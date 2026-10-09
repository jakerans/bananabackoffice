<?php

namespace App\Enums;

/**
 * What happened to a Lead in the call queue.
 *
 * A stored value never changes once events exist that use it.
 */
enum QueueEventType: string
{
    case Entered = 'entered';
    case Claimed = 'claimed';
    case HoldExpired = 'hold_expired';
    case HoldEnded = 'hold_ended';
    case Skipped = 'skipped';
    case AttemptCounted = 'attempt_counted';
    case Ended = 'ended';
    case FinishedNoContact = 'finished_no_contact';
    case ReturnedToQueue = 'returned_to_queue';

    public function label(): string
    {
        return match ($this) {
            self::Entered => 'Entered the call queue',
            self::Claimed => 'Given to a setter',
            self::HoldExpired => 'Hold ran out',
            self::HoldEnded => 'Hold ended',
            self::Skipped => 'Skipped',
            self::AttemptCounted => 'Attempt counted',
            self::Ended => 'Left the call queue',
            self::FinishedNoContact => 'Cadence finished, no contact',
            self::ReturnedToQueue => 'Put back in the call queue',
        };
    }
}
