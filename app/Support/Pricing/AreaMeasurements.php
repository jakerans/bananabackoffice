<?php

namespace App\Support\Pricing;

use App\Enums\AreaShape;
use App\Enums\RateMeasure;

/**
 * The amounts an area's rates are counted in, worked out from its width,
 * length and height in feet as PaintScout does (docs/slice-31-estimating-plan.md,
 * step 31b). Nothing is rounded: a room 13.58 by 10.25 has a ceiling of
 * 139.195 square feet.
 */
final readonly class AreaMeasurements
{
    public function __construct(
        public float $linearFeet,
        public float $wallArea,
        public float $ceilingArea,
        public float $floorArea,
    ) {}

    public static function of(AreaInput $area): self
    {
        $width = (float) $area->width;
        $length = (float) $area->length;
        $height = (float) $area->height;

        if ($area->shape === AreaShape::Surface) {
            return new self($length, $length * $height, $length * $width, $length * $width);
        }

        $around = 2 * ($width + $length);

        return new self($around, $around * $height, $width * $length, $width * $length);
    }

    /**
     * The amount a rate counted in that measure works from; a count is the surface's own quantity.
     */
    public function amountFor(RateMeasure $measure, float $quantity): float
    {
        return match ($measure) {
            RateMeasure::WallArea => $this->wallArea,
            RateMeasure::CeilingArea => $this->ceilingArea,
            RateMeasure::FloorArea => $this->floorArea,
            RateMeasure::LinearFeet => $this->linearFeet,
            RateMeasure::Count => $quantity,
        };
    }
}
