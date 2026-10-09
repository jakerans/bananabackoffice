<?php

namespace App\Enums;

/**
 * Where a Lead stands in the phone cadence.
 *
 * This is not a Lead status. "Finished, no contact" lives here and nowhere
 * else: no lifecycle status, history row or revision is touched by it.
 */
enum CadenceState: string
{
    /** The Lead has an attempt due, now or later. */
    case InQueue = 'in_queue';

    /** A real two-way connection was logged; a person decides the next step. */
    case Connected = 'connected';

    case Disqualified = 'disqualified';

    /** A manual Deferred Follow-up was scheduled; that workflow has taken over. */
    case DeferredFollowUp = 'deferred_follow_up';

    /** Every attempt in the cadence was made without a connection. */
    case FinishedNoContact = 'finished_no_contact';

    public function label(): string
    {
        return match ($this) {
            self::InQueue => 'In the call queue',
            self::Connected => 'Connected',
            self::Disqualified => 'Disqualified',
            self::DeferredFollowUp => 'Deferred Follow-up',
            self::FinishedNoContact => 'Cadence finished, no contact',
        };
    }
}
