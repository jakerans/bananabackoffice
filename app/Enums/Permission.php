<?php

namespace App\Enums;

/**
 * One thing a staff member can do. The value is the stable identifier used by
 * gates, routes and the navigation registry. See docs/permission-model.md.
 */
enum Permission: string
{
    case CompanyOverviewAccess = 'company_overview.access';
    case MarketingAccess = 'marketing.access';
    case SalesAccess = 'sales.access';
    case ProductionAccess = 'production.access';
    case FinanceAccess = 'finance.access';
    case ColorStudioAccess = 'color_studio.access';
    case ManageLocations = 'settings.locations.manage';
    case ManageUsers = 'settings.users.manage';

    /**
     * Company-level permissions only count when held through an all-locations grant.
     */
    public function isCompanyLevel(): bool
    {
        return match ($this) {
            self::CompanyOverviewAccess,
            self::ManageLocations,
            self::ManageUsers => true,
            default => false,
        };
    }
}
