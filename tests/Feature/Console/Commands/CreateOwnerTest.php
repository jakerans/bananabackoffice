<?php

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

test('it creates a verified owner with company-wide access', function () {
    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'Olive@Example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->assertSuccessful();

    $owner = User::query()->sole();

    expect($owner->name)->toBe('Olive Owner');
    expect($owner->email)->toBe('olive@example.com');
    expect($owner->email_verified_at)->not->toBeNull();
    expect($owner->is_active)->toBeTrue();
    expect(Hash::check('a-long-passphrase', $owner->password))->toBeTrue();

    $grant = $owner->accessGrants()->sole();

    expect($grant->role)->toBe(Role::Owner);
    expect($grant->scope)->toBe(GrantScope::AllLocations);
    expect($grant->location_id)->toBeNull();
});

test('it refuses to create a second owner while an active owner exists', function () {
    User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->artisan('backoffice:create-owner')
        ->expectsOutputToContain('An active Owner already exists.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

test('it creates another owner when forced', function () {
    User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->artisan('backoffice:create-owner', ['--force' => true])
        ->expectsQuestion('Full name', 'Second Owner')
        ->expectsQuestion('Email address', 'second@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->assertSuccessful();

    expect(User::query()->where('email', 'second@example.com')->exists())->toBeTrue();
});

test('it creates an owner when the only existing owner is deactivated', function () {
    User::factory()->inactive()->withRoleEverywhere(Role::Owner)->create();

    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Replacement Owner')
        ->expectsQuestion('Email address', 'replacement@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->assertSuccessful();

    expect(User::query()->where('email', 'replacement@example.com')->exists())->toBeTrue();
});

test('it rejects a password that is too short', function () {
    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'olive@example.com')
        ->expectsQuestion('Password', 'short')
        ->expectsQuestion('Confirm password', 'short')
        ->expectsOutputToContain('The password field must be at least 8 characters.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('it rejects a password confirmation that does not match', function () {
    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'olive@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-different-passphrase')
        ->expectsOutputToContain('The password field confirmation does not match.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0);
});

test('it rejects an email address that is already in use', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'taken@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->expectsOutputToContain('The email has already been taken.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});
