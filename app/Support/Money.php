<?php

namespace App\Support;

class Money
{
    /** The largest amount accepted, in cents: ten million dollars. Anything above it is a typing mistake. */
    public const int MAX_CENTS = 1_000_000_000;

    /**
     * Read an amount of money as a person types it, in dollars, and give it
     * back in cents: "8450", "8,450", "$8,450.50". Cents are optional and at
     * most two digits.
     *
     * Nothing is rounded and nothing is guessed: anything that is not plainly
     * an amount above zero and up to the limit is refused with null.
     */
    public static function toCents(?string $typed): ?int
    {
        $amount = str_replace([',', ' '], '', ltrim(trim((string) $typed), '$'));

        if (preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $amount, $parts) !== 1) {
            return null;
        }

        $cents = ((int) $parts[1]) * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $cents > 0 && $cents <= self::MAX_CENTS ? $cents : null;
    }

    /**
     * Show an amount kept in cents, in dollars: "$8,450", or "$8,450.50" when
     * it has cents. An amount below zero, as a month's figures can be after a
     * correction, reads "-$8,450".
     */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, $cents % 100 === 0 ? 0 : 2);
    }
}
