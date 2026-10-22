<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where a Project's money from the customer stands, worked out from its
 * invoices and payments (docs/slice-17-customer-payments-plan.md, step 17a;
 * decision 162). The payment state is its own dimension of a Project,
 * apart from its production state (product-architecture.md, section 2).
 */
final class Payments
{
    public const string DEPOSIT_DUE = 'deposit_due';

    public const string DEPOSIT_PAID = 'deposit_paid';

    public const string BALANCE_DUE = 'balance_due';

    public const string PAID_IN_FULL = 'paid_in_full';

    /** The states, with how each is said, in English. */
    public const array STATES = [
        self::DEPOSIT_DUE => 'Deposit due',
        self::DEPOSIT_PAID => 'Deposit paid',
        self::BALANCE_DUE => 'Balance due',
        self::PAID_IN_FULL => 'Paid in full',
    ];

    /**
     * @param  Collection<int, ProjectInvoice>  $invoices  Not voided.
     * @param  Collection<int, ProjectPayment>  $payments  Not reversed.
     */
    private function __construct(
        public readonly Collection $invoices,
        public readonly Collection $payments,
    ) {}

    public static function for(Project $project): self
    {
        $invoices = $project->invoices()->whereNull('voided_at')->get();
        $payments = $project->payments()->whereNull('reversed_at')->get();

        return new self($invoices, $payments);
    }

    public function deposit(): ?ProjectInvoice
    {
        return $this->invoices->firstWhere('kind', ProjectInvoice::KIND_DEPOSIT);
    }

    public function final(): ?ProjectInvoice
    {
        return $this->invoices->firstWhere('kind', ProjectInvoice::KIND_FINAL);
    }

    /**
     * All that was invoiced, in cents.
     */
    public function invoicedCents(): int
    {
        return (int) $this->invoices->sum('amount_cents');
    }

    /**
     * All that was paid and counts, in cents.
     */
    public function paidCents(): int
    {
        return (int) $this->payments->filter(fn (ProjectPayment $payment): bool => $payment->counts())->sum('amount_cents');
    }

    /**
     * Cash and check received but not yet approved by Finance, in cents.
     */
    public function awaitingApprovalCents(): int
    {
        return (int) $this->payments->filter(fn (ProjectPayment $payment): bool => $payment->isAwaitingApproval())->sum('amount_cents');
    }

    /**
     * What is still owed on everything invoiced, in cents.
     */
    public function balanceCents(): int
    {
        return $this->invoicedCents() - $this->paidCents();
    }

    /**
     * What is paid against one invoice, in cents; with what awaits approval
     * too when asked.
     */
    public function paidOnCents(ProjectInvoice $invoice, bool $withAwaiting = false): int
    {
        return (int) $this->payments
            ->where('project_invoice_id', $invoice->id)
            ->filter(fn (ProjectPayment $payment): bool => $payment->counts() || ($withAwaiting && $payment->isAwaitingApproval()))
            ->sum('amount_cents');
    }

    /**
     * Deposit due until the deposit invoice is paid; then Deposit paid until
     * the final invoice; then Balance due until everything invoiced is paid.
     */
    public function state(): string
    {
        $final = $this->final();

        if ($final !== null) {
            return $this->balanceCents() <= 0 ? self::PAID_IN_FULL : self::BALANCE_DUE;
        }

        $deposit = $this->deposit();

        return $deposit !== null && $this->paidOnCents($deposit) >= $deposit->amount_cents ? self::DEPOSIT_PAID : self::DEPOSIT_DUE;
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state()];
    }

    public function balance(): string
    {
        return Money::format($this->balanceCents());
    }
}
