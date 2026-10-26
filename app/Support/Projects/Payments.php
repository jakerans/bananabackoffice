<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectBalanceAdjustment;
use App\Models\ProjectChangeOrder;
use App\Models\ProjectCollectionContact;
use App\Models\ProjectDepositChange;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Support\Money;
use Carbon\CarbonImmutable;
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

    /** The final balance is due this many days after the final invoice was sent (product-architecture.md, 10.6). */
    public const int DUE_DAYS = 7;

    /** From this many days after the final invoice it moves to Collections, which is Finance (decision 162, question 6). */
    public const int COLLECTIONS_DAYS = 30;

    /** The days after the final invoice it is chased: daily for three days, on the due day and day 14, then weekly. */
    public const array CHASE_DAYS = [1, 2, 3, 7, 14];

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
     * @param  Collection<int, ProjectCollectionContact>  $contacts  Each contact about the balance, the latest first.
     * @param  Collection<int, ProjectBalanceAdjustment>  $adjustments  Its credits, refunds and write-offs (slice 24), in order.
     */
    private function __construct(
        public readonly Project $project,
        public readonly Collection $invoices,
        public readonly Collection $payments,
        public readonly ?ProjectDepositChange $depositChange,
        public readonly Collection $contacts,
        public readonly Collection $adjustments,
    ) {}

    public static function for(Project $project): self
    {
        $project->loadMissing('location');

        $invoices = $project->invoices()->whereNull('voided_at')->get();
        $payments = $project->payments()->whereNull('reversed_at')->get();
        $depositChange = $project->depositChanges()->reorder()->orderByDesc('id')->first();
        $contacts = $project->collectionContacts()->reorder()->orderByDesc('contacted_on')->orderByDesc('id')->get();
        $adjustments = $project->balanceAdjustments()->get();

        return new self($project, $invoices, $payments, $depositChange, $contacts, $adjustments);
    }

    /**
     * Whether the final balance is still owed: a final invoice, and less paid
     * than everything invoiced.
     */
    public function hasOpenBalance(): bool
    {
        return $this->final() !== null && $this->balanceCents() > 0;
    }

    /**
     * The day the final balance is due: seven days after the final invoice
     * was sent.
     */
    public function balanceDueOn(): ?CarbonImmutable
    {
        $final = $this->final();

        return $final === null ? null : CarbonImmutable::instance($final->sent_on)->addDays(self::DUE_DAYS);
    }

    /**
     * How many days ago the final invoice was sent, where the job is.
     */
    public function daysSinceFinal(): ?int
    {
        $final = $this->final();

        return $final === null ? null : (int) CarbonImmutable::instance($final->sent_on)->diffInDays(RoughTimeframe::today($this->project->location));
    }

    /**
     * Whether the final balance is late: still owed on the day it was due or
     * after. A late fee applies, added in QuickBooks (decision 162, question 5).
     */
    public function isLate(): bool
    {
        return $this->hasOpenBalance() && $this->daysSinceFinal() >= self::DUE_DAYS;
    }

    /**
     * Whether the final balance is with Collections: still owed 30 days after
     * the final invoice. It leaves the Production Manager's list.
     */
    public function inCollections(): bool
    {
        return $this->hasOpenBalance() && $this->daysSinceFinal() >= self::COLLECTIONS_DAYS;
    }

    /**
     * Whether the customer is to be contacted about the balance today: on
     * days 1, 2, 3, 7 and 14 after the final invoice, then every seven days,
     * until Collections has it; and nobody has contacted them today yet.
     */
    public function isToChaseToday(): bool
    {
        $days = $this->daysSinceFinal();

        if (! $this->hasOpenBalance() || $days === null || $days >= self::COLLECTIONS_DAYS) {
            return false;
        }

        $chaseDay = in_array($days, self::CHASE_DAYS, true) || ($days > 14 && ($days - 14) % 7 === 0);
        $today = RoughTimeframe::today($this->project->location)->toDateString();

        return $chaseDay && ! $this->contacts->contains(fn (ProjectCollectionContact $contact): bool => $contact->contacted_on->toDateString() === $today);
    }

    /**
     * Who owns chasing the balance: the Production Manager, or Collections
     * from day 30.
     */
    public function chasedBy(): string
    {
        return $this->inCollections() ? 'Collections' : ($this->project->production_manager_name ?? 'Nobody yet');
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
     * What the customer agreed to pay before tax, in cents: the price sold
     * and every signed change order (slice 18, step 18a), less every approved
     * credit (slice 24, step 24a).
     */
    public function contractCents(): int
    {
        return $this->project->sold_cents + (int) $this->project->changeOrders()
            ->where('status', ProjectChangeOrder::STATUS_SIGNED)
            ->sum('price_change_cents') - $this->creditedCents();
    }

    /**
     * What approved credits took off, in cents (slice 24, step 24a).
     */
    public function creditedCents(): int
    {
        return $this->countedCents(ProjectBalanceAdjustment::CREDIT);
    }

    /**
     * What was paid back in refunds, in cents (step 24b).
     */
    public function refundedCents(): int
    {
        return $this->countedCents(ProjectBalanceAdjustment::REFUND);
    }

    /**
     * What was written off as bad debt, in cents (step 24c).
     */
    public function writtenOffCents(): int
    {
        return $this->countedCents(ProjectBalanceAdjustment::WRITE_OFF);
    }

    /**
     * What can still be refunded: what was paid, less every refund asked
     * for and not declined (question 4).
     */
    public function refundableCents(): int
    {
        $asked = (int) $this->adjustments
            ->where('kind', ProjectBalanceAdjustment::REFUND)
            ->where('status', '!=', ProjectBalanceAdjustment::STATUS_DECLINED)
            ->sum('amount_cents');

        return max(0, $this->paidCents() - $asked);
    }

    private function countedCents(string $kind): int
    {
        return (int) $this->adjustments
            ->where('kind', $kind)
            ->filter(fn (ProjectBalanceAdjustment $adjustment): bool => $adjustment->counts())
            ->sum('amount_cents');
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
     * What is still owed on everything invoiced, in cents: less what was
     * paid, credited and written off, and plus what was refunded (slice 24).
     */
    public function balanceCents(): int
    {
        return $this->invoicedCents() - $this->paidCents() - $this->creditedCents() - $this->writtenOffCents() + $this->refundedCents();
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
