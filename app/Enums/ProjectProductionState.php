<?php

namespace App\Enums;

/**
 * Where a Project is in Production's hands. It is one of several separate
 * states a Project has, never the Project's one status
 * (product-architecture.md, 2). Later slices add to it.
 */
enum ProjectProductionState: string
{
    /** Just sold: Production has not taken it yet. */
    case NewFromSales = 'new_from_sales';

    /** A Production Manager has it and is getting it ready. */
    case PreProduction = 'pre_production';

    /** Every package of its work has dates the customer confirmed (slice 11, step 11b). */
    case Scheduled = 'scheduled';

    /** Work has started on at least one package (slice 14, step 14a). */
    case InProgress = 'in_progress';

    /** Every package's work has finished (slice 14, step 14a). */
    case WorkComplete = 'work_complete';

    /** The customer signed off, 100% satisfied (slice 14, step 14b). */
    case SignedOff = 'signed_off';

    public function label(): string
    {
        return match ($this) {
            self::NewFromSales => 'New from Sales',
            self::PreProduction => 'Pre-production',
            self::Scheduled => 'Scheduled',
            self::InProgress => 'In progress',
            self::WorkComplete => 'Work complete',
            self::SignedOff => 'Signed off',
        };
    }
}
