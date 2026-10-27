<?php

namespace App\Support\Pricing;

/**
 * A line item's figures (docs/slice-31-estimating-plan.md, step 31b).
 */
final readonly class PricedLineItem
{
    public function __construct(
        public LineItemInput $input,
        public float $hours,
        public int $priceCents,
        public bool $isCounted,
    ) {}
}
