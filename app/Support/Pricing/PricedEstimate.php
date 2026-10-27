<?php

namespace App\Support\Pricing;

/**
 * An estimate's figures (docs/slice-31-estimating-plan.md, step 31b): its
 * areas and line items, then the subtotal, the discount, the tax and the
 * total. Optional work is priced but only shown beside the total.
 */
final readonly class PricedEstimate
{
    /**
     * @param  list<PricedArea>  $areas
     * @param  list<PricedLineItem>  $lineItems
     * @param  list<array{discount: DiscountInput, cents: int}>  $discounts
     */
    public function __construct(
        public array $areas,
        public array $lineItems,
        public float $hours,
        public int $workCents,
        public int $materialsCents,
        public int $lineItemsCents,
        public int $subtotalCents,
        public array $discounts,
        public int $discountCents,
        public int $taxCents,
        public int $totalCents,
        public int $optionalCents,
    ) {}
}
