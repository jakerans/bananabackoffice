<?php

use App\Enums\Permission;
use App\Enums\Role;

test('a role contains exactly its approved permissions', function (Role $role, array $expected) {
    expect($role->permissions())->toEqualCanonicalizing($expected);
})->with([
    'owner' => [Role::Owner, [
        Permission::ViewProjects,
        Permission::ManageProjects,
        Permission::CompanyOverviewAccess,
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::FinanceAccess,
        Permission::ColorStudioAccess,
        Permission::ManageLocations,
        Permission::ManageUsers,
        Permission::ManageLeadSalesLists,
        Permission::ManageContactCenterSettings,
        Permission::WorkLeadQueue,
        Permission::BookAppointments,
        Permission::ManageAppointments,
        Permission::ViewLeads,
        Permission::ManageLeads,
        Permission::ViewUnroutedLeads,
        Permission::RouteLeads,
        Permission::TransferLeads,
        Permission::ReturnLeads,
        Permission::AssignLeads,
        Permission::WriteNotes,
        Permission::RemoveNotes,
        Permission::WaiveContactWait,
        Permission::ViewAccounts,
        Permission::ManageAccounts,
        Permission::ArchiveAccounts,
        Permission::ViewSubcontractors,
        Permission::ManageSubcontractors,
    ]],
    'leadership' => [Role::Leadership, [
        Permission::ViewProjects,
        Permission::ManageProjects,
        Permission::CompanyOverviewAccess,
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::ColorStudioAccess,
        Permission::ManageLeadSalesLists,
        Permission::ManageContactCenterSettings,
        Permission::WorkLeadQueue,
        Permission::BookAppointments,
        Permission::ManageAppointments,
        Permission::ViewLeads,
        Permission::ManageLeads,
        Permission::ViewUnroutedLeads,
        Permission::RouteLeads,
        Permission::TransferLeads,
        Permission::ReturnLeads,
        Permission::AssignLeads,
        Permission::WriteNotes,
        Permission::RemoveNotes,
        Permission::WaiveContactWait,
        Permission::ViewAccounts,
        Permission::ManageAccounts,
        Permission::ArchiveAccounts,
        Permission::ViewSubcontractors,
        Permission::ManageSubcontractors,
    ]],
    'finance' => [Role::Finance, [
        Permission::ViewProjects,
        Permission::FinanceAccess,
        Permission::ViewAccounts,
    ]],
    'location manager' => [Role::LocationManager, [
        Permission::ViewProjects,
        Permission::ManageProjects,
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::ColorStudioAccess,
        Permission::ViewLeads,
        Permission::ManageLeads,
        Permission::TransferLeads,
        Permission::ReturnLeads,
        Permission::AssignLeads,
        Permission::WorkLeadQueue,
        Permission::BookAppointments,
        Permission::ManageAppointments,
        Permission::WriteNotes,
        Permission::RemoveNotes,
        Permission::WaiveContactWait,
        Permission::ViewAccounts,
        Permission::ManageAccounts,
        Permission::ViewSubcontractors,
        Permission::ManageSubcontractors,
    ]],
    'call center agent' => [Role::CallCenterAgent, [
        Permission::MarketingAccess,
        Permission::ViewLeads,
        Permission::ManageLeads,
        Permission::ViewUnroutedLeads,
        Permission::RouteLeads,
        Permission::ReturnLeads,
        Permission::AssignLeads,
        Permission::WorkLeadQueue,
        Permission::BookAppointments,
        Permission::WriteNotes,
        Permission::ViewAccounts,
        Permission::ManageAccounts,
    ]],
    'sales consultant' => [Role::SalesConsultant, [
        Permission::ViewProjects,
        Permission::SalesAccess,
        Permission::ColorStudioAccess,
        Permission::ViewLeads,
        Permission::ManageLeads,
        Permission::WriteNotes,
        Permission::ViewAccounts,
        Permission::ManageAccounts,
    ]],
    'production manager' => [Role::ProductionManager, [
        Permission::ViewProjects,
        Permission::ProductionAccess,
        Permission::WriteNotes,
        Permission::ViewAccounts,
        Permission::ViewSubcontractors,
        Permission::ManageSubcontractors,
    ]],
]);

