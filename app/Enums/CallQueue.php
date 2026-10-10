<?php

namespace App\Enums;

/**
 * Which call queue a Lead is in. Each has its own "Next" button on the Call
 * Queue, and a Lead is in exactly one of them.
 */
enum CallQueue: string
{
    /** New Leads and their follow-up calls. */
    case Main = 'main';

    /**
     * Leads that came back from Sales with their last appointment cancelled:
     * a cancelled booking to try to rebook (decision 117).
     */
    case CancelledAppointments = 'cancelled_appointments';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Call queue',
            self::CancelledAppointments => 'Cancelled appointments',
        };
    }
}
