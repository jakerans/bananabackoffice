<?php

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $staff = User::factory()->create();

    $this->get(route('settings.users.show', $staff))->assertRedirect(route('login'));
});

test('an owner can view a staff member\'s access', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::ProductionManager, $lakeview)->create(['name' => 'Parker Production']);

    $this->actingAs($owner)
        ->get(route('settings.users.show', $staff))
        ->assertOk()
        ->assertSee('Parker Production')
        ->assertSee('Production Manager')
        ->assertSee('Lakeview');
});

test('staff without the manage users permission are forbidden', function () {
    $location = Location::factory()->create();

    $manager = User::factory()->withRoleAt(Role::LocationManager, $location)->create();
    $staff = User::factory()->create();

    $this->actingAs($manager)->get(route('settings.users.show', $staff))->assertForbidden();

    Livewire::actingAs($manager)
        ->test('pages::settings.user', ['user' => $staff])
        ->assertForbidden();
});

test('an owner can grant a role at one location', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::SalesConsultant->value)
        ->set('scope', GrantScope::Location->value)
        ->set('locationId', $lakeview->id)
        ->call('grant')
        ->assertHasNoErrors();

    $grant = $staff->accessGrants()->sole();

    expect($grant->role)->toBe(Role::SalesConsultant);
    expect($grant->scope)->toBe(GrantScope::Location);
    expect($grant->location_id)->toBe($lakeview->id);
    expect($grant->granted_by)->toBe($owner->id);
});

test('an owner can grant a role across all locations', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::CallCenterAgent->value)
        ->set('scope', GrantScope::AllLocations->value)
        ->set('locationId', $lakeview->id)
        ->call('grant')
        ->assertHasNoErrors();

    $grant = $staff->accessGrants()->sole();

    expect($grant->scope)->toBe(GrantScope::AllLocations);
    expect($grant->location_id)->toBeNull();
});

test('a location-scoped grant requires an active location that exists', function (?Closure $locationId, string $message) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::SalesConsultant->value)
        ->set('scope', GrantScope::Location->value)
        ->set('locationId', $locationId ? $locationId() : null)
        ->call('grant')
        ->assertHasErrors(['locationId' => $message]);

    expect($staff->accessGrants()->count())->toBe(0);
})->with([
    'no location' => [null, 'The location field is required.'],
    'unknown location' => [fn () => 999999, 'The selected location is invalid.'],
    'inactive location' => [fn () => Location::factory()->inactive()->create()->id, 'The selected location is invalid.'],
]);

test('a role and scope outside the defined values are rejected', function (string $role, string $scope, array $errors) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', $role)
        ->set('scope', $scope)
        ->call('grant')
        ->assertHasErrors($errors);

    expect($staff->accessGrants()->count())->toBe(0);
})->with([
    'missing role' => ['', 'all_locations', ['role' => 'The role field is required.']],
    'unknown role' => ['superuser', 'all_locations', ['role' => 'The selected role is invalid.']],
    'unknown scope' => ['finance', 'everywhere', ['scope' => 'The selected scope is invalid.']],
    'wildcard scope' => ['finance', '*', ['scope' => 'The selected scope is invalid.']],
]);

test('the owner role cannot be granted for a single location', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('scope', GrantScope::Location->value)
        ->set('locationId', $lakeview->id)
        ->set('role', Role::Owner->value)
        ->set('scope', GrantScope::Location->value)
        ->call('grant')
        ->assertHasErrors(['scope' => 'The Owner role can only be granted for all locations.']);

    expect($staff->accessGrants()->count())->toBe(0);
});

test('choosing the owner role switches the grant to all locations', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::Owner->value)
        ->assertSet('scope', GrantScope::AllLocations->value);
});

test('the same access cannot be granted twice', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->set('role', Role::SalesConsultant->value)
        ->set('scope', GrantScope::Location->value)
        ->set('locationId', $lakeview->id)
        ->call('grant')
        ->assertHasErrors(['role' => 'This person already has that access.']);

    expect($staff->accessGrants()->count())->toBe(1);
});

test('an owner can remove an access grant', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('revoke', $staff->accessGrants()->sole()->id)
        ->assertHasNoErrors();

    expect($staff->accessGrants()->count())->toBe(0);
});

test('a grant belonging to someone else cannot be removed from this page', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();
    $otherGrant = AccessGrant::factory()->at($lakeview)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('revoke', $otherGrant->id)
        ->assertNotFound();

    expect(AccessGrant::query()->whereKey($otherGrant->id)->exists())->toBeTrue();
});

