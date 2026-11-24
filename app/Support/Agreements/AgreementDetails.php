<?php

namespace App\Support\Agreements;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Support\Money;
use App\Support\Pricing\EstimatePricing;

/**
 * What the customer sees above the wording when they sign
 * (docs/slice-33-agreement-plan.md, step 33b): who and where, the option
 * chosen with its rooms and written line items, the savings, whether tax is
 * included, the price, and the deposit due under the Location's policy
 * (slice 17) or as chosen for this estimate (slice 38, step 38c), on the
 * price before tax, so the deposit carries no tax. Kept with a signed
 * agreement exactly as shown.
 */
class AgreementDetails
{
    public function __construct(private EstimatePricing $estimatePricing) {}

    /**
     * @return array{customer: string|null, property: string|null, location: string, estimate_type: string, option: array{name: string, description: string|null}, areas: list<string>, line_items: list<array{name: string, description: string|null}>, discounts: list<array{name: string, cents: int}>, tax_cents: int, total_cents: int, price_before_tax_cents: int, deposit_percent: int|null, deposit_cents: int}
     */
    public function for(Lead $lead, Estimate $estimate, EstimateOption $option, ?string $property): array
    {
        $priced = $this->estimatePricing->price($estimate, $option);
        $priceCents = $priced->subtotalCents - $priced->discountCents;
        $depositCents = $estimate->depositCentsOn($priceCents);

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
            'price_before_tax_cents' => $priceCents,
            // The percent when the deposit is a percent; a flat deposit or none has no percent to say.
            'deposit_percent' => $estimate->deposit_flat_cents !== null ? null : ($estimate->deposit_percent ?? (int) $lead->location->deposit_percent),
            'deposit_cents' => $depositCents,
        ];
    }

    /**
     * The deposit line the customer reads, from kept details: a percent of
     * the price, a flat amount, or none. An agreement signed before step 38c
     * has a percent of the price with tax, said as it was then.
     *
     * @param  array<string, mixed>  $details
     */
    public static function depositLine(array $details): string
    {
        $amount = Money::format((int) ($details['deposit_cents'] ?? 0));

        return match (true) {
            (int) ($details['deposit_cents'] ?? 0) === 0 => __('No deposit is due on acceptance.'),
            ($details['deposit_percent'] ?? null) === null => __('Deposit due on acceptance: :amount.', ['amount' => $amount]),
            array_key_exists('price_before_tax_cents', $details) => __('Deposit due on acceptance: :amount (:percent% of the price before tax).', ['amount' => $amount, 'percent' => $details['deposit_percent']]),
            default => __('Deposit due on acceptance: :amount (:percent% of the price).', ['amount' => $amount, 'percent' => $details['deposit_percent']]),
        };
    }
}
