<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Estimates\ChangeEstimate;
use App\Enums\AuditAction;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\Pricing\Decimal;
use App\Support\Pricing\EstimatePricing;
use App\Support\Pricing\PricedEstimate;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksNotFound;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Send an estimate's options to its Location's QuickBooks company and bring
 * back the tax QuickBooks works out (docs/slice-38-quickbooks-tax-plan.md,
 * step 38b; decisions 229 and 230).
 *
 * The Lead's customer is matched by email in QuickBooks, or added. Each
 * priced option is a QuickBooks estimate at the job's address, made once and
 * then kept in step; QuickBooks works out its tax from that address. The
 * estimate then charges tax at QuickBooks' rate, so its prices follow every
 * later change at once, and the town list is no longer used for it.
 */
class SyncEstimateToQuickBooks
{
    public function __construct(
        private QuickBooksClient $client,
        private EstimatePricing $pricing,
        private ChangeEstimate $changeEstimate,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Whether the estimate's Location has a QuickBooks company to ask.
     */
    public static function isAvailableFor(Estimate $estimate): bool
    {
        return QuickBooksClient::isConfigured()
            && QuickBooksConnection::query()->where('location_id', $estimate->location_id)->whereNull('failed_at')->exists();
    }

    /**
     * @throws QuickBooksUnavailable
     * @throws AuthorizationException
     */
    public function handle(Estimate $estimate, User $actor): void
    {
        // Checked before anything is sent; the change itself is authorized again when it is saved.
        Gate::forUser($actor)->authorize('update', $estimate);

        $connection = QuickBooksConnection::query()->with('location')->where('location_id', $estimate->location_id)->first()
            ?? throw new QuickBooksUnavailable(__(':location is not connected to QuickBooks.', ['location' => $estimate->location->name]));

        $lead = $estimate->lead;
        $address = $this->address($lead);
        $estimate->unsetRelation('discounts');

        $priced = [];

        foreach ($estimate->options()->get() as $option) {
            $figures = $this->pricing->price($estimate, $option);

            if ($figures->subtotalCents - $figures->discountCents > 0) {
                $priced[] = [$option, $figures];
            }
        }

        if ($priced === []) {
            throw new QuickBooksUnavailable(__('Price something first: QuickBooks works out the tax on an option\'s price.'));
        }

        $customerId = $this->customer($connection, $lead, $address);
        $itemId = $this->workItem($connection);

        $sent = [];
        $percent = null;

        foreach ($priced as [$option, $figures]) {
            $kept = $this->send($connection, $option, $figures, $customerId, $itemId, $address);
            $sent[] = [$option, $kept];

            if ($figures->taxableCents > 0) {
                $percent ??= $this->percent($kept);
            }
        }

        $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($sent, $percent, $lead, $actor): void {
            foreach ($sent as [$option, $kept]) {
                $option->forceFill([
                    'quickbooks_estimate_id' => (string) $kept['Id'],
                    'quickbooks_sync_token' => (string) ($kept['SyncToken'] ?? '0'),
                    'quickbooks_tax_cents' => (int) round(((float) ($kept['TxnTaxDetail']['TotalTax'] ?? 0)) * 100),
                    'quickbooks_total_cents' => (int) round(((float) ($kept['TotalAmt'] ?? 0)) * 100),
                    'quickbooks_synced_at' => now(),
                ])->save();
            }

            $before = ['tax' => $estimate->tax_name, 'percent' => $estimate->tax_percent === null ? null : Decimal::plain($estimate->tax_percent)];

            $estimate->forceFill([
                'tax_name' => $percent === null ? null : Estimate::QUICKBOOKS_TAX_NAME,
                'tax_percent' => $percent,
                'quickbooks_tax_at' => now(),
            ])->save();

            $after = ['tax' => $estimate->tax_name, 'percent' => $percent === null ? null : Decimal::plain($percent)];

            if ($before != $after) {
                $this->recordAuditEvent->handle(
                    AuditAction::LeadEstimateTaxFromQuickbooks,
                    $lead,
                    actor: $actor,
                    before: $before,
                    after: $after,
                    location: $estimate->location,
                );
            }
        });
    }

