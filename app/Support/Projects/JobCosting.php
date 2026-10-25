<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\ProjectCost;
use App\Models\ProjectJobCostingStep;
use App\Models\WarrantyCost;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;

/**
 * A job's budget and gross margin (docs/slice-19-job-costing-plan.md;
 * decision 170; product-architecture.md, 6.9 and 10.3), worked out from
 * the contract, the Work Packages' payouts and the costs recorded.
 *
 * Committed costs are every payout set and every cost recorded, so the
 * projected margin is truthful before anything is paid (10.3). Before
 * allocations, which Finance adds at the monthly close (question 5).
 * Finance-sensitive: shown only to those who may see payouts.
 */
final readonly class JobCosting
{
    public const string OPEN = 'open';

    public const string SUBMITTED = 'submitted';

    public const string APPROVED = 'approved';

    public const string CLOSED = 'closed';

    public const string REOPENED = 'reopened';

    /** Where a job's costing stands, with how each is said, in English. */
    public const array STATES = [
        self::OPEN => 'Open',
        self::SUBMITTED => 'Submitted',
        self::APPROVED => 'Approved by Finance',
        self::CLOSED => 'Financially closed',
        self::REOPENED => 'Reopened',
    ];

    /**
     * @param  Collection<int, ProjectCost>  $costs  Not voided, with their receipts.
     * @param  Collection<int, ProjectJobCostingStep>  $steps  Every step, the earliest first.
     * @param  Collection<int, ProjectAccuracyStrike>  $strikes  This job's accuracy strikes.
     */
    private function __construct(
        public Project $project,
        public AfterPayouts $afterPayouts,
        public Collection $costs,
        public Collection $steps,
        public Collection $strikes,
    ) {}

    public static function for(Project $project): self
    {
        $project->loadMissing('location');

        return new self(
            $project,
            AfterPayouts::for($project),
            $project->costs()->whereNull('voided_at')->with('receipts')->get(),
            ProjectJobCostingStep::query()->where('project_id', $project->id)->orderBy('id')->get(),
            ProjectAccuracyStrike::query()->where('project_id', $project->id)->orderBy('id')->get(),
        );
    }

    /**
     * Where the job's costing stands: open until the Production Manager
     * submits it (step 19b), then as its latest step says (step 19c).
     */
    public function state(): string
    {
        return $this->steps->last()->kind ?? self::OPEN;
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state()] ?? $this->state();
    }

    /**
     * Whether costs added or voided now are corrections: after submission,
     * until Finance approves.
     */
    public function takesCorrections(): bool
    {
        return in_array($this->state(), [self::SUBMITTED, self::REOPENED], true);
    }

    /**
     * Whether the Production Manager can no longer change a cost: approved
     * or closed, until Finance reopens it.
     */
    public function isLocked(): bool
    {
        return in_array($this->state(), [self::APPROVED, self::CLOSED], true);
    }

    /**
     * The Production Manager's latest submission: the provisional gross margin.
     */
    public function provisional(): ?ProjectJobCostingStep
    {
        return $this->steps->where('kind', ProjectJobCostingStep::KIND_SUBMITTED)->last();
    }

    /**
     * Finance's approval in force: the final gross margin. None after a
     * reopening until Finance approves again.
     */
    public function final(): ?ProjectJobCostingStep
    {
        return $this->isLocked() ? $this->steps->where('kind', ProjectJobCostingStep::KIND_APPROVED)->last() : null;
    }

    public function contractCents(): int
    {
        return $this->afterPayouts->contractCents;
    }

    public function targetPercent(): int
    {
        return $this->project->location->target_margin_percent;
    }

    /**
     * The retail price before any Brand Ambassador discount, with every
     * signed change order (slice 21, step 21c).
     */
    public function retailCents(): int
    {
        return $this->project->retailCents() + ($this->contractCents() - $this->project->sold_cents);
    }

    /**
     * The floor price the budget is set from: the retail price less the
     * largest Brand Ambassador discount, never the higher price it sold for
     * (product-architecture.md, 10.2; decision 175).
     */
    public function floorCents(): int
    {
        return intdiv($this->retailCents() * (100 - $this->project->floorDiscountPercent()), 100);
    }

    /**
     * The budget: the most the job can cost and still make the target margin
     * at the floor price. Selling above the floor is margin, not budget.
     */
    public function targetCostCents(): int
    {
        return intdiv($this->floorCents() * (100 - $this->targetPercent()), 100);
    }

    /**
     * What its warranty cases have cost: every warranty cost not voided
     * (slice 22, step 22c).
     */
    public function warrantyCents(): int
    {
        return (int) WarrantyCost::query()->where('project_id', $this->project->id)->whereNull('voided_at')->sum('amount_cents');
    }

    /**
     * The lifetime gross margin: the final one, or the projection before
     * it, less its warranty costs (product-architecture.md, 16).
     */
    public function lifetimeMarginCents(): int
    {
        return ($this->final()->margin_cents ?? $this->marginCents()) - $this->warrantyCents();
    }

    public function payoutsCents(): int
    {
        return $this->afterPayouts->payoutsCents;
    }

    public function costsCents(): int
    {
        return (int) $this->costs->sum('amount_cents');
    }

    /**
     * Every payout set and every cost recorded.
     */
    public function committedCents(): int
    {
        return $this->payoutsCents() + $this->costsCents();
    }

    /**
     * What is left of the target cost; below zero when over budget.
     */
    public function remainingCents(): int
    {
        return $this->targetCostCents() - $this->committedCents();
    }

    public function marginCents(): int
    {
        return $this->contractCents() - $this->committedCents();
    }

    /**
     * The margin as a share of the contract, to one decimal.
     */
    public function marginPercent(): ?float
    {
        return $this->contractCents() > 0 ? round($this->marginCents() * 100 / $this->contractCents(), 1) : null;
    }

    /**
     * As it is said: "$3,450 (40.8%)".
     */
    public function marginWords(): string
    {
        return self::words($this->marginCents(), $this->marginPercent());
    }

    /**
     * The costs still missing a receipt (question 3).
     *
     * @return Collection<int, ProjectCost>
     */
    public function missingReceipts(): Collection
    {
        return $this->costs->filter(fn (ProjectCost $cost): bool => $cost->receipts->isEmpty())->values();
    }

    /**
     * An amount with its share: "$3,450 (40.8%)".
     */
    public static function words(int $cents, ?float $percent): string
    {
        return Money::format($cents).($percent === null ? '' : ' ('.rtrim(rtrim(number_format($percent, 1), '0'), '.').'%)');
    }
}
