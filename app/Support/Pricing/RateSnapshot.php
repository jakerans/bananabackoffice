<?php

namespace App\Support\Pricing;

use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\ProductionRate;

/**
 * A production rate as an estimate was priced with it
 * (docs/slice-31-estimating-plan.md, step 31b; decision 197, question 4). It
 * is a copy, so changing the rate later never changes an estimate already
 * priced.
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
        );
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
