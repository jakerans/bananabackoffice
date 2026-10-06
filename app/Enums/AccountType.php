<?php

namespace App\Enums;

/**
 * What kind of customer an Account is. Nothing assumes a homeowner.
 */
enum AccountType: string
{
    /** A household. Shown by its primary Contact's name. */
    case Residential = 'residential';

    /** A business. Shown by its business name. */
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::Commercial => 'Commercial',
        };
    }
}
