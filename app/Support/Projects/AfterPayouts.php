<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Models\WorkPackage;
use App\Support\Money;

/**
 * What a job brings in after subcontractor payouts
 * (docs/slice-18-change-orders-plan.md, step 18c; decision 166, question 8;
 * product-architecture.md, 6.7): the contract total before tax (the price
 * sold and every signed change order) less every Work Package's payout, in
 * dollars and as a share of the contract.
 *
 * Never called gross margin: materials and every other cost come with job
 * costing (6.9). Gross payouts are used; the insurance deduction stays its
 * own entry (decision 133). Finance-sensitive: shown only to those who may
 * see payouts.
 */
final readonly class AfterPayouts
{
    /**
     * @param  int  $packagesWithoutPayout  Work Packages with no payout set yet, so the figure is not complete.
     */
    private function __construct(
        public int $contractCents,
        public int $payoutsCents,
        public int $packagesWithoutPayout,
    ) {}

    public static function for(Project $project): self
    {
        $contract = $project->sold_cents + (int) $project->changeOrders()->where('status', ProjectChangeOrder::STATUS_SIGNED)->sum('price_change_cents');
        $packages = WorkPackage::query()->where('project_id', $project->id)->get(['id', 'payout_cents']);

        return new self(
            $contract,
            (int) $packages->sum('payout_cents'),
            $packages->whereNull('payout_cents')->count(),
        );
    }

    /**
     * What is left after payouts, in cents.
     */
    public function afterCents(): int
    {
        return $this->contractCents - $this->payoutsCents;
    }

    /**
     * What is left as a share of the contract, to one decimal: "38.5".
     * Nothing when there is no contract to divide by.
     */
    public function percent(): ?float
    {
        return $this->contractCents > 0 ? round($this->afterCents() * 100 / $this->contractCents, 1) : null;
    }

    /**
     * As it is said: "$3,450 (38.5%)".
     */
    public function words(): string
    {
        $percent = $this->percent();

        return Money::format($this->afterCents()).($percent === null ? '' : ' ('.rtrim(rtrim(number_format($percent, 1), '0'), '.').'%)');
    }

    /**
     * The same, had the contract changed by this much: a change order about
     * to be signed.
     */
    public function withPriceChange(int $cents): self
    {
        return new self($this->contractCents + $cents, $this->payoutsCents, $this->packagesWithoutPayout);
    }

    /**
     * The same, had one package's payout been this instead: a payout about
     * to be saved.
     */
    public function withPayout(?int $currentCents, int $newCents): self
    {
        return new self($this->contractCents, $this->payoutsCents - (int) $currentCents + $newCents, $this->packagesWithoutPayout - ($currentCents === null ? 1 : 0));
    }
}
