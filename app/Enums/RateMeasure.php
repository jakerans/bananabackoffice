<?php

namespace App\Enums;

/**
 * What a production rate is counted in, taken from an area's measurements or
 * typed in (docs/slice-31-estimating-plan.md).
 */
enum RateMeasure: string
{
    case WallArea = 'wall_area';
    case CeilingArea = 'ceiling_area';
    case FloorArea = 'floor_area';
    case LinearFeet = 'linear_feet';
    case Count = 'count';

    public function label(): string
    {
        return match ($this) {
            self::WallArea => 'Wall area',
            self::CeilingArea => 'Ceiling area',
            self::FloorArea => 'Floor area',
            self::LinearFeet => 'Length',
            self::Count => 'Count',
        };
    }

    /**
     * The unit one value is counted in.
     */
    public function unit(): string
    {
        return match ($this) {
            self::WallArea, self::CeilingArea, self::FloorArea => 'sq ft',
            self::LinearFeet => 'ft',
            self::Count => 'each',
        };
    }
}
