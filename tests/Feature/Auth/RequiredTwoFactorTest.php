<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Laravel\Fortify\Features;

/*
 * Required two-factor sign-in, step 30a (docs/slice-30-production-readiness-plan.md;
 * decision 193, question 3): Owner, Leadership and Finance set it up before
 * anything else, and cannot switch it off.
 */

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    config(['auth.two_factor_required' => true]);
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
});

test('Owner, Leadership and Finance are sent to set up two-factor sign-in before anything else', function (Role $role) {
    $staff = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($staff)->get(route('dashboard'))->assertRedirect(route('security.edit'));
    $this->actingAs($staff)->get(route('settings.users'))->assertRedirect(route('security.edit'));

    $this->actingAs($staff)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()
        ->assertSee('Your role must use two-factor sign-in')
        ->assertSee('Enable 2FA');
})->with([Role::Owner, Role::Leadership, Role::Finance]);

test('every other role is only offered it', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();

    $this->actingAs($manager)->get(route('dashboard'))->assertOk();
    $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()
        ->assertDontSee('Your role must use two-factor sign-in')
        ->assertSee('Enable 2FA');
});

test('someone setting it up may still confirm their password, use the Security page and sign out', function () {
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->create();

    $this->actingAs($finance)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->actingAs($finance)->get(route('password.confirm'))->assertOk();

    $securityPage = livewireSnapshot($this->actingAs($finance)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit')));
    sendLivewireAction($securityPage, 'loadPasskeys')->assertOk();

    $this->actingAs($finance)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

test('a page opened before the requirement cannot be used until it is set up', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    config(['auth.two_factor_required' => false]);
    $auditLog = livewireSnapshot($this->actingAs($owner)->get(route('settings.audit_log'))->assertOk());

    config(['auth.two_factor_required' => true]);
    sendLivewireAction($auditLog, 'showEverything')->assertForbidden();
});

test('once set up, Backoffice opens and two-factor sign-in cannot be switched off', function () {
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->withTwoFactor()->create();

    $this->actingAs($finance)->get(route('dashboard'))->assertOk();

    $response = $this->actingAs($finance)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertOk()
        ->assertSee('so it stays on')
        ->assertDontSee('Disable 2FA');

    sendLivewireAction(livewireSnapshot($response), 'disable')->assertForbidden();
    $this->actingAs($finance)->withSession(['auth.password_confirmed_at' => time()])->delete(route('two-factor.disable'))->assertForbidden();

    expect($finance->fresh()->hasEnabledTwoFactorAuthentication())->toBeTrue();
});

test('someone who is only offered it may still switch it off', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->withTwoFactor()->create();

    $response = $this->actingAs($manager)->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertSee('Disable 2FA');
    sendLivewireAction(livewireSnapshot($response), 'disable')->assertOk();

    expect($manager->fresh()->hasEnabledTwoFactorAuthentication())->toBeFalse();
});

test('an Owner viewing Backoffice as a Finance member who has not set it up is not stopped', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->withTwoFactor()->create();
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->create();

    impersonate($owner, $finance);

    $this->get(route('dashboard'))->assertOk();
});

test('switched off, as on a developer\'s computer, nobody is stopped', function () {
    config(['auth.two_factor_required' => false]);

    $this->actingAs(User::factory()->withRoleEverywhere(Role::Owner)->create())->get(route('dashboard'))->assertOk();
});

/*
 * Resetting it for someone who has lost their phone and their recovery codes.
 */

test('the command line resets it, records why, and they set it up again', function () {
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->withTwoFactor()->create(['name' => 'Finley Finance']);

    $this->artisan('backoffice:two-factor-reset', ['email' => strtoupper($finance->email), '--reason' => 'Lost phone and codes'])
        ->expectsOutputToContain('Two-factor sign-in is off for Finley Finance. They will be asked to set it up again')
        ->assertSuccessful();

    expect($finance->fresh()->two_factor_secret)->toBeNull();

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::StaffTwoFactorReset)
        ->and($event->subject->is($finance))->toBeTrue()
        ->and($event->reason)->toBe('Lost phone and codes')
        ->and($event->actor_id)->toBeNull();

    $this->actingAs($finance->fresh())->get(route('dashboard'))->assertRedirect(route('security.edit'));
});

test('the reset asks why, and changes nothing for an unknown address or someone without it', function () {
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->withTwoFactor()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();

    $this->artisan('backoffice:two-factor-reset', ['email' => $finance->email])
        ->expectsQuestion('Why is it being reset?', 'New phone')
        ->assertSuccessful();
    expect(AuditEvent::query()->sole()->reason)->toBe('New phone');

    $this->artisan('backoffice:two-factor-reset', ['email' => 'nobody@example.com', '--reason' => 'x'])->assertFailed();
    $this->artisan('backoffice:two-factor-reset', ['email' => $manager->email, '--reason' => 'x'])
        ->expectsOutputToContain('has no two-factor sign-in to reset')
        ->assertSuccessful();

    expect(AuditEvent::query()->count())->toBe(1);
});
