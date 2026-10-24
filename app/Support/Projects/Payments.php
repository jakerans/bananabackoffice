<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectDepositChange;
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

    /** A manager waived the deposit (step 17b). */
    public const string NO_DEPOSIT = 'no_deposit';

    /** The states, with how each is said, in English. */
    public const array STATES = [
        self::DEPOSIT_DUE => 'Deposit due',
        self::NO_DEPOSIT => 'No deposit',
        self::DEPOSIT_PAID => 'Deposit paid',
        self::BALANCE_DUE => 'Balance due',
        self::PAID_IN_FULL => 'Paid in full',
    ];

    /**
     * @param  Collection<int, ProjectInvoice>  $invoices  Not voided.
     * @param  Collection<int, ProjectPayment>  $payments  Not reversed.
     * @param  ProjectDepositChange|null  $depositChange  The latest smaller deposit, or none, set by a manager.
     */
    private function __construct(
        public readonly Project $project,
        public readonly Collection $invoices,
        public readonly Collection $payments,
        public readonly ?ProjectDepositChange $depositChange,
    ) {}

    public static function for(Project $project): self
    {
        $project->loadMissing('location');

        $invoices = $project->invoices()->whereNull('voided_at')->get();
        $payments = $project->payments()->whereNull('reversed_at')->get();
        $depositChange = $project->depositChanges()->reorder()->orderByDesc('id')->first();

        return new self($project, $invoices, $payments, $depositChange);
    }

    /**
     * The deposit the Location's policy asks for, before tax, in cents
     * (product-architecture.md, 10.4): its percent of the sold price.
     */
    public function policyDepositCents(): int
    {
        return intdiv($this->project->sold_cents * $this->project->location->deposit_percent, 100);
    }

    /**
     * The deposit asked for on this job, before tax, in cents: the policy's,
     * unless a manager set a smaller one or none.
     */
    public function requiredDepositCents(): int
    {
        return $this->depositChange === null ? $this->policyDepositCents() : $this->depositChange->required_cents;
    }

    /**
     * Whether the deposit invoice is paid in full.
     */
    public function depositIsPaid(): bool
    {
        $deposit = $this->deposit();

        return $deposit !== null && $this->paidOnCents($deposit) >= $deposit->amount_cents;
    }

    /**
     * Whether the full deposit the policy asks for was actually collected,
     * kept for the sales commission advance (product-architecture.md, 5.7):
     * a smaller or waived deposit is not a full one.
     */
    public function fullDepositCollected(): bool
    {
        $deposit = $this->deposit();
        $policy = $this->policyDepositCents();

        return $deposit !== null && $policy > 0 && $this->paidOnCents($deposit) >= $policy;
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
     * Deposit due until the deposit invoice is paid, or No deposit when a
     * manager waived it; then Deposit paid until the final invoice; then
     * Balance due until everything invoiced is paid.
     */
    public function state(): string
    {
        if ($this->final() !== null) {
            return $this->balanceCents() <= 0 ? self::PAID_IN_FULL : self::BALANCE_DUE;
        }

        if ($this->depositIsPaid()) {
            return self::DEPOSIT_PAID;
        }

        return $this->deposit() === null && $this->requiredDepositCents() === 0 ? self::NO_DEPOSIT : self::DEPOSIT_DUE;
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
