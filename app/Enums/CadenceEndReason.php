<?php

namespace App\Enums;

/**
 * Why a Lead left the call queue before its last attempt.
 *
 * Reaching the last attempt is not one of these: that is recorded as its own
 * "finished, no contact" event.
 */
enum CadenceEndReason: string
{
    case Connected = 'connected';
    case Disqualified = 'disqualified';
    case DeferredFollowUp = 'deferred_follow_up';

    /** An appointment was booked: the Lead is with Sales now. */
    case AppointmentBooked = 'appointment_booked';

    /** The salesperson it was released from took it back: the Lead is with Sales again (rulebook, rule 12). */
    case TakenBackBySales = 'taken_back_by_sales';

    /** The customer told the call center no, and the Lead was marked lost from the Rehash queue (decision 137). */
    case Lost = 'lost';

    /**
     * The state the cadence is left in.
     */
    public function state(): CadenceState
    {
        return match ($this) {
            self::Connected => CadenceState::Connected,
            self::Disqualified, self::Lost => CadenceState::Disqualified,
            self::DeferredFollowUp => CadenceState::DeferredFollowUp,
            self::AppointmentBooked, self::TakenBackBySales => CadenceState::AppointmentBooked,
        };
    }
}
