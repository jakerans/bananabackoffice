<?php

namespace App\Enums;

/**
 * Why a setter put a Lead back without calling it.
 *
 * A fixed list in code, not a managed list (decision 39). A skip is recorded
 * for managers and carries no penalty.
 */
enum QueueSkipReason: string
{
    case NoUsableNumber = 'no_usable_number';
    case NeedsManager = 'needs_manager';
    case CannotCallNow = 'cannot_call_now';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::NoUsableNumber => 'No usable phone number',
            self::NeedsManager => 'Needs a manager to look at it',
            self::CannotCallNow => 'I cannot make this call right now',
            self::Other => 'Other',
        };
    }

    /**
     * "Other" says nothing by itself, so it needs a short note.
     */
    public function needsNote(): bool
    {
        return $this === self::Other;
    }
}
