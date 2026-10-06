<?php

namespace App\Support\Customers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Contact;

/**
 * A customer the viewer cannot see whose Contact has exactly the phone or email
 * on a Lead.
 *
 * Only three things about it may ever be shown: the kind of customer, which
 * detail matched, and a masked form of that detail. The Account and Contact are
 * held here for the server to act on once the person confirms; they are never
 * sent to a browser.
 */
final readonly class MaskedCustomerMatch
{
    public function __construct(
        public Account $account,
        public Contact $contact,
        public string $field,
        public string $maskedValue,
    ) {}

    public function type(): AccountType
    {
        return $this->account->type;
    }

    /**
     * Everything a person is told about the match: "An existing residential
     * customer has a phone number ending in 44."
     */
    public function description(): string
    {
        $kind = mb_strtolower($this->type()->label());

        return $this->field === CustomerMatch::PHONE
            ? "An existing {$kind} customer has a phone number {$this->maskedValue}."
            : "An existing {$kind} customer has an email address {$this->maskedValue}.";
    }

    /**
     * A phone number reduced to its last two digits: "ending in 44".
     */
    public static function maskPhone(string $normalized): string
    {
        return 'ending in '.substr($normalized, -2);
    }

    /**
     * An email address reduced to its first letter and its ending: "like m•••@•••.com".
     */
    public static function maskEmail(string $normalized): string
    {
        $first = mb_substr($normalized, 0, 1);
        $ending = str_contains($normalized, '.') ? mb_substr($normalized, (int) mb_strrpos($normalized, '.')) : '';

        return "like {$first}•••@•••{$ending}";
    }
}