test('every role is covered by the permission matrix', function () {
    expect(Role::cases())->toHaveCount(7);
});

test('only the owner role must be granted for all locations', function (Role $role) {
    expect($role->requiresAllLocations())->toBe($role === Role::Owner);
})->with(Role::cases());

test('company overview and settings are company-level permissions', function (Permission $permission, bool $expected) {
    expect($permission->isCompanyLevel())->toBe($expected);
})->with([
    'company overview' => [Permission::CompanyOverviewAccess, true],
    'manage locations' => [Permission::ManageLocations, true],
    'manage users' => [Permission::ManageUsers, true],
    'manage lead and sales lists' => [Permission::ManageLeadSalesLists, true],
    'view unrouted leads' => [Permission::ViewUnroutedLeads, true],
    'route leads' => [Permission::RouteLeads, true],
    'transfer leads' => [Permission::TransferLeads, false],
    'return leads' => [Permission::ReturnLeads, false],
    'assign leads' => [Permission::AssignLeads, false],
    'waive the contact wait' => [Permission::WaiveContactWait, false],
    'write notes' => [Permission::WriteNotes, false],
    'remove notes' => [Permission::RemoveNotes, false],
    'view accounts' => [Permission::ViewAccounts, false],
    'manage accounts' => [Permission::ManageAccounts, false],
    'archive accounts' => [Permission::ArchiveAccounts, true],
    'manage contact center settings' => [Permission::ManageContactCenterSettings, true],
    'work the lead queue' => [Permission::WorkLeadQueue, false],
    'book appointments' => [Permission::BookAppointments, false],
    'manage appointments' => [Permission::ManageAppointments, false],
    'view leads' => [Permission::ViewLeads, false],
    'manage leads' => [Permission::ManageLeads, false],
    'marketing' => [Permission::MarketingAccess, false],
    'sales' => [Permission::SalesAccess, false],
    'production' => [Permission::ProductionAccess, false],
    'finance' => [Permission::FinanceAccess, false],
    'color studio' => [Permission::ColorStudioAccess, false],
]);

test('only owner and leadership manage the contact center settings', function (Role $role) {
    expect($role->allows(Permission::ManageContactCenterSettings))->toBe(in_array($role, [Role::Owner, Role::Leadership], true));
})->with(Role::cases());

test('the queue permission is held by exactly the four roles that work the queue', function (Role $role) {
    expect($role->allows(Permission::WorkLeadQueue))->toBe(in_array($role, [
        Role::Owner,
        Role::Leadership,
        Role::LocationManager,
        Role::CallCenterAgent,
    ], true));
})->with(Role::cases());

test('the appointment permissions are held by exactly the approved roles', function (Role $role) {
    expect($role->allows(Permission::BookAppointments))->toBe(in_array($role, [
        Role::Owner,
        Role::Leadership,
        Role::LocationManager,
        Role::CallCenterAgent,
    ], true));

    // A Call Center Agent books, and cannot undo or change a booking.
    expect($role->allows(Permission::ManageAppointments))->toBe(in_array($role, [
        Role::Owner,
        Role::Leadership,
        Role::LocationManager,
    ], true));
})->with(Role::cases());

test('the permission values are the approved identifiers', function () {
    expect(Permission::ManageContactCenterSettings->value)->toBe('settings.contact_center.manage');
    expect(Permission::WorkLeadQueue->value)->toBe('leads.work_queue');
    expect(Permission::BookAppointments->value)->toBe('appointments.book');
    expect(Permission::ManageAppointments->value)->toBe('appointments.manage');
});
