<?php

namespace App\Enums;

/**
 * Where a Project's colors stand (docs/slice-10-pre-production-plan.md, step
 * 10b). It is one of several separate states a Project has, never the
 * Project's one status (product-architecture.md, 2).
 */
enum ColorReadiness: string
{
    /** Nothing has been written down yet. */
    case NotStarted = 'not_started';

    /** Colors are being written, or approved colors are being changed. */
    case InProgress = 'in_progress';

    /** The colors are with the customer, who has not approved them yet. */
    case AwaitingApproval = 'awaiting_approval';

    /** The customer approved the colors as they are written. */
    case Approved = 'approved';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::AwaitingApproval => 'Waiting for the customer\'s approval',
            self::Approved => 'Approved',
        };
    }

    /**
     * The Flux badge color it is shown in.
     */
    public function color(): string
    {
        return match ($this) {
            self::NotStarted => 'zinc',
            self::InProgress => 'amber',
            self::AwaitingApproval => 'sky',
            self::Approved => 'emerald',
        };
    }
}
