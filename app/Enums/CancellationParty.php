<?php

namespace App\Enums;

/**
 * Who called an appointment off (rulebook, rule 8; decision 104). The record
 * and the rules are the same whichever it is.
 */
enum CancellationParty: string
{
    case Customer = 'customer';
    case Salesperson = 'salesperson';
    case Company = 'company';

    public function label(): string
    {
        return match ($this) {
            self::Customer => 'The customer',
            self::Salesperson => 'The salesperson',
            self::Company => 'The company',
        };
    }
}
