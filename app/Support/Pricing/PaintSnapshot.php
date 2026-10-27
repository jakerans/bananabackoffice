<?php

namespace App\Support\Pricing;

use App\Models\PaintProduct;

/**
 * A paint as an estimate was priced with it: its price for one unit, and
 * how far one unit goes (docs/slice-31-estimating-plan.md, step 31b).
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
}
