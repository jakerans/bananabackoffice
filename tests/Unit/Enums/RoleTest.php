<?php

use App\Enums\Permission;
use App\Enums\Role;

test('a role contains exactly its approved permissions', function (Role $role, array $expected) {
    expect($role->permissions())->toEqualCanonicalizing($expected);
})->with([
    'owner' => [Role::Owner, [
        Permission::CompanyOverviewAccess,
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::FinanceAccess,
        Permission::ColorStudioAccess,
        Permission::ManageLocations,
        Permission::ManageUsers,
        Permission::ManageLeadSalesLists,
    ]],
    'leadership' => [Role::Leadership, [
        Permission::CompanyOverviewAccess,
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::ColorStudioAccess,
        Permission::ManageLeadSalesLists,
    ]],
    'finance' => [Role::Finance, [
        Permission::FinanceAccess,
    ]],
    'location manager' => [Role::LocationManager, [
        Permission::MarketingAccess,
        Permission::SalesAccess,
        Permission::ProductionAccess,
        Permission::ColorStudioAccess,
    ]],
    'call center agent' => [Role::CallCenterAgent, [
        Permission::MarketingAccess,
    ]],
    'sales consultant' => [Role::SalesConsultant, [
        Permission::SalesAccess,
        Permission::ColorStudioAccess,
    ]],
    'production manager' => [Role::ProductionManager, [
        Permission::ProductionAccess,
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
    'marketing' => [Permission::MarketingAccess, false],
    'sales' => [Permission::SalesAccess, false],
    'production' => [Permission::ProductionAccess, false],
    'finance' => [Permission::FinanceAccess, false],
    'color studio' => [Permission::ColorStudioAccess, false],
]);
