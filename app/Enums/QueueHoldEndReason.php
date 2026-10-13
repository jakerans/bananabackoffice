<?php

namespace App\Enums;

/**
 * Why a hold was ended before its time ran out, so the person who held the
 * Lead can be told.
 */
enum QueueHoldEndReason: string
{
    case Disqualified = 'disqualified';
    case DeferredFollowUp = 'deferred_follow_up';
    case AppointmentBooked = 'appointment_booked';

    /** The salesperson it was released from took it back. */
    case TakenBackBySales = 'taken_back_by_sales';

    /** The customer said no and the Lead was marked lost from the Rehash queue. */
    case MarkedLost = 'marked_lost';

    /** Routed, transferred or returned to central. */
    case Moved = 'moved';

    /** The holder may no longer work the Lead where it is. */
    case AccessLost = 'access_lost';

    /** The holder logged an attempt on the Lead they hold. */
    case AttemptLogged = 'attempt_logged';

    /** Someone else logged an attempt on it. */
    case AttemptLoggedByOther = 'attempt_logged_by_other';

    case OutsideCallingHours = 'outside_calling_hours';

    /** The holder skipped this Lead a moment ago and their cooldown is still running. */
    case SkipCooldown = 'skip_cooldown';

    /**
     * How the history words it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Disqualified => 'The lead was disqualified',
            self::DeferredFollowUp => 'A follow-up was scheduled',
            self::AppointmentBooked => 'An appointment was booked',
            self::TakenBackBySales => 'Its salesperson took the lead back',
            self::MarkedLost => 'The lead was marked lost',
            self::Moved => 'The lead was moved to another location',
            self::AccessLost => 'The setter may no longer work this lead',
            self::AttemptLogged => 'The setter logged a call',
            self::AttemptLoggedByOther => 'Someone else logged a call',
            self::OutsideCallingHours => 'Calling hours closed for this lead',
            self::SkipCooldown => 'The setter had skipped this lead a moment ago',
        };
    }

    /**
     * What the setter who held the Lead is told when the hold is gone. It says
     * why and nothing about the Lead itself.
     */
    public function notice(): string
    {
        return match ($this) {
            self::Disqualified => 'This lead was disqualified, so it is no longer yours to call.',
            self::DeferredFollowUp => 'A follow-up was scheduled for this lead, so it left the queue.',
            self::AppointmentBooked => 'An appointment was booked for this lead, so it left the queue.',
            self::TakenBackBySales => 'The salesperson this lead came from took it back, so it left the queue.',
            self::MarkedLost => 'This lead was marked lost, so it left the queue.',
            self::Moved => 'This lead was moved to another location.',
            self::AccessLost => 'Your access to this lead changed.',
            self::AttemptLogged => 'Your call was logged.',
            self::AttemptLoggedByOther => 'Someone else logged a call on this lead.',
            self::OutsideCallingHours => 'Calling hours have closed for this lead.',
            self::SkipCooldown => 'You skipped this lead a moment ago, so it is not offered to you yet.',
        };
    }
}
