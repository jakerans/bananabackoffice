<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\LocalDemoSeeder;

test('the demo seeder refuses to run outside local development even when run by name', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    $run = fn () => app(LocalDemoSeeder::class)->run();

    expect($run)->toThrow(LogicException::class, 'only runs in local development');

    expect(User::query()->count())->toBe(0);
    expect(Location::query()->count())->toBe(0);
})->with(['production', 'staging']);

test('the default seeder does nothing outside local development', function () {
    app()->detectEnvironment(fn () => 'production');

    app(DatabaseSeeder::class)->setContainer(app())->run();

    expect(User::query()->count())->toBe(0);
});

test('the demo seeder creates fictitious locations and one account per role', function () {
    app(LocalDemoSeeder::class)->run();

    expect(Location::query()->orderBy('name')->pluck('name')->all())->toBe(['Lakeview', 'Northside', 'Riverside']);

    $owner = User::query()->where('email', 'owner@example.com')->sole();

    expect($owner->isUsableOwner())->toBeTrue();

    $rolesInUse = User::query()->with('accessGrants')->get()
        ->flatMap(fn (User $user) => $user->accessGrants->pluck('role'))
        ->unique()
        ->values();

    expect($rolesInUse)->toHaveCount(count(Role::cases()));
});
