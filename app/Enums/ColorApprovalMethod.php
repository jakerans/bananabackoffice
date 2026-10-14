<?php

namespace App\Enums;

/**
 * How the customer said yes: to the colors (decision 141, question 4) and to
 * the dates of the work (decision 144). Recorded by staff until the customer
 * can say it for themselves.
 */
enum ColorApprovalMethod: string
{
    case InPerson = 'in_person';
    case Phone = 'phone';
    case Text = 'text';
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::InPerson => 'In person',
            self::Phone => 'By phone',
            self::Text => 'By text',
            self::Email => 'By email',
        };
    }
}
