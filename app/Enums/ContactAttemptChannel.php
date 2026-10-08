<?php

namespace App\Enums;

enum ContactAttemptChannel: string
{
    case Phone = 'phone';
    case Sms = 'sms';
    case Email = 'email';
    case Messenger = 'messenger';
    case Glsa = 'glsa';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Sms => 'SMS',
            self::Email => 'Email',
            self::Messenger => 'Messenger',
            self::Glsa => 'GLSA',
            self::Other => 'Other',
        };
    }

    public function requiresNumber(): bool
    {
        return $this === self::Phone || $this === self::Sms;
    }

    public function supportsDuration(): bool
    {
        return $this === self::Phone;
    }

    /** @return list<ContactAttemptDisposition> */
    public function dispositions(): array
    {
        if ($this === self::Phone) {
            return [
                ContactAttemptDisposition::Connected,
                ContactAttemptDisposition::NoAnswer,
                ContactAttemptDisposition::VoicemailLeft,
                ContactAttemptDisposition::WrongParty,
                ContactAttemptDisposition::Other,
            ];
        }

        return [
            ContactAttemptDisposition::Connected,
            ContactAttemptDisposition::MessageSent,
            ContactAttemptDisposition::WrongParty,
            ContactAttemptDisposition::Other,
        ];
    }

    public function supportsDisposition(ContactAttemptDisposition $disposition): bool
    {
        return in_array($disposition, $this->dispositions(), true);
    }
}
