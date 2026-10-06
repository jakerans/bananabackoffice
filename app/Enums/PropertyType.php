<?php

namespace App\Enums;

/**
 * What kind of place a Property is. A household may own a commercial property
 * and a business may own a residential one, so this is not taken from the Account.
 */
enum PropertyType: string
{
    case Residential = 'residential';
    case Commercial = 'commercial';

    public function label(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::Commercial => 'Commercial',
        };
    }
}
