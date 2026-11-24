<?php

namespace App\Support\QuickBooks;

use App\Models\ProjectPayment;

/**
 * How Backoffice's ways of paying line up with a QuickBooks company's own
 * payment methods, which each company names itself: "Check", "Credit Card",
 * "Visa" (slice 38, step 38d). Matched by name, since nothing else is shared.
 */
final class PaymentMethods
{
    /** A word in a QuickBooks method's name, and the way of paying it means; the first that fits wins. */
    private const array WORDS = [
        'financ' => 'financing',
        'ach' => 'ach',
        'bank' => 'ach',
        'eft' => 'ach',
        'e-check' => 'ach',
        'echeck' => 'ach',
        'check' => 'check',
        'cheque' => 'check',
        'cash' => 'cash',
        'card' => 'card',
        'credit' => 'card',
        'debit' => 'card',
        'visa' => 'card',
        'master' => 'card',
        'amex' => 'card',
        'american express' => 'card',
        'discover' => 'card',
    ];

    /**
     * Backoffice's way of paying for a QuickBooks method's name; one it
     * cannot place is kept as paid in QuickBooks.
     */
    public static function methodFor(?string $name): string
    {
        $name = mb_strtolower(trim((string) $name));

        foreach (self::WORDS as $word => $method) {
            if ($name !== '' && str_contains($name, $word)) {
                return $method;
            }
        }

        return ProjectPayment::QUICKBOOKS;
    }

    /**
     * The company's own method for one of Backoffice's ways of paying, if it
     * has one.
     *
     * @param  list<array<string, mixed>>  $methods  The company's payment methods, as QuickBooks lists them.
     */
    public static function idFor(array $methods, string $method): ?string
    {
        foreach ($methods as $known) {
            if (isset($known['Id']) && self::methodFor((string) ($known['Name'] ?? '')) === $method) {
                return (string) $known['Id'];
            }
        }

        return null;
    }
}
