<?php

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

test('a location-scoped grant without a location cannot be saved', function () {
    $user = User::factory()->create();

    $save = fn () => AccessGrant::query()->create([
        'user_id' => $user->id,
        'role' => Role::SalesConsultant,
        'scope' => GrantScope::Location,
        'location_id' => null,
    ]);

    expect($save)->toThrow(InvalidArgumentException::class, 'A location-scoped access grant must name a location.');
    expect(AccessGrant::query()->count())->toBe(0);
});

test('an all-locations grant that names a location cannot be saved', function () {
    $user = User::factory()->create();
    $location = Location::factory()->create();

    $save = fn () => AccessGrant::query()->create([
        'user_id' => $user->id,
        'role' => Role::CallCenterAgent,
        'scope' => GrantScope::AllLocations,
        'location_id' => $location->id,
    ]);

    expect($save)->toThrow(InvalidArgumentException::class, 'An all-locations access grant must not name a location.');
    expect(AccessGrant::query()->count())->toBe(0);
});

test('the owner role cannot be granted for a single location', function () {
    $user = User::factory()->create();
    $location = Location::factory()->create();

    $save = fn () => AccessGrant::query()->create([
        'user_id' => $user->id,
        'role' => Role::Owner,
        'scope' => GrantScope::Location,
        'location_id' => $location->id,
    ]);

    expect($save)->toThrow(InvalidArgumentException::class, 'The Owner role can only be granted for all locations.');
    expect(AccessGrant::query()->count())->toBe(0);
});

test('a location-scoped grant covers only its own location', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $grant = AccessGrant::factory()->at($northside)->create();

    expect($grant->covers($northside))->toBeTrue();
    expect($grant->covers($lakeview))->toBeFalse();
});

test('the database refuses a second identical all-locations grant', function () {
    $user = User::factory()->create();

    $grant = fn () => AccessGrant::query()->create([
        'user_id' => $user->id,
        'role' => Role::Finance,
        'scope' => GrantScope::AllLocations,
    ]);

    $grant();

    expect($grant)->toThrow(UniqueConstraintViolationException::class);
    expect($user->accessGrants()->count())->toBe(1);
});

test('the database refuses a second identical grant at the same location', function () {
    $user = User::factory()->create();
    $location = Location::factory()->create();

    $grant = fn () => AccessGrant::query()->create([
        'user_id' => $user->id,
        'role' => Role::SalesConsultant,
        'scope' => GrantScope::Location,
        'location_id' => $location->id,
    ]);

    $grant();

    expect($grant)->toThrow(UniqueConstraintViolationException::class);
    expect($user->accessGrants()->count())->toBe(1);
});

test('one person can hold the same role company-wide, at several locations, and alongside other roles', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $user = User::factory()
        ->withRoleEverywhere(Role::SalesConsultant)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $northside)
        ->withRoleEverywhere(Role::Finance)
        ->create();

    expect($user->accessGrants()->count())->toBe(5);
});

test('two people can hold the same grant', function () {
    $location = Location::factory()->create();

    User::factory()->count(2)->withRoleAt(Role::SalesConsultant, $location)->withRoleEverywhere(Role::Finance)->create();

    expect(AccessGrant::query()->count())->toBe(4);
});

test('an all-locations grant covers every location', function () {
    $northside = Location::factory()->create();
    $lakeview = Location::factory()->create();

    $grant = AccessGrant::factory()->role(Role::CallCenterAgent)->everywhere()->create();

    expect($grant->covers($northside))->toBeTrue();
    expect($grant->covers($lakeview))->toBeTrue();
});
