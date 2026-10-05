<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;

test('a permission held at one location does not apply at another', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();

    expect($user->hasPermissionAt(Permission::SalesAccess, $northside))->toBeTrue();
    expect($user->hasPermissionAt(Permission::SalesAccess, $lakeview))->toBeFalse();
});

test('a company-wide grant applies at every location', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect($user->hasPermissionAt(Permission::MarketingAccess, $northside))->toBeTrue();
    expect($user->hasPermissionAt(Permission::MarketingAccess, $lakeview))->toBeTrue();
    expect($user->hasPermissionAcrossAllLocations(Permission::MarketingAccess))->toBeTrue();
});

test('two grants do not combine into access that neither one gives', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->withRoleEverywhere(Role::CallCenterAgent)
        ->create();

    expect($user->hasPermissionAt(Permission::SalesAccess, $northside))->toBeTrue();
    expect($user->hasPermissionAt(Permission::MarketingAccess, $lakeview))->toBeTrue();
    expect($user->hasPermissionAt(Permission::SalesAccess, $lakeview))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations(Permission::SalesAccess))->toBeFalse();
});

test('a company-wide call center grant gives no other access', function (Permission $permission) {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect($user->hasPermissionAt($permission, $location))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations($permission))->toBeFalse();
})->with([
    'finance' => Permission::FinanceAccess,
    'sales' => Permission::SalesAccess,
    'production' => Permission::ProductionAccess,
    'color studio' => Permission::ColorStudioAccess,
    'company overview' => Permission::CompanyOverviewAccess,
    'manage locations' => Permission::ManageLocations,
    'manage users' => Permission::ManageUsers,
]);

test('a company-wide leadership grant gives no finance access', function () {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    expect($user->hasPermissionAt(Permission::FinanceAccess, $location))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations(Permission::FinanceAccess))->toBeFalse();
});

test('a staff member with no grants has no permissions', function (Permission $permission) {
    $location = Location::factory()->create();

    $user = User::factory()->create();

    expect($user->hasPermissionAt($permission, $location))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations($permission))->toBeFalse();
})->with(Permission::cases());

test('a company-level permission does not count when granted for a single location', function () {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleAt(Role::Leadership, $location)->create();

    expect($user->hasPermissionAt(Permission::MarketingAccess, $location))->toBeTrue();
    expect($user->hasPermissionAt(Permission::CompanyOverviewAccess, $location))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess))->toBeFalse();
});

test('a company-level permission counts when granted for all locations', function () {
    $user = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    expect($user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess))->toBeTrue();
});

test('a deactivated staff member has no permissions', function () {
    $location = Location::factory()->create();

    $user = User::factory()->inactive()->withRoleEverywhere(Role::Owner)->create();

    expect($user->hasPermissionAt(Permission::SalesAccess, $location))->toBeFalse();
    expect($user->hasPermissionAcrossAllLocations(Permission::ManageUsers))->toBeFalse();
    expect($user->canViewAllLocations())->toBeFalse();
    expect($user->authorizedLocations())->toBeEmpty();
});

test('a deactivated location is covered by no grant', function () {
    $closed = Location::factory()->inactive()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $closed)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    expect($manager->hasPermissionAt(Permission::SalesAccess, $closed))->toBeFalse();
    expect($owner->hasPermissionAt(Permission::SalesAccess, $closed))->toBeFalse();
});

test('only a company-wide grant allows the all locations view', function () {
    $location = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $location)->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect($manager->canViewAllLocations())->toBeFalse();
    expect($agent->canViewAllLocations())->toBeTrue();
});

test('authorized locations are the active locations covered by a grant in name order', function () {
    $riverside = Location::factory()->create(['name' => 'Riverside']);
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $closed = Location::factory()->inactive()->create(['name' => 'Closed']);
    Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $closed)
        ->create();

    expect($user->authorizedLocations()->pluck('name')->all())->toBe(['Lakeview', 'Riverside']);
});

test('a company-wide grant authorizes every active location', function () {
    Location::factory()->create(['name' => 'Riverside']);
    Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->inactive()->create(['name' => 'Closed']);

    $user = User::factory()->withRoleEverywhere(Role::Finance)->create();

    expect($user->authorizedLocations()->pluck('name')->all())->toBe(['Lakeview', 'Riverside']);
});

test('an email address is stored trimmed and in lowercase however it is written', function () {
    $user = User::factory()->create(['email' => '  Jordan.Smith@Example.COM ']);

    expect($user->email)->toBe('jordan.smith@example.com');
    expect($user->refresh()->email)->toBe('jordan.smith@example.com');

    $user->update(['email' => 'NEW@Example.com']);

    expect($user->refresh()->email)->toBe('new@example.com');
});

test('only an owner who can sign in and manage the system counts as a usable owner', function (Closure $make, bool $usable) {
    $person = $make();

    expect($person->isUsableOwner())->toBe($usable);
    expect(User::query()->usableOwners()->whereKey($person->id)->exists())->toBe($usable);
})->with([
    'an active owner who has confirmed their email' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'an owner who accepted their invitation' => [
        fn () => User::factory()->withRoleEverywhere(Role::Owner)->create(['invited_at' => now()->subDay(), 'invitation_accepted_at' => now()]),
        true,
    ],
    'an owner who never accepted their invitation' => [fn () => User::factory()->invited()->withRoleEverywhere(Role::Owner)->create(), false],
    'a deactivated owner' => [fn () => User::factory()->inactive()->withRoleEverywhere(Role::Owner)->create(), false],
    'an owner who has not confirmed their email' => [fn () => User::factory()->unverified()->withRoleEverywhere(Role::Owner)->create(), false],
    'an active person who is not an owner' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create(), false],
    'a person with no grants' => [fn () => User::factory()->create(), false],
]);

test('holding a role is determined by the access grants', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $agent = User::factory()->has(AccessGrant::factory()->role(Role::CallCenterAgent)->everywhere())->create();

    expect($owner->holdsRole(Role::Owner))->toBeTrue();
    expect($agent->holdsRole(Role::Owner))->toBeFalse();
});
