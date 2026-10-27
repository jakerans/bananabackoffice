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
    case ManageLeadSalesLists = 'settings.lists.manage';
    case ManageContactCenterSettings = 'settings.contact_center.manage';
    case ViewLeads = 'leads.view';
    case ManageLeads = 'leads.manage';
    case ViewUnroutedLeads = 'leads.view_unrouted';
    case RouteLeads = 'leads.route';
    case TransferLeads = 'leads.transfer';
    case ReturnLeads = 'leads.return';
    case AssignLeads = 'leads.assign';
    case WaiveContactWait = 'leads.waive_contact_wait';
    case WriteNotes = 'notes.write';
    case RemoveNotes = 'notes.remove';
    case ViewAccounts = 'accounts.view';
    case ManageAccounts = 'accounts.manage';
    case ArchiveAccounts = 'accounts.archive';
    case WorkLeadQueue = 'leads.work_queue';
    case BookAppointments = 'appointments.book';
    case ManageAppointments = 'appointments.manage';
    case ViewProjects = 'projects.view';
    case ManageProjects = 'projects.manage';
    case ViewSubcontractors = 'subcontractors.view';
    case ManageSubcontractors = 'subcontractors.manage';
    case ViewPayouts = 'payouts.view';
    case ManageCompensation = 'compensation.manage';
    case MarketingSpendManage = 'marketing.spend.manage';
    case ViewAuditLog = 'settings.audit_log.view';

    /**
     * Company-level permissions only count when held through an all-locations grant.
     */
    public function isCompanyLevel(): bool
    {
        return match ($this) {
            self::CompanyOverviewAccess,
            self::ManageLocations,
            self::ManageUsers,
            self::ManageLeadSalesLists,
            self::ManageContactCenterSettings,
            self::ViewUnroutedLeads,
            self::RouteLeads,
            self::ArchiveAccounts,
            // Pay is one company payroll across every Location (slice 20; decision 172, question 4).
            self::ManageCompensation => true,
            // The audit log covers every Location, pay and impersonation (slice 29, decision 191).
            self::ViewAuditLog => true,
            default => false,
        };
    }
}
