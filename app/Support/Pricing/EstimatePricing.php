<?php

namespace App\Support\Pricing;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateDiscount;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;

/**
 * Prices a saved estimate's option with the pricing engine
 * (docs/slice-31-estimating-plan.md, step 31c): its own areas and line items,
 * with the estimate's hourly rate, discounts and tax (decision 204).
 */
class EstimatePricing
{
    public function __construct(private PricingEngine $engine) {}

    public function price(Estimate $estimate, EstimateOption $option): PricedEstimate
    {
        return $this->engine->price($this->input($estimate, $option));
    }

    public function input(Estimate $estimate, EstimateOption $option): EstimateInput
    {
        $option->loadMissing(['areas.surfaces', 'lineItems']);
        $estimate->loadMissing('discounts');

        return new EstimateInput(
            hourlyRateCents: $estimate->hourly_rate_cents,
            areas: array_values($option->areas->map(fn (EstimateArea $area): AreaInput => $area->toInput())->all()),
            lineItems: array_values($option->lineItems->map(fn (EstimateLineItem $item): LineItemInput => $item->toInput())->all()),
            discounts: array_values($estimate->discounts->map(fn (EstimateDiscount $discount): DiscountInput => $discount->toInput())->all()),
            taxPercent: $estimate->tax_percent,
        );
    }

    /**
     * Price every option again and keep each one's total, for lists.
     */
    public function refreshTotals(Estimate $estimate): void
    {
        $estimate->unsetRelation('discounts');

        foreach ($estimate->options()->get() as $option) {
            $total = $this->price($estimate, $option)->totalCents;

            if ($option->total_cents !== $total) {
                $option->forceFill(['total_cents' => $total])->save();
            }
        }
    }
}
