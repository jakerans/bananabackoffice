<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectCost;
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
    /**
     * @param  Collection<int, ProjectCost>  $costs  Not voided, with their receipts.
     */
    private function __construct(
        public Project $project,
        public AfterPayouts $afterPayouts,
        public Collection $costs,
    ) {}

    public static function for(Project $project): self
    {
        $project->loadMissing('location');

        return new self(
            $project,
            AfterPayouts::for($project),
            $project->costs()->whereNull('voided_at')->with('receipts')->get(),
        );
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
     * The most the job can cost and still make the target margin.
     */
    public function targetCostCents(): int
    {
        return intdiv($this->contractCents() * (100 - $this->targetPercent()), 100);
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
