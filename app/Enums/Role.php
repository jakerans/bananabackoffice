<?php

namespace App\Enums;

/**
 * A named bundle of permissions. Adding a role means adding a case here, its
 * permissions below, and its line in docs/permission-model.md.
 */
enum Role: string
{
    case Owner = 'owner';
    case Leadership = 'leadership';
    case Finance = 'finance';
    case LocationManager = 'location_manager';
    case CallCenterAgent = 'call_center_agent';
    case SalesConsultant = 'sales_consultant';
    case ProductionManager = 'production_manager';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Owner',
            self::Leadership => 'Leadership',
            self::Finance => 'Finance',
            self::LocationManager => 'Location Manager',
            self::CallCenterAgent => 'Call Center Agent',
            self::SalesConsultant => 'Sales Consultant',
            self::ProductionManager => 'Production Manager',
        };
    }

    /**
     * The permissions this role contains. Where they apply depends on each grant.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Leadership => [
                Permission::CompanyOverviewAccess,
                Permission::MarketingAccess,
                Permission::SalesAccess,
                Permission::ProductionAccess,
                Permission::ColorStudioAccess,
                // The one Settings page Leadership may use. It opens no other.
                Permission::ManageLeadSalesLists,
                Permission::ViewLeads,
                Permission::ManageLeads,
                Permission::ViewUnroutedLeads,
                Permission::RouteLeads,
                Permission::TransferLeads,
                Permission::ReturnLeads,
                Permission::AssignLeads,
            ],
            self::Finance => [
                Permission::FinanceAccess,
            ],
            self::LocationManager => [
                Permission::MarketingAccess,
                Permission::SalesAccess,
                Permission::ProductionAccess,
                Permission::ColorStudioAccess,
                Permission::ViewLeads,
                Permission::ManageLeads,
                // A manager may move a Lead between two locations they both manage,
                // return one to central, and decide who works it.
                Permission::TransferLeads,
                Permission::ReturnLeads,
                Permission::AssignLeads,
            ],
            self::CallCenterAgent => [
                Permission::MarketingAccess,
                Permission::ViewLeads,
                Permission::ManageLeads,
                Permission::ViewUnroutedLeads,
                Permission::RouteLeads,
                Permission::ReturnLeads,
                Permission::AssignLeads,
            ],
            self::SalesConsultant => [
                Permission::SalesAccess,
                Permission::ColorStudioAccess,
                Permission::ViewLeads,
                Permission::ManageLeads,
            ],
            self::ProductionManager => [
                Permission::ProductionAccess,
            ],
        };
    }

    public function allows(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Roles that may only be granted company-wide, never for a single location.
     */
    public function requiresAllLocations(): bool
    {
        return $this === self::Owner;
    }
}
