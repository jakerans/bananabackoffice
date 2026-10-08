<?php

namespace App\Enums;

/** What factually happened in the attempt, without implying a lifecycle change. */
enum ContactAttemptDisposition: string
{
    case Connected = 'connected';
    case NoAnswer = 'no_answer';
    case VoicemailLeft = 'voicemail_left';
    case MessageSent = 'message_sent';
    case WrongParty = 'wrong_party';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Connected => 'Connected',
            self::NoAnswer => 'No answer',
            self::VoicemailLeft => 'Voicemail left',
            self::MessageSent => 'Message sent',
            self::WrongParty => 'Wrong party',
            self::Other => 'Other',
        };
    }

    public function answered(): ?bool
    {
        return match ($this) {
            self::Connected, self::WrongParty => true,
            self::NoAnswer, self::VoicemailLeft, self::MessageSent => false,
            self::Other => null,
        };
    }

    public function connected(): ?bool
    {
        return match ($this) {
            self::Connected => true,
            self::NoAnswer, self::VoicemailLeft, self::MessageSent, self::WrongParty => false,
            self::Other => null,
        };
    }
}
