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

    public function label(): string
    {
        return match ($this) {
            self::NewFromSales => 'New from Sales',
        };
    }
}
