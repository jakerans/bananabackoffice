<?php

namespace App\Support\Pricing;

/**
 * Exact decimals for price books (docs/slice-31-estimating-plan.md, step
 * 31a): production rate values, hours and coverage, typed by people and kept
 * by the database as strings, never as floats, so nothing is lost on the way.
 */
class Decimal
{
    /** A number from 0 to 99,999,999 with at most four decimal places, as typed. */
    public const array RULES = ['string', 'max:20', 'regex:/^\s*\d{0,8}(\.\d{1,4})?\s*$/', 'not_regex:/^\s*\.?\s*$/'];

    /**
     * A typed decimal as the database keeps it, or null when nothing was typed.
     */
    public static function normalize(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        return self::plain(str_starts_with($typed, '.') ? '0'.$typed : $typed);
    }

    /**
     * A decimal as people write it, without trailing zeros: "100", "0.5"; null stays null.
     */
    public static function plain(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $value = ltrim($value, '0') === '' || str_starts_with(ltrim($value, '0'), '.') ? '0'.ltrim($value, '0') : ltrim($value, '0');

        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