    /**
     * Whether QuickBooks has not seen the estimate as it is now: its rate
     * was never fetched, or an option changed since it was last sent.
     */
    public static function isBehind(Estimate $estimate): bool
    {
        if (! $estimate->taxIsFromQuickBooks()) {
            return true;
        }

        return $estimate->options()->get()->contains(fn (EstimateOption $option): bool => $option->total_cents > 0
            && ($option->quickbooks_synced_at === null || $option->quickbooks_synced_at->lessThan($estimate->updated_at)));
    }

    /**
     * Send the estimate to QuickBooks if its Location has one and it is
     * behind. Returns why it could not be, in words, or null when it is up
     * to date or the Location does not use QuickBooks.
     */
    public function bringUpToDate(Estimate $estimate, User $actor): ?string
    {
        if (! self::isAvailableFor($estimate) || $estimate->isSettled() || ! self::isBehind($estimate)) {
            return null;
        }

        try {
            $this->handle($estimate, $actor);
        } catch (QuickBooksUnavailable $unavailable) {
            return $unavailable->getMessage();
        }

        return null;
    }

    /**
     * Remove an option's QuickBooks estimate, when the option itself is
     * removed. QuickBooks not answering never stops the option going.
     */
    public function forget(EstimateOption $option): void
    {
        if ($option->quickbooks_estimate_id === null) {
            return;
        }

        try {
            $connection = QuickBooksConnection::query()->with('location')->where('location_id', $option->estimate->location_id)->first();

            if ($connection === null) {
                return;
            }

            $kept = $this->client->get($connection, 'estimate/'.$option->quickbooks_estimate_id)['Estimate'] ?? null;

            if (is_array($kept)) {
                $this->client->post($connection, 'estimate', ['Id' => $kept['Id'], 'SyncToken' => $kept['SyncToken']], ['operation' => 'delete']);
            }
        } catch (QuickBooksUnavailable $unavailable) {
            report($unavailable);
        }
    }

    /**
     * The job's address, as QuickBooks takes it. Without one QuickBooks
     * cannot tell which town's tax applies.
     *
     * @return array{Line1: string, City: string, CountrySubDivisionCode: string, PostalCode: string, Country: string}
     *
     * @throws QuickBooksUnavailable
     */
    public function address(Lead $lead): array
    {
        if (blank($lead->address_line1) || blank($lead->address_city) || blank($lead->address_state) || blank($lead->address_postal_code)) {
            throw new QuickBooksUnavailable(__('Give the lead\'s full address first: QuickBooks works out the tax from where the job is.'));
        }

        return [
            'Line1' => trim($lead->address_line1.' '.$lead->address_line2),
            'City' => (string) $lead->address_city,
            'CountrySubDivisionCode' => (string) $lead->address_state,
            'PostalCode' => (string) $lead->address_postal_code,
            'Country' => 'USA',
        ];
    }

    /**
     * The Lead's customer in QuickBooks: the one already matched, one with
     * the same email, or a new one. One that exists is never changed.
     *
     * @param  array<string, string>  $address
     *
     * @throws QuickBooksUnavailable
     */
    private function customer(QuickBooksConnection $connection, Lead $lead, array $address): string
    {
        if ($lead->quickbooks_customer_id !== null) {
            return $lead->quickbooks_customer_id;
        }

        $email = $lead->contact->email ?? $lead->inquiry_email;
        $id = null;

        if (filled($email)) {
            $id = $this->client->query($connection, 'Customer', "select * from Customer where PrimaryEmailAddr = '".str_replace("'", "\\'", (string) $email)."' maxresults 1")[0]['Id'] ?? null;
        }

        if ($id === null) {
            $name = $lead->displayName() ?? __('Backoffice lead :id', ['id' => $lead->id]);
            $details = array_filter([
                'PrimaryEmailAddr' => filled($email) ? ['Address' => (string) $email] : null,
                'PrimaryPhone' => filled($lead->inquiry_phone) ? ['FreeFormNumber' => (string) $lead->displayPhone()] : null,
                'BillAddr' => $address,
                'ShipAddr' => $address,
            ]);

            try {
                $id = $this->client->post($connection, 'customer', ['DisplayName' => $name, ...$details])['Customer']['Id'] ?? null;
            } catch (QuickBooksUnavailable) {
                // QuickBooks keeps one customer per name: a second person of the same name is told apart.
                $id = $this->client->post($connection, 'customer', ['DisplayName' => $name.' ('.__('lead :id', ['id' => $lead->id]).')', ...$details])['Customer']['Id'] ?? null;
            }
        }

        if ($id === null) {
            throw new QuickBooksUnavailable(__('QuickBooks did not keep the customer for :location.', ['location' => $connection->location->name]));
        }

        $lead->forceFill(['quickbooks_customer_id' => (string) $id])->saveQuietly();

        return (string) $id;
    }

