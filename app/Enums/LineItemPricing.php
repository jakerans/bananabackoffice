<?php

namespace App\Enums;

/**
 * How a line item on an estimate is priced, as in PaintScout
 * (docs/slice-31-estimating-plan.md, step 31c).
 */
enum LineItemPricing: string
{
    /** Hours at the hourly rate, its own or the estimate's. */
    case Hours = 'hours';

    /** A set price. */
    case Price = 'price';

    /** A quantity, with hours and a price for each. */
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Hours => __('By hours'),
            self::Price => __('Set price'),
            self::Quantity => __('By quantity'),
        };
    }
}
