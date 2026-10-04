<?php

use App\Actions\Staff\SendStaffInvitation;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;

test('guests are redirected to the login page', function () {
    $this->get(route('settings.users'))->assertRedirect(route('login'));
});

test('an owner can view the staff list with each person\'s access', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    $this->actingAs($owner)
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee('Sam Sales')
        ->assertSee('Sales Consultant')
        ->assertSee('Lakeview');
});

test('staff without the manage users permission are forbidden', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('settings.users'))->assertForbidden();
})->with([
    'leadership' => Role::Leadership,
    'finance' => Role::Finance,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
]);

test('the users component cannot be mounted without permission', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::settings.users')
        ->assertForbidden();
});

test('an owner can invite a staff member by email', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'Sam@Example.com')
        ->call('invite')
        ->assertHasNoErrors();

    $staff = User::query()->where('email', 'sam@example.com')->sole();

    expect($staff->name)->toBe('Sam Sales');
    expect($staff->is_active)->toBeTrue();
    expect($staff->invitationIsPending())->toBeTrue();
    expect($staff->email_verified_at)->toBeNull();
    expect($staff->accessGrants)->toBeEmpty();

    Notification::assertSentTo($staff, StaffInvitation::class);
});

test('an invited account has no password anyone could know', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'sam@example.com')
        ->call('invite');

    $staff = User::query()->where('email', 'sam@example.com')->sole();

    expect(Hash::check('', $staff->password))->toBeFalse();
    expect(Hash::check('password', $staff->password))->toBeFalse();

    auth()->logout();

    $this->post(route('login.store'), ['email' => 'sam@example.com', 'password' => 'password']);

    $this->assertGuest();
});

test('an invitation requires a name and a valid unused email address', function (string $name, string $email, array $errors) {
    Notification::fake();

    User::factory()->create(['email' => 'taken@example.com']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', $name)
        ->set('email', $email)
        ->call('invite')
        ->assertHasErrors($errors);

    expect(User::query()->count())->toBe(2);

    Notification::assertNothingSent();
})->with([
    'missing name' => ['', 'sam@example.com', ['name' => 'The name field is required.']],
    'missing email' => ['Sam Sales', '', ['email' => 'The email field is required.']],
    'invalid email' => ['Sam Sales', 'not-an-email', ['email' => 'The email field must be a valid email address.']],
    'email in use' => ['Sam Sales', 'taken@example.com', ['email' => 'The email has already been taken.']],
]);

test('the account is kept when the invitation email cannot be sent', function () {
    $this->mock(SendStaffInvitation::class)
        ->shouldReceive('handle')
        ->once()
        ->andThrow(new TransportException('Connection refused'));

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $staff = fn () => User::query()->where('email', 'sam@example.com')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.users')
        ->set('name', 'Sam Sales')
        ->set('email', 'sam@example.com')
        ->call('invite')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.users.show', $staff()));

    expect($staff()->is_active)->toBeTrue();
});

test('an invitation is refused once the permission has been removed', function () {
    Notification::fake();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $component = Livewire::actingAs($owner)->test('pages::settings.users');

    $owner->accessGrants()->delete();
    $owner->refresh();

    $component
        ->set('name', 'Sam Sales')
        ->set('email', 'sam@example.com')
        ->call('invite')
        ->assertForbidden();

    expect(User::query()->where('email', 'sam@example.com')->exists())->toBeFalse();

    Notification::assertNothingSent();
});
