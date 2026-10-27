<?php

namespace App\Support\Pricing;

/**
 * An area's figures: its surfaces, and the sums of those that count
 * (docs/slice-31-estimating-plan.md, step 31b).
 */
final readonly class PricedArea
{
    /**
     * @param  list<PricedSurface>  $surfaces
     */
    public function __construct(
        public AreaInput $input,
        public AreaMeasurements $measurements,
        public array $surfaces,
        public float $hours,
        public int $workCents,
        public int $materialsCents,
        public int $optionalCents,
    ) {}

    public function totalCents(): int
    {
        return $this->workCents + $this->materialsCents;
    }
}
