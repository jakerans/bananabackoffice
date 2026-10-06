<?php

namespace App\Support\Customers;

/**
 * Turns the name someone gave on an inquiry into a first and last name to start
 * a Contact from.
 *
 * It is deliberately cautious. A plain name is split at its last word. Anything
 * that looks like more than one person ("John & Mary Smith", "Bob and Sue") is
 * left whole in the first name, untouched: staff decide who the people are and
 * add the second one themselves. Nothing here ever makes two Contacts.
 */
final class InquiryName
{
    /**
     * @return array{first_name: string|null, last_name: string|null}
     */
    public static function split(?string $name): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) $name));

        if ($name === '') {
            return ['first_name' => null, 'last_name' => null];
        }

        $words = explode(' ', $name);

        if (count($words) === 1 || preg_match('/[&,\/+]|\b(and|or)\b/i', $name) === 1) {
            return ['first_name' => $name, 'last_name' => null];
        }

        $lastName = array_pop($words);

        return ['first_name' => implode(' ', $words), 'last_name' => $lastName];
    }
}
