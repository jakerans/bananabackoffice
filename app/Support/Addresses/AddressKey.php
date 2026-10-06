<?php

namespace App\Support\Addresses;

/**
 * Turns a street address into a simplified form, so the same address typed two
 * ways ("14 Oak Street" and "14 oak st.") is recognized as one.
 *
 * It is used to notice that a customer already has a property at an address.
 * It only ever raises a question for a person to answer; it never decides that
 * two records are the same.
 */
final class AddressKey
{
    /**
     * The usual short forms of street words and directions.
     */
    private const array ABBREVIATIONS = [
        'street' => 'st', 'avenue' => 'ave', 'boulevard' => 'blvd', 'road' => 'rd', 'drive' => 'dr',
        'lane' => 'ln', 'court' => 'ct', 'place' => 'pl', 'circle' => 'cir', 'terrace' => 'ter',
        'highway' => 'hwy', 'parkway' => 'pkwy', 'trail' => 'trl', 'square' => 'sq', 'way' => 'way',
        'north' => 'n', 'south' => 's', 'east' => 'e', 'west' => 'w',
        'northeast' => 'ne', 'northwest' => 'nw', 'southeast' => 'se', 'southwest' => 'sw',
        'apartment' => 'apt', 'suite' => 'ste', 'unit' => 'unit', 'building' => 'bldg', 'floor' => 'fl',
    ];

    /**
     * The simplified form of a street, unit and ZIP code, or null when there is no street to go on.
     */
    public static function for(?string $line1, ?string $line2 = null, ?string $postalCode = null): ?string
    {
        $street = self::simplify($line1);

        if ($street === '') {
            return null;
        }

        $zip = substr((string) preg_replace('/\D+/', '', (string) $postalCode), 0, 5);

        return implode('|', [$street, self::simplify($line2), $zip]);
    }

    /**
     * A street line with its street words shortened the usual way, for a compact
     * display: "14 Oak Street" becomes "14 Oak St". Everything else is left as typed.
     */
    public static function shortStreet(string $line1): string
    {
        $words = preg_split('/\s+/', trim($line1), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $position => $word) {
            $short = self::ABBREVIATIONS[mb_strtolower(rtrim($word, '.,'))] ?? null;

            // The first word is the house number or part of the street's name, never a word to shorten.
            if ($short !== null && $position > 0) {
                $words[$position] = ucfirst($short);
            }
        }

        return implode(' ', $words);
    }

    private static function simplify(?string $value): string
    {
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower(trim((string) $value)), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $word): string => self::ABBREVIATIONS[$word] ?? $word, $words));
    }
}
