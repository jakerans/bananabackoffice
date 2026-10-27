<?php

namespace App\Support\Pricing;

/**
 * One piece of work in an estimate's area, priced by a production rate: the
 * walls, the ceiling, four doors (docs/slice-31-estimating-plan.md, step 31b).
 * An optional one is priced but left out of the total.
 */
final readonly class SurfaceInput
{
    /**
     * @param  string  $quantity  How many, for a rate that counts items.
     * @param  string|null  $prepHours  The surface's own prep hours, typed in the estimate; null for the rate's.
     */
    public function __construct(
        public RateSnapshot $rate,
        public ?PaintSnapshot $paint = null,
        public int $coats = 1,
        public string $quantity = '1',
        public bool $isOptional = false,
        public ?string $prepHours = null,
    ) {}
}
