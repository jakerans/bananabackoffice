<?php

namespace App\Support\Pricing;

/**
 * Everything an estimate's price is worked out from
 * (docs/slice-31-estimating-plan.md, step 31b).
 */
final readonly class EstimateInput
{
    /**
     * @param  list<AreaInput>  $areas
     * @param  list<LineItemInput>  $lineItems
     * @param  list<DiscountInput>  $discounts
     * @param  string|null  $taxPercent  Null or zero for no tax.
     */
    public function __construct(
        public int $hourlyRateCents,
        public array $areas = [],
        public array $lineItems = [],
        public array $discounts = [],
        public ?string $taxPercent = null,
    ) {}
}
