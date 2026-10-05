<?php

use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\ReactivateStaffMember;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Build the "remember me" cookie a browser would hold for this person right now.
 *
 * @return array<string, string>
 */
function rememberCookieFor(User $user): array
{
    return [Auth::guard('web')->getRecallerName() => $user->id.'|'.$user->remember_token.'|'.$user->password];
}

/**
 * Forget everything about the current visitor except the cookies they send.
 */
function startFreshBrowserSession(): void
{
    test()->flushSession();

    app('auth')->forgetGuards();
}

test('the only owner who can sign in cannot be deactivated', function (Closure $otherOwner) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $otherOwner();

    $deactivate = fn () => app(DeactivateStaffMember::class)->handle($owner);

    expect($deactivate)->toThrow(
        ValidationException::class,
        'This is the only Owner who can sign in. Make sure another Owner has signed in before deactivating this one.',
    );

    expect($owner->refresh()->is_active)->toBeTrue();
})->with([
    'no other owner' => [fn () => null],
    'the other owner never accepted their invitation' => [fn () => User::factory()->invited()->withRoleEverywhere(Role::Owner)->create()],
    'the other owner is deactivated' => [fn () => User::factory()->inactive()->withRoleEverywhere(Role::Owner)->create()],
    'the other owner has not confirmed their email' => [fn () => User::factory()->unverified()->withRoleEverywhere(Role::Owner)->create()],
]);

test('an owner can be deactivated while another owner who can sign in remains', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    User::factory()->withRoleEverywhere(Role::Owner)->create();

    app(DeactivateStaffMember::class)->handle($owner);

    expect($owner->refresh()->is_active)->toBeFalse();
});

test('an owner who never accepted their invitation can be deactivated', function () {
    User::factory()->withRoleEverywhere(Role::Owner)->create();
    $invited = User::factory()->invited()->withRoleEverywhere(Role::Owner)->create();

    app(DeactivateStaffMember::class)->handle($invited);

    expect($invited->refresh()->is_active)->toBeFalse();
});

test('a remember-me cookie signs a staff member in while their account is active', function () {
    $staff = User::factory()->create();

    $this->withCookies(rememberCookieFor($staff))
        ->get(route('dashboard'))
        ->assertOk();
});

test('deactivating a staff member makes their remember-me cookie worthless, even after reactivation', function () {
    $staff = User::factory()->create();
    $cookie = rememberCookieFor($staff);

    app(DeactivateStaffMember::class)->handle($staff);

    $this->withCookies($cookie)->get(route('dashboard'))->assertRedirect(route('login'));

    startFreshBrowserSession();

    app(ReactivateStaffMember::class)->handle($staff->refresh());

    $this->withCookies($cookie)->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});

test('deactivating a staff member deletes their stored sessions and nobody else\'s', function () {
    config(['session.driver' => 'database']);

    $staff = User::factory()->create();
    $colleague = User::factory()->create();

    foreach ([['one', $staff->id], ['two', $staff->id], ['three', $colleague->id], ['four', null]] as [$id, $userId]) {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => now()->timestamp]);
    }

    app(DeactivateStaffMember::class)->handle($staff);

    expect(DB::table('sessions')->orderBy('id')->pluck('id')->all())->toBe(['four', 'three']);
});

test('a reactivated staff member can sign in again with their password', function () {
    $staff = User::factory()->create();

    app(DeactivateStaffMember::class)->handle($staff);
    app(ReactivateStaffMember::class)->handle($staff->refresh());

    $this->post(route('login.store'), ['email' => $staff->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($staff);
});
