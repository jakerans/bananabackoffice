<?php

namespace App\Enums;

/**
 * What kind of change an appointment's history row records.
 */
enum AppointmentChangeType: string
{
    /** A new time, with the same salesperson (rulebook, rule 6). */
    case Rescheduled = 'rescheduled';

    /** A manager gave it to another salesperson (rule 7). */
    case Moved = 'moved';

    public function label(): string
    {
        return match ($this) {
            self::Rescheduled => 'Rescheduled',
            self::Moved => 'Moved to another salesperson',
        };
    }
}
