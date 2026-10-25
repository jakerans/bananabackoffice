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
                // The Contact Center settings page, also a company-wide Settings page of its own.
                Permission::ManageContactCenterSettings,
                Permission::ViewLeads,
                Permission::ManageLeads,
                Permission::ViewUnroutedLeads,
                Permission::RouteLeads,
                Permission::TransferLeads,
                Permission::ReturnLeads,
                Permission::AssignLeads,
                Permission::WaiveContactWait,
                Permission::WorkLeadQueue,
                Permission::BookAppointments,
                Permission::ManageAppointments,
                Permission::ViewProjects,
                Permission::ManageProjects,
                // Subcontractors are seen by Production and managers only (decision 148, question 9).
                Permission::ViewSubcontractors,
                Permission::ManageSubcontractors,
                // Subcontractor payouts are finance-sensitive (decision 151, question 1).
                Permission::ViewPayouts,
                Permission::WriteNotes,
                Permission::RemoveNotes,
                Permission::ViewAccounts,
                Permission::ManageAccounts,
                // Archiving shared customer data counts only through an all-locations grant.
                Permission::ArchiveAccounts,
            ],
            self::Finance => [
                Permission::FinanceAccess,
                // Finance sees what was sold and for how much (decision 130), and what Banana will pay for it (decision 151).
                Permission::ViewProjects,
                Permission::ViewAccounts,
                Permission::ViewPayouts,
                // Finance sees and manages everyone's pay, through an all-locations grant (decision 172, question 4).
                Permission::ManageCompensation,
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
                // Managers, not the Call Center, may let a lead be closed as "unable to contact" early.
                Permission::WaiveContactWait,
                Permission::WorkLeadQueue,
                // A manager books appointments, and is the one who may undo one that was booked in error.
                Permission::BookAppointments,
                Permission::ManageAppointments,
                // A manager sees the Projects at their Location and may edit one (decision 130).
                Permission::ViewProjects,
                Permission::ManageProjects,
                Permission::ViewSubcontractors,
                Permission::ManageSubcontractors,
                Permission::ViewPayouts,
                Permission::WriteNotes,
                // A manager may remove anyone's note from view. Everyone else only their own.
                Permission::RemoveNotes,
                Permission::ViewAccounts,
                Permission::ManageAccounts,
            ],
            self::CallCenterAgent => [
                Permission::MarketingAccess,
                Permission::ViewLeads,
                Permission::ManageLeads,
                Permission::ViewUnroutedLeads,
                Permission::RouteLeads,
                Permission::ReturnLeads,
                Permission::AssignLeads,
                Permission::WorkLeadQueue,
                // Booking hands the Lead to Sales. An agent cannot undo or change a booking.
                Permission::BookAppointments,
                Permission::WriteNotes,
                Permission::ViewAccounts,
                Permission::ManageAccounts,
            ],
            self::SalesConsultant => [
                Permission::SalesAccess,
                // A Sales Consultant sees the Projects at their Location (decision 130).
                Permission::ViewProjects,
                Permission::ColorStudioAccess,
                Permission::ViewLeads,
                Permission::ManageLeads,
                Permission::WriteNotes,
                Permission::ViewAccounts,
                Permission::ManageAccounts,
            ],
            self::ProductionManager => [
                Permission::ProductionAccess,
                // A Production Manager sees Projects, and still cannot see Leads.
                Permission::ViewProjects,
                // They recruit, onboard and keep the roster (product-architecture.md, 7).
                Permission::ViewSubcontractors,
                Permission::ManageSubcontractors,
                Permission::ViewPayouts,
                Permission::WriteNotes,
                Permission::ViewAccounts,
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
