<?php

namespace App\Support\Pricing;

use App\Enums\DiscountKind;
use App\Models\Discount;

/**
 * A discount given on an estimate (docs/slice-31-estimating-plan.md, step 31b).
 */
final readonly class DiscountInput
{
    /**
     * @param  string  $value  A percentage, or dollars.
     */
    public function __construct(
        public string $name,
        public DiscountKind $kind,
        public string $value,
    ) {}

    public static function fromDiscount(Discount $discount): self
    {
        return new self($discount->name, $discount->kind, $discount->value);
    }
}
