<?php

namespace App\Enums;

/**
 * The kinds of estimate a price book prices (docs/slice-31-estimating-plan.md).
 * Commercial estimates are kept in the price book but not yet offered (5.9).
 */
enum EstimateType: string
{
    case ResidentialInterior = 'residential_interior';
    case ResidentialExterior = 'residential_exterior';
    case CommercialInterior = 'commercial_interior';
    case CommercialExterior = 'commercial_exterior';

    public function label(): string
    {
        return match ($this) {
            self::ResidentialInterior => 'Residential Interior',
            self::ResidentialExterior => 'Residential Exterior',
            self::CommercialInterior => 'Commercial Interior',
            self::CommercialExterior => 'Commercial Exterior',
        };
    }

    /**
     * Every type's value, in order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
