<?php

namespace App\Enums;

/**
 * How a production rate's values turn a measurement into time or money
 * (docs/slice-31-estimating-plan.md).
 */
enum RateCalculation: string
{
    /**
     * How much is done in an hour: hours = amount / value. For a count of
     * items PaintScout multiplies instead, so a door rate of 0.5 is half an
     * hour for each door (step 31b).
     */
    case UnitsPerHour = 'units_per_hour';

    /** A price for each one: price = amount x value. */
    case PricePerUnit = 'price_per_unit';

    /** Hours for each one: hours = amount x value. */
    case HoursPerUnit = 'hours_per_unit';

    /** A set number of hours: hours = value. */
    case FixedHours = 'fixed_hours';

    /**
     * What one value means, for a rate counted in the given measure.
     */
    public function label(RateMeasure $measure): string
    {
        return match ($this) {
            self::UnitsPerHour => $measure === RateMeasure::Count ? __('Hours each') : __(':unit per hour', ['unit' => $measure->unit()]),
            self::PricePerUnit => $measure === RateMeasure::Count ? __('Price each') : __('Price per :unit', ['unit' => $measure->unit()]),
            self::HoursPerUnit => $measure === RateMeasure::Count ? __('Hours each') : __('Hours per :unit', ['unit' => $measure->unit()]),
            self::FixedHours => __('Hours'),
        };
    }
}
