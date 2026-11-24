<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\EstimateOption;
use App\Models\Project;
use App\Models\ProjectInvoice;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\Projects\Payments;
use App\Support\Queue\CallingHours;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksNotFound;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Once an option is sold, settle it in the Location's QuickBooks company
 * (docs/slice-38-quickbooks-tax-plan.md, step 38c; decisions 229 and 232):
 * the options not chosen lose their QuickBooks estimates, the chosen one is
 * marked accepted, and the deposit is invoiced.
 *
 * The deposit invoice is for the deposit this job asks for, on the company's
 * non-taxable deposit item, so it carries no tax; payable online by card or
 * bank transfer; and emailed to the customer by QuickBooks. It is recorded
 * on the Project as its deposit invoice, with QuickBooks' own number.
 * Nobody signs in to QuickBooks. Safe to run again: nothing is made twice.
 */
class FinalizeSaleInQuickBooks
{
    public function __construct(
        private QuickBooksClient $client,
        private SyncEstimateToQuickBooks $syncEstimateToQuickBooks,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Whether the Project's Location has a QuickBooks company to settle it in.
     */
    public static function isAvailableFor(Project $project): bool
    {
        return QuickBooksClient::isConfigured()
            && QuickBooksConnection::query()->where('location_id', $project->location_id)->whereNull('failed_at')->exists();
    }

    /**
     * Straight after a sale: QuickBooks not answering never undoes the sale.
     * What could not be done is done from the Project's Payments, with
     * "Send the deposit invoice".
     */
    public function afterSale(Project $project, User $actor): void
    {
        if (! self::isAvailableFor($project)) {
            return;
        }

        try {
            $this->settle($project, $actor);
        } catch (QuickBooksUnavailable $unavailable) {
            report($unavailable);
        }
    }

    /**
     * Asked for by someone who records this Project's payments.
     *
     * @throws QuickBooksUnavailable
     * @throws AuthorizationException
     */
    public function handle(Project $project, User $actor): ?ProjectInvoice
    {
        Gate::forUser($actor)->authorize('recordPayments', $project);

        return $this->settle($project, $actor);
    }

    /**
     * @throws QuickBooksUnavailable
     */
    private function settle(Project $project, User $actor): ?ProjectInvoice
    {
        $connection = $this->connectionFor($project);
        $proposal = $project->proposal;

        if ($proposal->estimate_id !== null) {
            foreach (EstimateOption::query()->with('estimate')->where('estimate_id', $proposal->estimate_id)->whereNotNull('quickbooks_estimate_id')->get() as $option) {
                $option->id === $proposal->estimate_option_id
                    ? $this->accept($connection, $option)
                    : $this->remove($option);
            }
        }

        return $this->depositInvoice($connection, $project, $actor);
    }

    /**
     * The Project's Location's QuickBooks company.
     *
     * @throws QuickBooksUnavailable
     */
    public function connectionFor(Project $project): QuickBooksConnection
    {
        return QuickBooksConnection::query()->with('location')->where('location_id', $project->location_id)->first()
            ?? throw new QuickBooksUnavailable(__(':location is not connected to QuickBooks.', ['location' => $project->location->name]));
    }

    /**
     * @throws QuickBooksUnavailable
     */
    private function accept(QuickBooksConnection $connection, EstimateOption $option): void
    {
        try {
            $kept = $this->client->get($connection, 'estimate/'.$option->quickbooks_estimate_id)['Estimate'] ?? null;
        } catch (QuickBooksNotFound) {
            return;
        }

        if (is_array($kept) && ($kept['TxnStatus'] ?? null) === 'Pending') {
            $this->client->post($connection, 'estimate', ['sparse' => true, 'Id' => $kept['Id'], 'SyncToken' => $kept['SyncToken'], 'TxnStatus' => 'Accepted']);
        }
    }

    private function remove(EstimateOption $option): void
    {
        $this->syncEstimateToQuickBooks->forget($option);

        $option->forceFill(['quickbooks_estimate_id' => null, 'quickbooks_sync_token' => null])->save();
    }

    /**
     * The Project's deposit invoice, made in QuickBooks if it has none yet
     * and a deposit is asked for, then emailed if it has not been.
     *
     * @throws QuickBooksUnavailable
     */
    private function depositInvoice(QuickBooksConnection $connection, Project $project, User $actor): ?ProjectInvoice
    {
        $payments = Payments::for($project);
        $invoice = $payments->deposit();
        $lead = $project->lead;

        if ($invoice === null) {
            $cents = $payments->requiredDepositCents();

            if ($cents <= 0) {
                return null;
            }

            $address = $this->syncEstimateToQuickBooks->address($lead);
            // A sale that never went through an estimate has no customer in QuickBooks yet: matched or added now.
            $customerId = $this->syncEstimateToQuickBooks->customer($connection, $lead, $address);
            $email = $project->contact->email ?? $lead->inquiry_email;

            $kept = $this->client->post($connection, 'invoice', array_filter([
                'CustomerRef' => ['value' => $customerId],
                'BillAddr' => $address,
                'ShipAddr' => $address,
                'BillEmail' => filled($email) ? ['Address' => (string) $email] : null,
                'AllowOnlineCreditCardPayment' => true,
                'AllowOnlineACHPayment' => true,
                'Line' => [[
                    'DetailType' => 'SalesItemLineDetail',
                    'Amount' => $cents / 100,
                    'Description' => __('Deposit for :job', ['job' => $project->title]),
                    'SalesItemLineDetail' => ['ItemRef' => ['value' => $this->depositItem($connection)], 'Qty' => 1, 'UnitPrice' => $cents / 100, 'TaxCodeRef' => ['value' => 'NON']],
                ]],
            ]))['Invoice'] ?? null;

            if (! is_array($kept) || ! isset($kept['Id'])) {
                throw new QuickBooksUnavailable(__('QuickBooks did not keep the deposit invoice for :location.', ['location' => $connection->location->name]));
            }

            $invoice = DB::transaction(function () use ($project, $kept, $actor): ProjectInvoice {
                $invoice = new ProjectInvoice;
                $invoice->forceFill([
                    'project_id' => $project->id,
                    'kind' => ProjectInvoice::KIND_DEPOSIT,
                    'number' => mb_substr((string) ($kept['DocNumber'] ?? $kept['Id']), 0, ProjectInvoice::NUMBER_LENGTH),
                    'amount_cents' => (int) round(((float) ($kept['TotalAmt'] ?? 0)) * 100),
                    'sent_on' => now()->setTimezone(CallingHours::timezoneFor($project->location))->toDateString(),
                    'quickbooks_invoice_id' => (string) $kept['Id'],
                    'recorded_by' => $actor->id,
                    'recorded_by_name' => $actor->name,
                ])->save();

                $this->recordAuditEvent->handle(
                    AuditAction::ProjectInvoiceRecorded,
                    $project,
                    actor: $actor,
                    after: ['invoice' => ProjectInvoice::KINDS[ProjectInvoice::KIND_DEPOSIT], 'number' => $invoice->number, 'amount_cents' => $invoice->amount_cents, 'made_in' => 'QuickBooks'],
                    location: $project->location,
                );

                return $invoice;
            });
        }

        if ($invoice->quickbooks_invoice_id !== null && $invoice->quickbooks_emailed_at === null) {
            $email = $project->contact->email ?? $lead->inquiry_email;

            if (filled($email)) {
                $this->client->email($connection, 'invoice/'.$invoice->quickbooks_invoice_id, (string) $email);
                $invoice->forceFill(['quickbooks_emailed_at' => now()])->save();
            }
        }

        return $invoice;
    }

    /**
     * The company's own non-taxable item deposits are invoiced on: the one
     * chosen before, or its item named for deposits, or else one Backoffice
     * adds, "Customer deposit", posting to a "Customer Deposits" liability
     * account so a deposit is held, not counted as income.
     *
     * @throws QuickBooksUnavailable
     */
    public function depositItem(QuickBooksConnection $connection): string
    {
        if ($connection->deposit_item_id !== null) {
            return $connection->deposit_item_id;
        }

        $item = collect($this->client->query($connection, 'Item', "select * from Item where Type = 'Service' and Active = true maxresults 100"))
            ->first(fn (array $item): bool => ($item['Taxable'] ?? false) !== true && str_contains(mb_strtolower((string) ($item['Name'] ?? '')), 'deposit'));

        if ($item === null) {
            $account = $this->client->query($connection, 'Account', "select * from Account where Name = 'Customer Deposits' maxresults 1")[0]
                ?? $this->client->post($connection, 'account', ['Name' => 'Customer Deposits', 'AccountType' => 'Other Current Liability'])['Account']
                ?? null;

            $item = is_array($account)
                ? $this->client->post($connection, 'item', ['Name' => 'Customer deposit', 'Type' => 'Service', 'Taxable' => false, 'IncomeAccountRef' => ['value' => $account['Id']]])['Item'] ?? null
                : null;
        }

        if (! is_array($item) || ! isset($item['Id'])) {
            throw new QuickBooksUnavailable(__(':location\'s QuickBooks has no item to invoice a deposit on, and one could not be added.', ['location' => $connection->location->name]));
        }

        $connection->forceFill(['deposit_item_id' => (string) $item['Id'], 'deposit_item_name' => (string) ($item['Name'] ?? '')])->save();

        return (string) $item['Id'];
    }
}
