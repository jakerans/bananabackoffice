<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;

test('with nothing remembered a staff member lands on their first authorized location', function () {
    Location::factory()->create(['name' => 'Riverside']);
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $user = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $context = app(CurrentLocation::class)->context($user);

    expect($context->isAllLocations)->toBeFalse();
    expect($context->is($lakeview))->toBeTrue();
});

test('a staff member with no authorized locations has no location context', function () {
    Location::factory()->create();

    $user = User::factory()->create();

    expect(app(CurrentLocation::class)->context($user))->toBeNull();
});

test('an owner has no location context before any location exists', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    expect(app(CurrentLocation::class)->context($owner))->toBeNull();
});

test('a remembered location is used while it remains authorized', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->create();

    session([CurrentLocation::SESSION_KEY => $riverside->id]);

    expect(app(CurrentLocation::class)->context($user)->is($riverside))->toBeTrue();
});

test('a remembered location the staff member is not authorized for is discarded', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    session([CurrentLocation::SESSION_KEY => $riverside->id]);

    expect(app(CurrentLocation::class)->context($user)->is($lakeview))->toBeTrue();
    expect(session(CurrentLocation::SESSION_KEY))->toBe($lakeview->id);
});

test('a remembered location that has been deactivated is discarded', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $closed = Location::factory()->inactive()->create(['name' => 'Closed']);

    $user = User::factory()->withRoleEverywhere(Role::Owner)->create();

    session([CurrentLocation::SESSION_KEY => $closed->id]);

    expect(app(CurrentLocation::class)->context($user)->is($lakeview))->toBeTrue();
});

test('a remembered all locations view is kept for a company-wide grant', function () {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    expect(app(CurrentLocation::class)->context($user)->isAllLocations)->toBeTrue();
});

test('a remembered all locations view is discarded without a company-wide grant', function () {
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    $context = app(CurrentLocation::class)->context($user);

    expect($context->isAllLocations)->toBeFalse();
    expect($context->is($lakeview))->toBeTrue();
    expect(session(CurrentLocation::SESSION_KEY))->toBe($lakeview->id);
});

test('a tampered session value never resolves to all locations', function (mixed $value) {
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::Owner)->create();

    session([CurrentLocation::SESSION_KEY => $value]);

    $context = app(CurrentLocation::class)->context($user);

    expect($context->isAllLocations)->toBeFalse();
    expect($context->is($lakeview))->toBeTrue();
})->with([
    'null' => [null],
    'empty string' => [''],
    'zero' => [0],
    'boolean true' => [true],
    'wildcard' => ['*'],
    'array' => [['all']],
]);
