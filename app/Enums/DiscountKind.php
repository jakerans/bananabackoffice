<?php

namespace App\Enums;

/**
 * How a discount takes money off an estimate (docs/slice-31-estimating-plan.md, step 31b).
 */
enum DiscountKind: string
{
    /** A percentage of what can be discounted, rounded up to the whole dollar, as PaintScout does. */
    case Percentage = 'percentage';

    /** A set amount in dollars. */
    case Amount = 'amount';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => __('Percentage'),
            self::Amount => __('Amount'),
        };
    }
}
