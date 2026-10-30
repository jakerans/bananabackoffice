<?php

namespace App\Support\Agreements;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Support\Pricing\EstimatePricing;

/**
 * What the customer sees above the wording when they sign
 * (docs/slice-33-agreement-plan.md, step 33b): who and where, the option
 * chosen with its rooms and written line items, the savings, whether tax is
 * included, the price, and the deposit due under the Location's policy
 * (slice 17), worked out as Projects work it out. Kept with a signed
 * agreement exactly as shown.
 */
class AgreementDetails
{
    public function __construct(private EstimatePricing $estimatePricing) {}

    /**
     * @return array{customer: string|null, property: string|null, location: string, estimate_type: string, option: array{name: string, description: string|null}, areas: list<string>, line_items: list<array{name: string, description: string|null}>, discounts: list<array{name: string, cents: int}>, tax_cents: int, total_cents: int, deposit_percent: int, deposit_cents: int}
     */
    public function for(Lead $lead, Estimate $estimate, EstimateOption $option, ?string $property): array
    {
        $priced = $this->estimatePricing->price($estimate, $option);
        $depositPercent = (int) $lead->location->deposit_percent;

        return [
            'customer' => $lead->displayName(),
            'property' => $property,
            'location' => $lead->location->name,
            'estimate_type' => $estimate->estimate_type->label(),
            'option' => ['name' => $option->name, 'description' => $option->description],
            'areas' => array_values($option->areas()->pluck('name')->all()),
            'line_items' => array_values($option->lineItems()->get()->map(fn (EstimateLineItem $item): array => ['name' => $item->name, 'description' => $item->description])->all()),
            'discounts' => array_values(array_map(
                fn (array $discount): array => ['name' => $discount['discount']->name, 'cents' => $discount['cents']],
                array_filter($priced->discounts, fn (array $discount): bool => $discount['cents'] > 0),
            )),
            'tax_cents' => $priced->taxCents,
            'total_cents' => $option->total_cents,
            'deposit_percent' => $depositPercent,
            'deposit_cents' => intdiv($option->total_cents * $depositPercent, 100),
        ];
    }
}
