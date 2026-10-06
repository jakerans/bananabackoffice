<?php

namespace App\Support\Contact;

/**
 * Turns a phone number as someone typed it into one form used for matching.
 *
 * What was typed is always kept beside it and is never replaced. The normalized
 * form is international notation ("+15551234567"), so numbers from outside the
 * United States can be stored as they are. Recognition is tuned for ordinary
 * U.S. numbers today and can be improved later without changing what is stored.
 */
final class PhoneNumber
{
    /**
     * Get the matching form of a typed number, or null when it cannot be recognized.
     *
     * An unrecognized number is not an error: it is saved as typed and simply
     * cannot be matched. A trailing extension is ignored for matching.
     */
    public static function normalize(?string $typed): ?string
    {
        $typed = trim((string) $typed);

        if ($typed === '') {
            return null;
        }

        $withoutExtension = (string) preg_replace('/\s*(?:ext\.?|extension|x|#)\s*\d+\s*$/i', '', $typed);

        $isInternational = str_starts_with($withoutExtension, '+');
        $digits = (string) preg_replace('/\D+/', '', $withoutExtension);

        if ($isInternational) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        return match (true) {
            strlen($digits) === 10 => '+1'.$digits,
            strlen($digits) === 11 && str_starts_with($digits, '1') => '+'.$digits,
            default => null,
        };
    }

    /**
     * Show a number the usual U.S. way when it is one; otherwise show it as typed.
     */
    public static function display(?string $typed, ?string $normalized): ?string
    {
        if ($normalized !== null && preg_match('/^\+1(\d{3})(\d{3})(\d{4})$/', $normalized, $parts) === 1) {
            $extension = preg_match('/(?:ext\.?|extension|x|#)\s*(\d+)\s*$/i', (string) $typed, $found) === 1
                ? ' ext. '.$found[1]
                : '';

            return "({$parts[1]}) {$parts[2]}-{$parts[3]}{$extension}";
        }

        $typed = trim((string) $typed);

        return $typed === '' ? null : $typed;
    }
}
