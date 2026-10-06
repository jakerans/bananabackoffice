<?php

namespace App\Support\Addresses;

/**
 * Used when no address provider is set up. Staff type addresses by hand.
 */
final class UnavailableAddressLookup implements AddressLookup
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function suggest(string $typed): array
    {
        return [];
    }

    public function resolve(AddressSuggestion $suggestion): ?StructuredAddress
    {
        return null;
    }
}
