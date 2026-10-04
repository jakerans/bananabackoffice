<?php

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Illuminate\Support\Facades\Gate;

test('a location-level gate answers for the current location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $riverside)
        ->create();

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    expect(Gate::forUser($user)->allows(Permission::SalesAccess))->toBeTrue();
    expect(Gate::forUser($user)->allows(Permission::ProductionAccess))->toBeFalse();
});

test('a location-level gate changes when the current location changes', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $riverside)
        ->create();

    app(CurrentLocation::class)->switchTo($user, $riverside);

    expect(Gate::forUser($user)->allows(Permission::SalesAccess))->toBeFalse();
    expect(Gate::forUser($user)->allows(Permission::ProductionAccess))->toBeTrue();
});

test('in the all locations view a single-location grant does not apply', function () {
    $lakeview = Location::factory()->create();

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleEverywhere(Role::CallCenterAgent)
        ->create();

    app(CurrentLocation::class)->switchToAllLocations($user);

    expect(Gate::forUser($user)->allows(Permission::MarketingAccess))->toBeTrue();
    expect(Gate::forUser($user)->allows(Permission::SalesAccess))->toBeFalse();
});

test('a location-level gate is denied when there is no location context', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    expect(Gate::forUser($owner)->allows(Permission::SalesAccess))->toBeFalse();
});

test('a company-level gate does not depend on the current location', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    expect(Gate::forUser($owner)->allows(Permission::ManageUsers))->toBeTrue();
    expect(Gate::forUser($manager)->allows(Permission::ManageUsers))->toBeFalse();
});

test('a gate checked with a location answers for that location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    expect(Gate::forUser($user)->allows(Permission::SalesAccess, $lakeview))->toBeTrue();
    expect(Gate::forUser($user)->allows(Permission::SalesAccess, $riverside))->toBeFalse();
});
