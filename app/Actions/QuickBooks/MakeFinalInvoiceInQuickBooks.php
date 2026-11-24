<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\EstimateOption;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Models\ProjectInvoice;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\Pricing\EstimatePricing;
use App\Support\Projects\Payments;
use App\Support\Queue\CallingHours;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksNotFound;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Make a Project's final invoice in its Location's QuickBooks company and
 * have QuickBooks email it (docs/slice-38-quickbooks-tax-plan.md, step 38d;
 * decisions 229 and 232).
 *
 * It carries the work, the price sold and every signed change order, on the
 * company's own item for the work, with the tax left for QuickBooks to work
 * out from the job's address: all the tax is here, none was on the deposit.
 * The deposit invoiced is taken off as a line of the deposit item, with no
 * tax. Payable online by card or bank transfer. It is recorded on the
 * Project as its final invoice, at QuickBooks' own total and number.
 *
 * Only where the deposit invoice, if there is one, was made by Backoffice in
 * QuickBooks too: one recorded by hand is on whatever its maker chose, so
 * its final invoice is made by hand as well.
 */
class MakeFinalInvoiceInQuickBooks
{
    public function __construct(
        private QuickBooksClient $client,
        private SyncEstimateToQuickBooks $syncEstimateToQuickBooks,
        private FinalizeSaleInQuickBooks $finalizeSaleInQuickBooks,
        private EstimatePricing $pricing,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Whether the Project's final invoice can be made in QuickBooks from Backoffice.
     */
    public static function isAvailableFor(Project $project, Payments $payments): bool
    {
        $deposit = $payments->deposit();

        return $payments->final() === null
            && ($deposit === null || $deposit->quickbooks_invoice_id !== null)
            && FinalizeSaleInQuickBooks::isAvailableFor($project);
    }

    /**
     * @throws QuickBooksUnavailable
     * @throws AuthorizationException
     */
    public function handle(Project $project, User $actor): ProjectInvoice
    {
        Gate::forUser($actor)->authorize('recordPayments', $project);

        $project = Project::query()->with(['location', 'lead.contact', 'contact', 'proposal'])->findOrFail($project->id);
        $payments = Payments::for($project);

        if ($payments->final() !== null) {
            throw new QuickBooksUnavailable(__('This job has its final invoice already.'));
        }

        if (! self::isAvailableFor($project, $payments)) {
            throw new QuickBooksUnavailable(__('This job\'s final invoice is recorded by hand: its deposit invoice was not made by Backoffice in QuickBooks, or :location is not connected.', ['location' => $project->location->name]));
        }

        $connection = $this->finalizeSaleInQuickBooks->connectionFor($project);
        $lead = $project->lead;
        $address = $this->syncEstimateToQuickBooks->address($lead);
        $customerId = $this->syncEstimateToQuickBooks->customer($connection, $lead, $address);
        $workItem = $this->syncEstimateToQuickBooks->workItem($connection);
        $option = $project->proposal->estimate_option_id === null ? null : EstimateOption::query()->with('estimate')->find($project->proposal->estimate_option_id);

        $lines = [];

        foreach ($this->work($project, $option) as [$cents, $taxCode, $description]) {
            if ($cents !== 0) {
                $lines[] = $this->line($workItem, $cents, $taxCode, $description);
            }
        }

        $deposit = $payments->deposit();

        if ($deposit !== null && $deposit->amount_cents > 0) {
            $lines[] = $this->line($this->finalizeSaleInQuickBooks->depositItem($connection), -$deposit->amount_cents, 'NON', __('Deposit, invoice :number', ['number' => $deposit->number]));
        }

        $email = $project->contact->email ?? $lead->inquiry_email;
        $today = now()->setTimezone(CallingHours::timezoneFor($project->location));

        $kept = $this->client->post($connection, 'invoice', array_filter([
            'CustomerRef' => ['value' => $customerId],
            'BillAddr' => $address,
            'ShipAddr' => $address,
            'BillEmail' => filled($email) ? ['Address' => (string) $email] : null,
            'DueDate' => $today->addDays(Payments::DUE_DAYS)->toDateString(),
            'AllowOnlineCreditCardPayment' => true,
            'AllowOnlineACHPayment' => true,
            'LinkedTxn' => $this->estimateLink($connection, $option),
            'Line' => $lines,
        ]))['Invoice'] ?? null;

        if (! is_array($kept) || ! isset($kept['Id'])) {
            throw new QuickBooksUnavailable(__('QuickBooks did not keep the final invoice for :location.', ['location' => $connection->location->name]));
        }

        $invoice = DB::transaction(function () use ($project, $kept, $actor, $today): ProjectInvoice {
            $invoice = new ProjectInvoice;
            $invoice->forceFill([
                'project_id' => $project->id,
                'kind' => ProjectInvoice::KIND_FINAL,
                'number' => mb_substr((string) ($kept['DocNumber'] ?? $kept['Id']), 0, ProjectInvoice::NUMBER_LENGTH),
                'amount_cents' => (int) round(((float) ($kept['TotalAmt'] ?? 0)) * 100),
                'sent_on' => $today->toDateString(),
                'quickbooks_invoice_id' => (string) $kept['Id'],
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectInvoiceRecorded,
                $project,
                actor: $actor,
                after: [
                    'invoice' => ProjectInvoice::KINDS[ProjectInvoice::KIND_FINAL],
                    'number' => $invoice->number,
                    'amount_cents' => $invoice->amount_cents,
                    'tax_cents' => (int) round(((float) ($kept['TxnTaxDetail']['TotalTax'] ?? 0)) * 100),
                    'made_in' => 'QuickBooks',
                ],
                location: $project->location,
            );

            return $invoice;
        });

        if (filled($email)) {
            try {
                $this->client->email($connection, 'invoice/'.$invoice->quickbooks_invoice_id, (string) $email);
                $invoice->forceFill(['quickbooks_emailed_at' => now()])->save();
            } catch (QuickBooksUnavailable $unavailable) {
                // The invoice stands; it is emailed with "Send it again" from the Project's Payments.
                report($unavailable);
            }
        }

        return $invoice;
    }

    /**
     * Have QuickBooks email an invoice Backoffice made there that has not
     * been emailed yet.
     *
     * @throws QuickBooksUnavailable
     * @throws AuthorizationException
     */
    public function email(Project $project, ProjectInvoice $invoice, User $actor): bool
    {
        Gate::forUser($actor)->authorize('recordPayments', $project);

        $project->loadMissing(['location', 'lead', 'contact']);
        $email = $project->contact->email ?? $project->lead->inquiry_email;

        if ($invoice->project_id !== $project->id || $invoice->quickbooks_invoice_id === null || $invoice->isVoided() || blank($email)) {
            return false;
        }

        $this->client->email($this->finalizeSaleInQuickBooks->connectionFor($project), 'invoice/'.$invoice->quickbooks_invoice_id, (string) $email);
        $invoice->forceFill(['quickbooks_emailed_at' => now()])->save();

        return true;
    }

    /**
     * The work, as lines: the price sold, split as its estimate was into
     * what is taxed and what is not, then each signed change order.
     *
     * @return list<array{0: int, 1: string, 2: string}> Cents, QuickBooks' tax code, and what it says.
     */
    private function work(Project $project, ?EstimateOption $option): array
    {
        $sold = $project->sold_cents;
        $taxable = $sold;

        if ($option !== null) {
            $figures = $this->pricing->price($option->estimate, $option);
            $net = $figures->subtotalCents - $figures->discountCents;
            $taxable = $net > 0 ? (int) round($sold * $figures->taxableCents / $net) : $sold;
        }

        $lines = [
            [$taxable, 'TAX', $project->title],
            [$sold - $taxable, 'NON', __(':job (not taxed)', ['job' => $project->title])],
        ];

        $changeOrders = $project->changeOrders()->where('status', ProjectChangeOrder::STATUS_SIGNED)->orderBy('number')->get();

        foreach ($changeOrders as $changeOrder) {
            $lines[] = [$changeOrder->price_change_cents, 'TAX', __('Change order :number: :what', ['number' => $changeOrder->number, 'what' => mb_substr($changeOrder->description, 0, 200)])];
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    private function line(string $itemId, int $cents, string $taxCode, string $description): array
    {
        return [
            'DetailType' => 'SalesItemLineDetail',
            'Amount' => $cents / 100,
            'Description' => $description,
            'SalesItemLineDetail' => ['ItemRef' => ['value' => $itemId], 'Qty' => 1, 'UnitPrice' => $cents / 100, 'TaxCodeRef' => ['value' => $taxCode]],
        ];
    }

    /**
     * The accepted estimate the invoice comes from, so QuickBooks shows it
     * converted; nothing when QuickBooks no longer has it.
     *
     * @return list<array{TxnId: string, TxnType: string}>|null
     *
     * @throws QuickBooksUnavailable
     */
    private function estimateLink(QuickBooksConnection $connection, ?EstimateOption $option): ?array
    {
        if ($option?->quickbooks_estimate_id === null) {
            return null;
        }

        try {
            $kept = $this->client->get($connection, 'estimate/'.$option->quickbooks_estimate_id)['Estimate'] ?? null;
        } catch (QuickBooksNotFound) {
            return null;
        }

        return is_array($kept) ? [['TxnId' => (string) $kept['Id'], 'TxnType' => 'Estimate']] : null;
    }
}
