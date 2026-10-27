<?php

namespace App\Support\Pricing;

/**
 * A line of the estimate outside its areas, such as a trip charge
 * (docs/slice-31-estimating-plan.md, step 31b). It is priced one of three
 * ways, as in PaintScout: hours at the hourly rate (its own or the
 * estimate's), a set price, or a quantity with hours and a price for each.
 * An optional one is priced but left out of the total.
 */
final readonly class LineItemInput
{
    /**
     * @param  string|null  $hours  Hours at the hourly rate; with a set price, only the crew's hours.
     * @param  int|null  $priceCents  A set price.
     * @param  string|null  $quantity  How many, when priced by quantity.
     * @param  string|null  $hoursEach  Hours for each one, when priced by quantity.
     * @param  int|null  $priceEachCents  The price of each one, when priced by quantity.
     */
    public function __construct(
        public string $name,
        public ?string $hours = null,
        public ?int $priceCents = null,
        public ?int $hourlyRateCents = null,
        public bool $isOptional = false,
        public ?string $quantity = null,
        public ?string $hoursEach = null,
        public ?int $priceEachCents = null,
    ) {}
}
