<?php

namespace App\Actions\QuickBooks;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Projects\RecordPayments;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\QuickBooksConnection;
use App\Models\User;
use App\Support\Projects\Payments;
use App\Support\QuickBooks\PaymentMethods;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksNotFound;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Bring a Project's payments in step with QuickBooks, both ways
 * (docs/slice-38-quickbooks-tax-plan.md, step 38d; decisions 229 and 230).
 *
 * A payment the customer made in QuickBooks, online by card or bank
 * transfer, against an invoice Backoffice made there is read back as a
 * payment of the Project; it is in the books already, so it counts at once.
 * One removed in QuickBooks since is reversed here. And what Backoffice
 * holds that QuickBooks does not have yet is sent: a payment taken another
 * way, a reversal.
 *
 * Every fifteen minutes by itself for the jobs with an invoice still owed,
 * and when someone who records a Project's payments asks.
 */
class ReadPaymentsFromQuickBooks
{
    /** Who a payment read from QuickBooks is recorded and reversed by. */
    public const string BY = 'QuickBooks';

    public function __construct(
        private QuickBooksClient $client,
        private SendPaymentToQuickBooks $sendPaymentToQuickBooks,
        private RecordPayments $recordPayments,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Asked for by someone who records this Project's payments.
     *
     * @return array{read: int, removed: int, sent: int}
     *
     * @throws QuickBooksUnavailable
     * @throws AuthorizationException
     */
    public function handle(Project $project, User $actor): array
    {
        Gate::forUser($actor)->authorize('recordPayments', $project);

        return $this->forProject($project, $actor);
    }

    /**
     * Every job with something to bring in step: an invoice in QuickBooks
     * still owed, or a payment or reversal QuickBooks has not been told of.
     *
     * @return array{projects: int, read: int, removed: int, sent: int, failed: list<string>}
     */
    public function open(): array
    {
        $done = ['projects' => 0, 'read' => 0, 'removed' => 0, 'sent' => 0, 'failed' => []];

        if (! QuickBooksClient::isConfigured()) {
            return $done;
        }

        foreach ($this->openProjects() as $project) {
            try {
                $counts = $this->forProject($project, null);
            } catch (Throwable $failure) {
                report($failure);
                $done['failed'][] = $project->title;

                continue;
            }

            $done['projects']++;
            $done['read'] += $counts['read'];
            $done['removed'] += $counts['removed'];
            $done['sent'] += $counts['sent'];
        }

        return $done;
    }

    /**
     * @return array{read: int, removed: int, sent: int}
     *
     * @throws QuickBooksUnavailable
     */
    public function forProject(Project $project, ?User $actor): array
    {
        $project->loadMissing('location');

        $connection = $this->sendPaymentToQuickBooks->connectionFor($project)
            ?? throw new QuickBooksUnavailable(__(':location is not connected to QuickBooks.', ['location' => $project->location->name]));

        $done = ['read' => 0, 'removed' => 0, 'sent' => 0];
        $methods = null;

        $invoices = ProjectInvoice::query()->where('project_id', $project->id)->whereNull('voided_at')->whereNotNull('quickbooks_invoice_id')->get();

        foreach ($invoices as $invoice) {
            try {
                $kept = $this->client->get($connection, 'invoice/'.$invoice->quickbooks_invoice_id)['Invoice'] ?? null;
            } catch (QuickBooksNotFound) {
                continue;
            }

            if (! is_array($kept) || (string) ($kept['Id'] ?? '') !== $invoice->quickbooks_invoice_id) {
                continue;
            }

            $linked = collect(is_array($kept['LinkedTxn'] ?? null) ? $kept['LinkedTxn'] : [])
                ->filter(fn (mixed $link): bool => is_array($link) && ($link['TxnType'] ?? null) === 'Payment' && isset($link['TxnId']))
                ->map(fn (array $link): string => (string) $link['TxnId'])
                ->unique()
                ->values();

            $known = ProjectPayment::query()->where('project_invoice_id', $invoice->id)->whereNotNull('quickbooks_payment_id')->pluck('quickbooks_payment_id');

            foreach ($linked->diff($known) as $paymentId) {
                $methods ??= $this->client->query($connection, 'PaymentMethod', 'select * from PaymentMethod maxresults 100');

                if ($this->take($connection, $project, $invoice, $paymentId, $methods, $actor)) {
                    $done['read']++;
                }
            }

            $gone = ProjectPayment::query()
                ->where('project_invoice_id', $invoice->id)
                ->where('from_quickbooks', true)
                ->whereNull('reversed_at')
                ->whereNotIn('quickbooks_payment_id', $linked->all())
                ->get();

            foreach ($gone as $payment) {
                $this->reverse($project, $payment, $actor);
                $done['removed']++;
            }
        }

        foreach ($this->unsent($project->id) as $payment) {
            if ($this->sendPaymentToQuickBooks->send($payment)) {
                $done['sent']++;
            }
        }

        foreach ($this->unremoved($project->id) as $payment) {
            $this->sendPaymentToQuickBooks->remove($payment);
        }

        if ($done['read'] > 0 || $done['removed'] > 0) {
            $this->recordPayments->settle($project, $actor);
        }

        return $done;
    }

    /**
     * One payment QuickBooks has against the invoice and Backoffice does
     * not. Returns whether it was read in as new: one Backoffice sent itself,
     * known by its note, is only matched up again.
     *
     * @param  list<array<string, mixed>>  $methods
     *
     * @throws QuickBooksUnavailable
     */
    private function take(QuickBooksConnection $connection, Project $project, ProjectInvoice $invoice, string $paymentId, array $methods, ?User $actor): bool
    {
        try {
            $kept = $this->client->get($connection, 'payment/'.$paymentId)['Payment'] ?? null;
        } catch (QuickBooksNotFound) {
            return false;
        }

        if (! is_array($kept)) {
            return false;
        }

        $cents = 0;

        foreach (is_array($kept['Line'] ?? null) ? $kept['Line'] : [] as $line) {
            foreach (is_array($line['LinkedTxn'] ?? null) ? $line['LinkedTxn'] : [] as $link) {
                if (($link['TxnType'] ?? null) === 'Invoice' && (string) ($link['TxnId'] ?? '') === $invoice->quickbooks_invoice_id) {
                    $cents += (int) round(((float) ($line['Amount'] ?? 0)) * 100);
                }
            }
        }

        if ($cents <= 0) {
            return false;
        }

        $own = SendPaymentToQuickBooks::paymentIdIn($kept['PrivateNote'] ?? null);
        $sent = $own === null ? null : ProjectPayment::query()
            ->where('project_invoice_id', $invoice->id)
            ->where('from_quickbooks', false)
            ->whereNull('quickbooks_payment_id')
            ->find($own);

        if ($sent !== null) {
            $sent->forceFill(['quickbooks_payment_id' => $paymentId])->save();

            return false;
        }

        $methodName = collect($methods)->firstWhere('Id', (string) ($kept['PaymentMethodRef']['value'] ?? ''))['Name'] ?? ($kept['PaymentMethodRef']['name'] ?? null);
        $method = PaymentMethods::methodFor(is_string($methodName) ? $methodName : null);
        $received = isset($kept['TxnDate']) ? CarbonImmutable::parse((string) $kept['TxnDate']) : CarbonImmutable::today();
        $reference = trim((string) ($kept['PaymentRefNum'] ?? ''));

        DB::transaction(function () use ($project, $invoice, $paymentId, $cents, $method, $received, $reference, $actor): void {
            $payment = new ProjectPayment;
            $payment->forceFill([
                'project_id' => $project->id,
                'project_invoice_id' => $invoice->id,
                'amount_cents' => $cents,
                'method' => $method,
                'received_on' => $received,
                'reference' => mb_substr($reference === '' ? __('QuickBooks payment :id', ['id' => $paymentId]) : $reference, 0, ProjectPayment::REFERENCE_LENGTH),
                'needs_approval' => false,
                'quickbooks_payment_id' => $paymentId,
                'from_quickbooks' => true,
                'recorded_by_name' => self::BY,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectPaymentRecorded,
                $project,
                actor: $actor,
                after: [
                    'invoice' => $invoice->label(),
                    'amount_cents' => $cents,
                    'method' => $payment->methodLabel(),
                    'received_on' => $received->toDateString(),
                    'awaiting_approval' => false,
                    'read_from' => self::BY,
                ],
                location: $project->location,
            );
        });

        return true;
    }

    /**
     * A payment read from QuickBooks that QuickBooks no longer has against
     * the invoice: deleted or moved there. It no longer counts here either.
     */
    private function reverse(Project $project, ProjectPayment $payment, ?User $actor): void
    {
        $reason = __('Removed in QuickBooks.');

        DB::transaction(function () use ($project, $payment, $actor, $reason): void {
            $payment->forceFill(['reversed_at' => now(), 'reversed_by_name' => self::BY, 'reversed_reason' => $reason])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectPaymentReversed,
                $project,
                actor: $actor,
                before: ['invoice' => $payment->invoice->label(), 'amount_cents' => $payment->amount_cents, 'method' => $payment->methodLabel()],
                location: $project->location,
                reason: $reason,
            );
        });
    }

    /**
     * Payments that count and QuickBooks has not been sent, on invoices it has.
     *
     * @return Collection<int, ProjectPayment>
     */
    private function unsent(?int $projectId = null): Collection
    {
        return ProjectPayment::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->where('from_quickbooks', false)
            ->whereNull('quickbooks_payment_id')
            ->whereNull('reversed_at')
            ->whereHas('invoice', fn ($query) => $query->whereNull('voided_at')->whereNotNull('quickbooks_invoice_id'))
            ->get()
            ->filter(fn (ProjectPayment $payment): bool => $payment->counts())
            ->values();
    }

    /**
     * Reversed payments Backoffice sent that QuickBooks still has.
     *
     * @return Collection<int, ProjectPayment>
     */
    private function unremoved(?int $projectId = null): Collection
    {
        return ProjectPayment::query()
            ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
            ->where('from_quickbooks', false)
            ->whereNotNull('quickbooks_payment_id')
            ->whereNotNull('reversed_at')
            ->get();
    }

    /**
     * @return Collection<int, Project>
     */
    private function openProjects(): Collection
    {
        $owed = ProjectInvoice::query()
            ->whereNull('voided_at')
            ->whereNotNull('quickbooks_invoice_id')
            ->with('project')
            ->get()
            ->filter(fn (ProjectInvoice $invoice): bool => Payments::for($invoice->project)->paidOnCents($invoice) < $invoice->amount_cents)
            ->pluck('project_id');

        $ids = $owed->merge($this->unsent()->pluck('project_id'))->merge($this->unremoved()->pluck('project_id'))->unique();

        return Project::query()->with('location')->whereIn('id', $ids->all())->orderBy('id')->get();
    }
}