test('the only owner who can sign in cannot lose the owner role', function (Closure $otherOwner) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $otherOwner();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $owner])
        ->call('revoke', $owner->accessGrants()->sole()->id)
        ->assertHasErrors(['grant' => 'This is the only Owner who can sign in. Make sure another Owner has signed in before removing this one.']);

    expect($owner->accessGrants()->count())->toBe(1);
})->with([
    'no other owner' => [fn () => null],
    'the other owner is deactivated' => [fn () => User::factory()->inactive()->withRoleEverywhere(Role::Owner)->create()],
    'the other owner never accepted their invitation' => [fn () => User::factory()->invited()->withRoleEverywhere(Role::Owner)->create()],
    'the other owner has not confirmed their email' => [fn () => User::factory()->unverified()->withRoleEverywhere(Role::Owner)->create()],
]);

test('the owner role can be removed from an owner who never accepted their invitation', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $invited = User::factory()->invited()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $invited])
        ->call('revoke', $invited->accessGrants()->sole()->id)
        ->assertHasNoErrors();

    expect($invited->accessGrants()->count())->toBe(0);
});

test('an owner role can be removed while another active owner remains', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $secondOwner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $secondOwner])
        ->call('revoke', $secondOwner->accessGrants()->sole()->id)
        ->assertHasNoErrors();

    expect($secondOwner->accessGrants()->count())->toBe(0);
});

test('an owner can deactivate and reactivate a staff member', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $component = Livewire::actingAs($owner)->test('pages::settings.user', ['user' => $staff]);

    $component->call('deactivate')->assertHasNoErrors();

    expect($staff->refresh()->is_active)->toBeFalse();
    expect($staff->accessGrants()->count())->toBe(1);

    $component->call('reactivate')->assertHasNoErrors();

    expect($staff->refresh()->is_active)->toBeTrue();
});

test('nobody can deactivate their own account', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $owner])
        ->call('deactivate')
        ->assertForbidden();

    expect($owner->refresh()->is_active)->toBeTrue();
});

test('deactivating a staff member cancels their pending invitation', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'sam@example.com')
        ->call('invite');

    $staff = User::query()->where('email', 'sam@example.com')->sole();

    $this->assertDatabaseHas('staff_invitation_tokens', ['email' => 'sam@example.com']);

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('deactivate');

    $this->assertDatabaseMissing('staff_invitation_tokens', ['email' => 'sam@example.com']);
});

test('resending an invitation sends a new link and the earlier link stops working', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->invited()->create();

    $earlierToken = Password::broker('invitations')->createToken($staff);

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('resendInvitation')
        ->assertHasNoErrors();

    $newToken = null;

    Notification::assertSentTo($staff, StaffInvitation::class, function (StaffInvitation $invitation) use (&$newToken) {
        $newToken = $invitation->token;

        return true;
    });

    expect($newToken)->not->toBe($earlierToken);

    auth()->logout();

    $accept = fn (string $token) => $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ]);

    $accept($earlierToken)->assertSessionHasErrors('email');

    expect($staff->refresh()->invitationIsPending())->toBeTrue();

    $accept($newToken)->assertSessionHasNoErrors();

    expect($staff->refresh()->invitationIsPending())->toBeFalse();
});

test('an earlier invitation still works when a resend cannot be delivered', function () {
    useFailingMailer();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->invited()->create();
    $invitedAt = $staff->invited_at;

    $earlierToken = Password::broker('invitations')->createToken($staff);

    $this->travel(1)->hours();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('resendInvitation')
        ->assertOk();

    expect($staff->refresh()->invited_at->equalTo($invitedAt))->toBeTrue();

    auth()->logout();

    $this->post(route('invitation.store'), [
        'token' => $earlierToken,
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();
});

test('an invitation cannot be resent to someone who has already set a password', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('resendInvitation')
        ->assertForbidden();

    Notification::assertNothingSent();
});

test('access actions are refused once the permission has been removed', function (string $action) {
    Notification::fake();

    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->invited()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $component = Livewire::actingAs($owner)->test('pages::settings.user', ['user' => $staff]);

    $owner->accessGrants()->delete();
    $owner->refresh();

    $component
        ->set('role', Role::Finance->value)
        ->set('scope', GrantScope::AllLocations->value)
        ->call($action, ...($action === 'revoke' ? [$staff->accessGrants()->sole()->id] : []))
        ->assertForbidden();

    expect($staff->refresh()->is_active)->toBeTrue();
    expect($staff->accessGrants()->pluck('role')->all())->toBe([Role::SalesConsultant]);

    Notification::assertNothingSent();
})->with(['grant', 'revoke', 'deactivate', 'resendInvitation']);
