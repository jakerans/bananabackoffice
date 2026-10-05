<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Lists\AddListOption;
use App\Actions\Lists\MoveListOption;
use App\Actions\Lists\SetListOptionActive;
use App\Actions\Lists\UpdateListOption;
use App\Actions\Staff\CreateOwner;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\GrantAccess;
use App\Actions\Staff\InviteStaffMember;
use App\Actions\Staff\ReactivateStaffMember;
use App\Actions\Staff\ResendStaffInvitation;
use App\Actions\Staff\RevokeAccess;
use App\Enums\GrantScope;
use App\Enums\ManagedList;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
 * A change and its audit event are saved together or not at all. These tests make
 * the audit event impossible to record and check that the change is not kept.
 */

beforeEach(function () {
    $this->mock(RecordAuditEvent::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('The audit event could not be recorded.'));
});

test('access is not granted when its audit event cannot be recorded', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->create();

    expect(fn () => app(GrantAccess::class)->handle($owner, $staff, Role::CallCenterAgent, GrantScope::AllLocations, null))
        ->toThrow(RuntimeException::class);

    expect($staff->accessGrants()->count())->toBe(0);
});

test('access is not removed when its audit event cannot be recorded', function () {
    $staff = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $grant = $staff->accessGrants()->sole();

    expect(fn () => app(RevokeAccess::class)->handle($grant))->toThrow(RuntimeException::class);

    expect(AccessGrant::query()->whereKey($grant->id)->exists())->toBeTrue();
});

test('a staff member is not deactivated when the audit event cannot be recorded', function () {
    $staff = User::factory()->create();

    expect(fn () => app(DeactivateStaffMember::class)->handle($staff))->toThrow(RuntimeException::class);

    expect($staff->fresh()->is_active)->toBeTrue();
});

test('a staff member is not reactivated when the audit event cannot be recorded', function () {
    $staff = User::factory()->inactive()->create();

    expect(fn () => app(ReactivateStaffMember::class)->handle($staff))->toThrow(RuntimeException::class);

    expect($staff->fresh()->is_active)->toBeFalse();
});

test('no account is created and no email sent by an invitation whose audit event cannot be recorded', function () {
    Notification::fake();

    expect(fn () => app(InviteStaffMember::class)->handle('Sam Sales', 'sam@example.com'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('email', 'sam@example.com')->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('an invitation is not replaced and no email sent when the resend cannot be recorded', function () {
    Notification::fake();

    $staff = User::factory()->invited()->create();
    $earlierToken = Password::broker('invitations')->createToken($staff);

    expect(fn () => app(ResendStaffInvitation::class)->handle($staff))->toThrow(RuntimeException::class);

    expect(Password::broker('invitations')->tokenExists($staff, $earlierToken))->toBeTrue();
    Notification::assertNothingSent();
});

test('no owner is created when the audit event cannot be recorded', function () {
    expect(fn () => app(CreateOwner::class)->handle('Olive Owner', 'olive@example.com', 'a-long-passphrase'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->count())->toBe(0);
    expect(AccessGrant::query()->count())->toBe(0);
});

test('a list change is not kept when its audit event cannot be recorded', function (Closure $change) {
    $before = LeadChannel::query()->ordered()->get(['name', 'position', 'is_active'])->toArray();
    $email = LeadChannel::query()->where('name', 'Email')->sole();

    expect(fn () => $change($email))->toThrow(RuntimeException::class);

    expect(LeadChannel::query()->ordered()->get(['name', 'position', 'is_active'])->toArray())->toBe($before);
})->with([
    'adding' => [fn () => app(AddListOption::class)->handle(ManagedList::LeadChannels, 'Carrier Pigeon')],
    'renaming' => [fn (LeadChannel $email) => app(UpdateListOption::class)->handle($email, 'Electronic Mail')],
    'moving' => [fn (LeadChannel $email) => app(MoveListOption::class)->handle($email, towardsStart: true)],
    'deactivating' => [fn (LeadChannel $email) => app(SetListOptionActive::class)->handle($email, active: false)],
]);

test('a location change is not kept when its audit event cannot be recorded', function (Closure $change, Closure $unchanged) {
    $location = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::settings.locations');

    expect(fn () => $change($page, $location))->toThrow(RuntimeException::class);

    expect($unchanged($location->fresh()))->toBeTrue();
})->with([
    'adding' => [
        fn ($page) => $page->set('name', 'Northside')->call('create'),
        fn () => Location::query()->where('name', 'Northside')->doesntExist(),
    ],
    'renaming' => [
        fn ($page, Location $location) => $page->call('edit', $location->id)->set('editingName', 'Lake View')->call('rename'),
        fn (Location $location) => $location->name === 'Lakeview',
    ],
    'deactivating' => [
        fn ($page, Location $location) => $page->call('deactivate', $location->id),
        fn (Location $location) => $location->is_active,
    ],
]);
