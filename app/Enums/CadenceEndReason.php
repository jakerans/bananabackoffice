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

    /**
     * The state the cadence is left in.
     */
    public function state(): CadenceState
    {
        return match ($this) {
            self::Connected => CadenceState::Connected,
            self::Disqualified => CadenceState::Disqualified,
            self::DeferredFollowUp => CadenceState::DeferredFollowUp,
        };
    }
}
