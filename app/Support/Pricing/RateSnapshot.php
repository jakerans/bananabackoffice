<?php

namespace App\Support\Pricing;

use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\ProductionRate;

/**
 * A production rate as an estimate was priced with it
 * (docs/slice-31-estimating-plan.md, step 31b; decision 197, question 4). It
 * is a copy, so changing the rate later never changes an estimate already
 * priced; an estimate keeps it as it was (step 31c).
 */
final readonly class RateSnapshot
{
    /**
     * @param  list<string|null>  $values  For one, two, three and four coats.
     */
    public function __construct(
        public string $name,
        public RateMeasure $measure,
        public RateCalculation $calculation,
        public array $values,
        public bool $usesCoats,
        public ?string $prepHours = null,
        public ?int $hourlyRateCents = null,
        public bool $excludedFromDiscount = false,
        public bool $excludedFromTax = false,
        public ?string $customerLabel = null,
    ) {}

    public static function fromProductionRate(ProductionRate $rate): self
    {
        return new self(
            name: $rate->name,
            measure: $rate->measure,
            calculation: $rate->calculation,
            values: $rate->values(),
            usesCoats: $rate->uses_coats,
            prepHours: $rate->prep_hours,
            hourlyRateCents: $rate->hourly_rate_cents,
            excludedFromDiscount: $rate->excluded_from_discount,
            excludedFromTax: $rate->excluded_from_tax,
            customerLabel: $rate->customer_label,
        );
    }

    /**
     * @param  array<string, mixed>  $saved  As toArray() gave it.
     */
    public static function fromArray(array $saved): self
    {
        return new self(
            name: (string) $saved['name'],
            measure: RateMeasure::from($saved['measure']),
            calculation: RateCalculation::from($saved['calculation']),
            values: array_values(array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, (array) $saved['values'])),
            usesCoats: (bool) $saved['uses_coats'],
            prepHours: isset($saved['prep_hours']) ? (string) $saved['prep_hours'] : null,
            hourlyRateCents: isset($saved['hourly_rate_cents']) ? (int) $saved['hourly_rate_cents'] : null,
            excludedFromDiscount: (bool) ($saved['excluded_from_discount'] ?? false),
            excludedFromTax: (bool) ($saved['excluded_from_tax'] ?? false),
            customerLabel: isset($saved['customer_label']) ? (string) $saved['customer_label'] : null,
        );
    }

    /**
     * @return array{name: string, measure: string, calculation: string, values: list<string|null>, uses_coats: bool, prep_hours: string|null, hourly_rate_cents: int|null, excluded_from_discount: bool, excluded_from_tax: bool, customer_label: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'measure' => $this->measure->value,
            'calculation' => $this->calculation->value,
            'values' => $this->values,
            'uses_coats' => $this->usesCoats,
            'prep_hours' => $this->prepHours,
            'hourly_rate_cents' => $this->hourlyRateCents,
            'excluded_from_discount' => $this->excludedFromDiscount,
            'excluded_from_tax' => $this->excludedFromTax,
            'customer_label' => $this->customerLabel,
        ];
    }

    /**
     * What the customer reads: its customer label, or its name.
     */
    public function customerName(): string
    {
        return $this->customerLabel ?? $this->name;
    }

    /**
     * The coats that count: one for a rate that does not use coats.
     */
    public function coatsFor(int $coats): int
    {
        return $this->usesCoats ? max(1, min(ProductionRate::VALUES, $coats)) : 1;
    }

    /**
     * The value for that many coats, as PaintScout picks it; zero when the rate has none.
     */
    public function valueFor(int $coats): float
    {
        return (float) ($this->values[$this->coatsFor($coats) - 1] ?? 0);
    }
}
