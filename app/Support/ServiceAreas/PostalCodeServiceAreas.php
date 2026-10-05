<?php

namespace App\Support\ServiceAreas;

use App\Models\Location;
use App\Support\Addresses\StructuredAddress;

/**
 * Service areas defined as postal codes.
 */
final class PostalCodeServiceAreas implements ServiceAreaResolver
{
    /**
     * The one active Location that serves the address's postal code.
     *
     * Null when the address has no usable postal code, when no active Location
     * serves it, or when more than one does. Two Locations claiming the same
     * postal code is a question for a person, not something to pick between.
     */
    public function locationFor(StructuredAddress $address): ?Location
    {
        $postalCode = PostalCode::normalize($address->postalCode);

        if ($postalCode === null) {
            return null;
        }

        $serving = Location::query()
            ->active()
            ->whereHas('servicePostalCodes', fn ($query) => $query->where('postal_code', $postalCode))
            ->limit(2)
            ->get();

        return $serving->count() === 1 ? $serving->first() : null;
    }
}
