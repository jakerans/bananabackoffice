<?php

use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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

test('it refuses to create a second owner while an owner who can sign in exists', function () {
    User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->artisan('backoffice:create-owner')
        ->expectsOutputToContain('An Owner who can sign in already exists.')
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
});

test('it creates an owner when the only existing owner never accepted their invitation', function () {
    User::factory()->invited()->withRoleEverywhere(Role::Owner)->create();

    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Replacement Owner')
        ->expectsQuestion('Email address', 'replacement@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->assertSuccessful();

    expect(User::query()->where('email', 'replacement@example.com')->exists())->toBeTrue();
});

test('it rejects an email address that differs from an existing one only by capital letters', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'Taken@Example.COM')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->expectsOutputToContain('The email has already been taken.')
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

/*
 * Step 37a (docs/slice-37-location-setup-plan.md): given a name and an email,
 * the command asks nothing, so it runs from Forge's Commands tab, and emails
 * the Owner a link to choose their own password.
 */

test('given a name and an email it asks nothing and emails the owner a link to choose a password', function () {
    Notification::fake();

    $this->artisan('backoffice:create-owner', ['--name' => 'Olive Owner', '--email' => 'Olive@Example.com'])
        ->expectsOutputToContain('A link to choose a password is on its way')
        ->assertSuccessful();

    $owner = User::query()->sole();

    expect($owner->email)->toBe('olive@example.com')
        ->and($owner->holdsRole(Role::Owner))->toBeTrue()
        ->and($owner->invitationIsPending())->toBeTrue()
        // Nobody can sign in as them until the link is used.
        ->and(User::query()->usableOwners()->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('subject_id', $owner->id)->pluck('action')->all())->toEqual([AuditAction::StaffInvited, AuditAction::StaffAccessGranted]);

    $token = null;
    Notification::assertSentTo($owner, StaffInvitation::class, function (StaffInvitation $invitation) use (&$token): bool {
        $token = $invitation->token;

        return true;
    });

    $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => 'olive@example.com',
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    expect($owner->refresh()->isUsableOwner())->toBeTrue()
        ->and(Hash::check('a-long-passphrase', $owner->password))->toBeTrue();
});

test('run again for an owner who never used their link, it sends a fresh one', function () {
    Notification::fake();

    $this->artisan('backoffice:create-owner', ['--name' => 'Olive Owner', '--email' => 'olive@example.com'])->assertSuccessful();
    $this->artisan('backoffice:create-owner', ['--name' => 'Olive Owner', '--email' => 'olive@example.com'])
        ->expectsOutputToContain('A fresh link was sent to olive@example.com')
        ->assertSuccessful();

    expect(User::query()->count())->toBe(1);
    Notification::assertSentToTimes(User::query()->sole(), StaffInvitation::class, 2);
});

test('when the email cannot be sent, no owner is left behind', function () {
    useFailingMailer();

    $this->artisan('backoffice:create-owner', ['--name' => 'Olive Owner', '--email' => 'olive@example.com'])
        ->expectsOutputToContain('The email could not be sent, so no Owner was created.')
        ->assertFailed();

    expect(User::query()->count())->toBe(0)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('by email it still refuses a second owner, and an address already in use', function () {
    Notification::fake();
    User::factory()->withRoleEverywhere(Role::Owner)->create(['email' => 'first@example.com']);

    $this->artisan('backoffice:create-owner', ['--name' => 'Second Owner', '--email' => 'second@example.com'])
        ->expectsOutputToContain('An Owner who can sign in already exists.')
        ->assertFailed();

    $this->artisan('backoffice:create-owner', ['--name' => 'Same Address', '--email' => 'First@Example.com', '--force' => true])
        ->assertFailed();

    $this->artisan('backoffice:create-owner', ['--email' => 'nameless@example.com', '--force' => true])
        ->assertFailed();

    expect(User::query()->count())->toBe(1);
    Notification::assertNothingSent();
});
