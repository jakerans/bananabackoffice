<?php

namespace App\Support\Customers;

use App\Models\Account;
use App\Models\Contact;
use App\Models\Property;

/**
 * A customer the viewer can already see that a Lead may be from, and why.
 *
 * It is a suggestion for a person to accept or ignore. Nothing is ever linked or
 * merged because of it alone.
 */
final readonly class CustomerMatch
{
    public const string PHONE = 'phone';

    public const string EMAIL = 'email';

    public const string ADDRESS = 'address';

    public const string BUSINESS_NAME = 'business_name';

    /**
     * @param  list<string>  $reasons  Which details matched, strongest first.
     * @param  Contact|null  $contact  The person at the customer whose phone or email matched.
     * @param  Property|null  $property  The customer's property at the same address.
     */
    public function __construct(
        public Account $account,
        public array $reasons,
        public ?Contact $contact = null,
        public ?Property $property = null,
    ) {}

    /**
     * Why this customer is suggested, in words: "same phone, property at this address".
     */
    public function reasonLabel(): string
    {
        return implode(', ', array_map(fn (string $reason): string => match ($reason) {
            self::PHONE => 'same phone',
            self::EMAIL => 'same email',
            self::ADDRESS => 'property at this address',
            default => 'same business name',
        }, $this->reasons));
    }
}
