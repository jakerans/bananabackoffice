<?php

namespace App\Enums;

/**
 * Where an appointment stands. This is not a Lead status and not an outcome:
 * what happened at the appointment belongs to the later after-action report.
 */
enum AppointmentState: string
{
    /** Saved and standing: the salesperson is expected to be there. */
    case Booked = 'booked';

    /** A manager undid the booking; the Lead was handed back as it was. */
    case BookedInError = 'booked_in_error';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::BookedInError => 'Booked in error',
        };
    }

    /**
     * Whether the appointment still holds its time and its salesperson.
     */
    public function stands(): bool
    {
        return $this === self::Booked;
    }
}
