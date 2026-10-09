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
}
