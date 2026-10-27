<?php

namespace App\Support\Pricing;

/**
 * A line of the estimate outside its areas, such as a trip charge: a set
 * price, or hours at the hourly rate (docs/slice-31-estimating-plan.md, step
 * 31b). An optional one is priced but left out of the total.
 */
final readonly class LineItemInput
{
    public function __construct(
        public string $name,
        public ?string $hours = null,
        public ?int $priceCents = null,
        public ?int $hourlyRateCents = null,
        public bool $isOptional = false,
    ) {}
}
