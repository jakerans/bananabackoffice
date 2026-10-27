<?php

namespace App\Support\Pricing;

use App\Enums\AreaShape;

/**
 * A room or a surface in an estimate, with its measurements in feet and the
 * work in it (docs/slice-31-estimating-plan.md, step 31b). An optional area is
 * priced but left out of the total.
 */
final readonly class AreaInput
{
    /**
     * @param  list<SurfaceInput>  $surfaces
     */
    public function __construct(
        public string $name,
        public AreaShape $shape,
        public string $width,
        public string $length,
        public string $height,
        public array $surfaces = [],
        public bool $isOptional = false,
    ) {}
}
