<?php

namespace App\Support\Pricing;

/**
 * A surface's figures (docs/slice-31-estimating-plan.md, step 31b). Its work
 * is its labor, or its price for a rate priced per item; its total adds the
 * paint. One that is not counted, being optional, is left out of the total.
 */
final readonly class PricedSurface
{
    public function __construct(
        public SurfaceInput $input,
        public float $amount,
        public int $coats,
        public float $value,
        public float $hours,
        public float $prepHours,
        public int $workCents,
        public float $gallons,
        public int $materialsCents,
        public bool $isCounted,
    ) {}

    public function totalCents(): int
    {
        return $this->workCents + $this->materialsCents;
    }
}
