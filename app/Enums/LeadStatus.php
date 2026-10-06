<?php

namespace App\Enums;

/**
 * Where a Lead is in its own lifecycle.
 *
 * This is never a project status. Qualified, Nurture and Disqualified are added
 * in the step that builds qualification.
 */
enum LeadStatus: string
{
    case New = 'new';
    case Working = 'working';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Working => 'Working',
        };
    }
}
