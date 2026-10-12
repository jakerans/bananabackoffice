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

    /** Called off, with who cancelled and why. The record is kept (rulebook, rule 8). */
    case Cancelled = 'cancelled';

    /** Its time came and the visit happened (decisions 121 and 123). It has no result: only a proposal does (decision 124). */
    case Run = 'run';

    /** Its time came and the customer was not there. Treated as a cancellation by the customer (decision 125). */
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::BookedInError => 'Booked in error',
            self::Cancelled => 'Cancelled',
            self::Run => 'Run',
            self::NoShow => 'No-show',
        };
    }

    /**
     * Whether the appointment still holds its time and its salesperson.
     */
    public function stands(): bool
    {
        return $this === self::Booked;
    }

    /**
     * Whether the visit did not happen: it was cancelled, or the customer was
     * not there. A Lead whose last appointment fell through is one to book
     * again, and joins the Cancelled Appointments queue when it goes back to
     * the Contact Center (decisions 117 and 125).
     */
    public function fellThrough(): bool
    {
        return $this === self::Cancelled || $this === self::NoShow;
    }

    /**
     * Whether someone has said what happened once its time came: run, or
     * no-show.
     */
    public function isClosedOut(): bool
    {
        return $this === self::Run || $this === self::NoShow;
    }
}
