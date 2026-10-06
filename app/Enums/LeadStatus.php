<?php

namespace App\Enums;

/**
 * Where a Lead is in its own lifecycle.
 *
 * This is never a project status, and Disqualified is never a lost sale: a Lead
 * is disqualified, a Project is lost.
 */
enum LeadStatus: string
{
    /** Received; nobody has worked it yet. */
    case New = 'new';

    /** Someone is in contact or trying to be. */
    case Working = 'working';

    /** A legitimate opportunity, with what is needed to create its Project. */
    case Qualified = 'qualified';

    /** Still a viable opportunity, to be followed up on a set date. */
    case Nurture = 'nurture';

    /** Did not become a legitimate opportunity, for a recorded reason. */
    case Disqualified = 'disqualified';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Working => 'Working',
            self::Qualified => 'Qualified',
            self::Nurture => 'Nurture',
            self::Disqualified => 'Disqualified',
        };
    }

    /**
     * Whether a Lead in this status still needs someone's attention.
     *
     * Everything but Disqualified does. A Qualified Lead stays active until
     * Projects exist to carry it on.
     */
    public function isActive(): bool
    {
        return $this !== self::Disqualified;
    }

    /**
     * The statuses of Leads that still need someone's attention.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => $status->isActive()));
    }
}
