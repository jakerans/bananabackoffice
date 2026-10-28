<?php

namespace App\Support\Pricing;

use App\Models\PaintProduct;

/**
 * A paint as an estimate was priced with it: its price for one unit, and
 * how far one unit goes (docs/slice-31-estimating-plan.md, step 31b). An
 * estimate keeps it as it was (step 31c).
 */
final readonly class PaintSnapshot
{
    public function __construct(
        public string $name,
        public int $priceCents,
        public ?string $coverageArea = null,
        public ?string $coverageLength = null,
        public ?string $coverageEach = null,
    ) {}

    public static function fromPaintProduct(PaintProduct $paint): self
    {
        return new self(
            name: $paint->name,
            priceCents: $paint->price_cents,
            coverageArea: $paint->coverage_area,
            coverageLength: $paint->coverage_length,
            coverageEach: $paint->coverage_each,
        );
    }

    /**
     * @param  array<string, mixed>  $saved  As toArray() gave it.
     */
    public static function fromArray(array $saved): self
    {
        return new self(
            name: (string) $saved['name'],
            priceCents: (int) $saved['price_cents'],
            coverageArea: isset($saved['coverage_area']) ? (string) $saved['coverage_area'] : null,
            coverageLength: isset($saved['coverage_length']) ? (string) $saved['coverage_length'] : null,
            coverageEach: isset($saved['coverage_each']) ? (string) $saved['coverage_each'] : null,
        );
    }

    /**
     * @return array{name: string, price_cents: int, coverage_area: string|null, coverage_length: string|null, coverage_each: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'price_cents' => $this->priceCents,
            'coverage_area' => $this->coverageArea,
            'coverage_length' => $this->coverageLength,
            'coverage_each' => $this->coverageEach,
        ];
    }
}
