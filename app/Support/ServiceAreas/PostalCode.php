<?php

namespace App\Support\ServiceAreas;

/**
 * Postal codes as service areas compare them: the five-digit United States ZIP.
 */
final class PostalCode
{
    /**
     * Reduce a typed postal code to its five-digit ZIP, or null when it is not one.
     *
     * "30301", " 30301 " and "30301-1234" all give "30301".
     */
    public static function normalize(?string $typed): ?string
    {
        return preg_match('/^\s*(\d{5})(?:-\d{4})?\s*$/', (string) $typed, $found) === 1 ? $found[1] : null;
    }
}
