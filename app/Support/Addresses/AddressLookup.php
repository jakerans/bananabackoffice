<?php

namespace App\Support\Addresses;

/**
 * Finds real addresses from what someone types.
 *
 * Whatever does the finding sits behind this, so the rest of Backoffice does not
 * depend on one provider. Neither method ever throws: if the provider is slow,
 * down or not set up, staff simply type the address by hand.
 */
interface AddressLookup
{
    /**
     * Whether lookup is set up at all. When it is not, only manual entry is offered.
     */
    public function isAvailable(): bool;

    /**
     * Addresses that match what has been typed so far. Empty when there is nothing
     * useful to offer, including when the provider cannot be reached.
     *
     * @return list<AddressSuggestion>
     */
    public function suggest(string $typed): array;

    /**
     * The full structured address for a suggestion the person selected, obtained
     * in the way that permits keeping it. Null when it could not be confirmed.
     */
    public function resolve(AddressSuggestion $suggestion): ?StructuredAddress;
}
