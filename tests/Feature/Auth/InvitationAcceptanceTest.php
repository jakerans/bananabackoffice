<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

function invitationTokenFor(User $staff): string
{
    return Password::broker('invitations')->createToken($staff);
}

test('the accept invitation screen can be rendered', function () {
    $staff = User::factory()->invited()->create();

    $this->get(route('invitation.accept', ['token' => invitationTokenFor($staff), 'email' => $staff->email]))
        ->assertOk()
        ->assertSee($staff->email);
});

test('an invited staff member can choose their own password', function () {
    $staff = User::factory()->invited()->create();

    $response = $this->post(route('invitation.store'), [
        'token' => invitationTokenFor($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your password has been set. You can now log in.');

    $staff->refresh();

    expect(Hash::check('a-long-passphrase', $staff->password))->toBeTrue();
    expect($staff->invitationIsPending())->toBeFalse();
    expect($staff->email_verified_at)->not->toBeNull();

    $this->assertGuest();
});

test('a staff member can sign in with the password they chose', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('invitation.store'), [
        'token' => invitationTokenFor($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ]);

    $this->post(route('login.store'), [
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($staff);
});

test('an invitation link can only be used once', function () {
    $staff = User::factory()->invited()->create();
    $token = invitationTokenFor($staff);

    $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'a-second-passphrase',
        'password_confirmation' => 'a-second-passphrase',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('a-long-passphrase', $staff->refresh()->password))->toBeTrue();
});

test('an invitation is refused with one message whatever is wrong with it', function (Closure $payload) {
    $staff = User::factory()->invited()->create(['email' => 'sam@example.com']);
    $originalPassword = $staff->password;

    $response = $this->post(route('invitation.store'), [
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
        ...$payload($staff),
    ]);

    $response->assertSessionHasErrors([
        'email' => 'This invitation link is invalid or has expired. Please ask an administrator to send a new one.',
    ]);

    expect($staff->refresh()->password)->toBe($originalPassword);
    expect($staff->invitationIsPending())->toBeTrue();
})->with([
    'wrong token' => [function (User $staff) {
        invitationTokenFor($staff);

        return ['token' => 'not-the-token', 'email' => $staff->email];
    }],
    'unknown email' => [fn (User $staff) => ['token' => invitationTokenFor($staff), 'email' => 'nobody@example.com']],
    'no invitation sent' => [fn (User $staff) => ['token' => 'anything', 'email' => $staff->email]],
    'expired link' => [function (User $staff) {
        $token = invitationTokenFor($staff);

        test()->travel(73)->hours();

        return ['token' => $token, 'email' => $staff->email];
    }],
    'deactivated account' => [function (User $staff) {
        $token = invitationTokenFor($staff);

        $staff->forceFill(['is_active' => false])->save();

        return ['token' => $token, 'email' => $staff->email];
    }],
]);

test('an invitation link is still valid shortly before it expires', function () {
    $staff = User::factory()->invited()->create();
    $token = invitationTokenFor($staff);

    $this->travel(71)->hours();

    $this->post(route('invitation.store'), [
        'token' => $token,
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();
});

test('the chosen password must be confirmed and long enough', function (string $password, string $confirmation, string $message) {
    $staff = User::factory()->invited()->create();

    $this->post(route('invitation.store'), [
        'token' => invitationTokenFor($staff),
        'email' => $staff->email,
        'password' => $password,
        'password_confirmation' => $confirmation,
    ])->assertSessionHasErrors(['password' => $message]);

    expect($staff->refresh()->invitationIsPending())->toBeTrue();
})->with([
    'not confirmed' => ['a-long-passphrase', 'a-different-passphrase', 'The password field confirmation does not match.'],
    'too short' => ['short', 'short', 'The password field must be at least 8 characters.'],
]);

test('an invitation token does not work on the password reset form', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('password.update'), [
        'token' => invitationTokenFor($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasErrors('email');

    expect($staff->refresh()->invitationIsPending())->toBeTrue();
});

test('a signed-in staff member is sent away from the accept invitation screen', function () {
    $staff = User::factory()->invited()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('invitation.accept', ['token' => invitationTokenFor($staff), 'email' => $staff->email]))
        ->assertRedirect(route('dashboard'));
});