    /**
     * The company's own item the work is priced on: the one chosen before,
     * or its "Painting services", or else its first taxable service.
     *
     * @throws QuickBooksUnavailable
     */
    private function workItem(QuickBooksConnection $connection): string
    {
        if ($connection->work_item_id !== null) {
            return $connection->work_item_id;
        }

        $items = collect($this->client->query($connection, 'Item', "select * from Item where Type = 'Service' and Active = true maxresults 100"))
            ->filter(fn (array $item): bool => ($item['Taxable'] ?? false) === true);

        $item = $items->first(fn (array $item): bool => mb_strtolower((string) ($item['Name'] ?? '')) === 'painting services') ?? $items->first();

        if ($item === null) {
            throw new QuickBooksUnavailable(__(':location\'s QuickBooks has no taxable service item to price the work on. Add one in QuickBooks, such as "Painting services".', ['location' => $connection->location->name]));
        }

        $connection->forceFill(['work_item_id' => (string) $item['Id'], 'work_item_name' => (string) ($item['Name'] ?? '')])->save();

        return (string) $item['Id'];
    }

    /**
     * Make the option's QuickBooks estimate, or bring the one it has in
     * step, and return it as QuickBooks keeps it, tax worked out.
     *
     * @param  array<string, string>  $address
     * @return array<string, mixed>
     *
     * @throws QuickBooksUnavailable
     */
    private function send(QuickBooksConnection $connection, EstimateOption $option, PricedEstimate $figures, string $customerId, string $itemId, array $address): array
    {
        $netCents = $figures->subtotalCents - $figures->discountCents;
        $lines = [];

        foreach ([[$figures->taxableCents, 'TAX', $option->name], [$netCents - $figures->taxableCents, 'NON', __(':option (not taxed)', ['option' => $option->name])]] as [$cents, $taxCode, $description]) {
            if ($cents > 0) {
                $lines[] = [
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => $cents / 100,
                    'Description' => $description,
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => $itemId], 'Qty' => 1, 'UnitPrice' => $cents / 100, 'TaxCodeRef' => ['value' => $taxCode]],
                ];
            }
        }

        $body = ['CustomerRef' => ['value' => $customerId], 'BillAddr' => $address, 'ShipAddr' => $address, 'Line' => $lines];

        if ($option->quickbooks_estimate_id !== null) {
            try {
                $kept = $this->client->get($connection, 'estimate/'.$option->quickbooks_estimate_id)['Estimate'] ?? null;
            } catch (QuickBooksNotFound) {
                // Deleted in QuickBooks since: made again below. QuickBooks not answering is not this, and stops here.
                $kept = null;
            }

            if (is_array($kept)) {
                $body = ['sparse' => true, 'Id' => $kept['Id'], 'SyncToken' => $kept['SyncToken'], ...$body];
            }
        }

        $estimate = $this->client->post($connection, 'estimate', $body)['Estimate'] ?? null;

        if (! is_array($estimate) || ! isset($estimate['Id'])) {
            throw new QuickBooksUnavailable(__('QuickBooks did not keep the estimate for :location.', ['location' => $connection->location->name]));
        }

        return $estimate;
    }

    /**
     * The rate QuickBooks charged, every part of it added up: state, county
     * and city.
     *
     * @param  array<string, mixed>  $estimate
     */
    private function percent(array $estimate): ?string
    {
        $lines = $estimate['TxnTaxDetail']['TaxLine'] ?? [];
        $percent = 0.0;

        foreach (is_array($lines) ? $lines : [] as $line) {
            $percent += (float) ($line['TaxLineDetail']['TaxPercent'] ?? 0);
        }

        return $percent > 0 ? number_format($percent, 4, '.', '') : null;
    }
}
