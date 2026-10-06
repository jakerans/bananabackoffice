<?php

namespace App\Support\Customers;

use App\Models\Contact;
use App\Support\Contact\PhoneNumber;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A person's details as typed on a form: trimmed, with anything empty treated as
 * not given.
 *
 * A Contact needs a first or last name. Phone and email are optional and are
 * never required to be unique: they are signals for a person to weigh.
 */
final readonly class ContactDetails
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $phone = null,
        public ?string $alternatePhone = null,
        public ?string $email = null,
    ) {}

    /**
     * @param  array{first_name?: string|null, last_name?: string|null, phone?: string|null, alternate_phone?: string|null, email?: string|null}  $input
     */
    public static function fromInput(array $input): self
    {
        $value = function (string $key) use ($input): ?string {
            $trimmed = trim((string) ($input[$key] ?? ''));

            return $trimmed === '' ? null : $trimmed;
        };

        return new self($value('first_name'), $value('last_name'), $value('phone'), $value('alternate_phone'), $value('email'));
    }

    public static function ofContact(Contact $contact): self
    {
        return new self($contact->first_name, $contact->last_name, $contact->phone, $contact->alternate_phone, $contact->email);
    }

    /**
     * Whether nothing at all was given, which is how "no contact yet" is said for a business.
     */
    public function isEmpty(): bool
    {
        return $this->firstName === null && $this->lastName === null && $this->phone === null && $this->alternatePhone === null && $this->email === null;
    }

    /**
     * @throws ValidationException
     */
    public function ensureNamed(): void
    {
        if ($this->firstName === null && $this->lastName === null) {
            throw ValidationException::withMessages([
                'contactFirstName' => __('Enter a first or last name for this person.'),
            ]);
        }
    }

    /**
     * The values to store on a Contact, with a matching form beside each phone and email.
     *
     * @return array<string, string|null>
     */
    public function attributes(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'phone_normalized' => PhoneNumber::normalize($this->phone),
            'alternate_phone' => $this->alternatePhone,
            'alternate_phone_normalized' => PhoneNumber::normalize($this->alternatePhone),
            'email' => $this->email,
            'email_normalized' => $this->email === null ? null : Str::lower($this->email),
        ];
    }

    /**
     * What a person would recognize in the audit history: the details as typed.
     *
     * @return array<string, string|null>
     */
    public function forAudit(): array
    {
        return [
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'phone' => $this->phone,
            'alternate_phone' => $this->alternatePhone,
            'email' => $this->email,
        ];
    }
}
