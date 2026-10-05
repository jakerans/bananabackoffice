<?php

use App\Actions\Staff\DeactivateStaffMember;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

test('granting a role at a location records who granted what to whom, and where', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::SalesConsultant->value)
        ->set('scope', GrantScope::Location->value)
        ->set('locationId', $lakeview->id)
        ->call('grant')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffAccessGranted);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->actor_name)->toBe('Olive Owner');
    expect($event->subject->is($staff))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe([]);
    expect($event->after())->toBe([
        'role' => 'sales_consultant',
        'scope' => 'location',
        'location_id' => $lakeview->id,
        'location' => 'Lakeview',
    ]);
});

test('granting a role across all locations records no location', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::CallCenterAgent->value)
        ->set('scope', GrantScope::AllLocations->value)
        ->call('grant')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->location_id)->toBeNull();
    expect($event->after())->toBe([
        'role' => 'call_center_agent',
        'scope' => 'all_locations',
        'location_id' => null,
        'location' => null,
    ]);
});

test('a grant that is refused records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::CallCenterAgent->value)
        ->set('scope', GrantScope::AllLocations->value)
        ->call('grant')
        ->assertHasErrors('role');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('removing access records what was removed, which the deleted grant can no longer show', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $grant = $staff->accessGrants()->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('revoke', $grant->id)
        ->assertHasNoErrors();

    expect(AccessGrant::query()->whereKey($grant->id)->exists())->toBeFalse();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffAccessRevoked);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($staff))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->after())->toBe([]);
    expect($event->before())->toBe([
        'role' => 'sales_consultant',
        'scope' => 'location',
        'location_id' => $lakeview->id,
        'location' => 'Lakeview',
    ]);
});

test('a removal that is refused records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $owner])
        ->call('revoke', $owner->accessGrants()->sole()->id)
        ->assertHasErrors('grant');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('deactivating and reactivating a staff member records each, with who did it', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    $page = Livewire::actingAs($owner)->test('pages::settings.user', ['user' => $staff]);

    $page->call('deactivate')->assertHasNoErrors();

    $deactivated = AuditEvent::query()->sole();

    expect($deactivated->action)->toBe(AuditAction::StaffDeactivated);
    expect($deactivated->actor->is($owner))->toBeTrue();
    expect($deactivated->subject->is($staff))->toBeTrue();

    $page->call('reactivate')->assertHasNoErrors();

    $reactivated = AuditEvent::query()->latest('id')->firstOrFail();

    expect(AuditEvent::query()->count())->toBe(2);
    expect($reactivated->action)->toBe(AuditAction::StaffReactivated);
    expect($reactivated->actor->is($owner))->toBeTrue();
    expect($reactivated->subject->is($staff))->toBeTrue();
});

test('a deactivation that is refused records nothing', function () {
    $onlyOwner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    expect(fn () => app(DeactivateStaffMember::class)->handle($onlyOwner))
        ->toThrow(ValidationException::class);

    expect($onlyOwner->fresh()->is_active)->toBeTrue();
    expect(AuditEvent::query()->count())->toBe(0);
});

test('deactivating someone who is already deactivated records nothing more', function () {
    $staff = User::factory()->inactive()->create();

    app(DeactivateStaffMember::class)->handle($staff);

    expect(AuditEvent::query()->count())->toBe(0);
});

test('inviting a staff member records who invited whom', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'Sam@Example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffInvited);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is(User::query()->where('email', 'sam@example.com')->sole()))->toBeTrue();
    expect($event->after())->toBe(['name' => 'Sam Sales', 'email' => 'sam@example.com']);
});

test('an invitation that could not be sent records nothing', function () {
    useFailingMailer();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'sam@example.com')
        ->call('invite')
        ->assertHasErrors('email');

    expect(User::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
    expect(AuditEvent::query()->count())->toBe(0);
});

test('accepting an invitation records it, with the person themself as the one acting', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('invitation.store'), [
        'token' => Password::broker('invitations')->createToken($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffInvitationAccepted);
    expect($event->actor->is($staff))->toBeTrue();
    expect($event->subject->is($staff))->toBeTrue();
});

test('an invitation link that is refused records nothing', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('invitation.store'), [
        'token' => 'not-the-real-token',
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasErrors('email');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('an invited person who sets their password through password reset is recorded as accepting', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffInvitationAccepted);
    expect($event->actor->is($staff))->toBeTrue();
    expect($event->subject->is($staff))->toBeTrue();
});

test('an ordinary password reset records nothing', function () {
    $staff = User::factory()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('creating the first owner from the command line records the access with nobody as the actor', function () {
    $this->artisan('backoffice:create-owner')
        ->expectsQuestion('Full name', 'Olive Owner')
        ->expectsQuestion('Email address', 'olive@example.com')
        ->expectsQuestion('Password', 'a-long-passphrase')
        ->expectsQuestion('Confirm password', 'a-long-passphrase')
        ->assertSuccessful();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::StaffAccessGranted);
    expect($event->actor_id)->toBeNull();
    expect($event->actor_name)->toBeNull();
    expect($event->subject->is(User::query()->sole()))->toBeTrue();
    expect($event->after())->toBe([
        'role' => 'owner',
        'scope' => 'all_locations',
        'location_id' => null,
        'location' => null,
    ]);
});
