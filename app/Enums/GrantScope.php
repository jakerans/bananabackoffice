<?php

namespace App\Enums;

/**
 * Where an access grant applies. Company-wide access is always this explicit
 * value; a missing location never means "everywhere".
 */
enum GrantScope: string
{
    case Location = 'location';
    case AllLocations = 'all_locations';

    public function label(): string
    {
        return match ($this) {
            self::Location => 'One location',
            self::AllLocations => 'All locations',
        };
    }
}
