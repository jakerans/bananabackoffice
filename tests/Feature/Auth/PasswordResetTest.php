<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::resetPasswords());
});

test('reset password link screen can be rendered', function () {
    $response = $this->get(route('password.request'));

    $response->assertOk();
});

test('reset password link can be requested', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

test('reset password screen can be rendered', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
        $response = $this->get(route('password.reset', $notification->token));

        $response->assertOk();

        return true;
    });
});

test('reset password link can be requested with the email typed in capital letters', function () {
    Notification::fake();

    $user = User::factory()->create(['email' => 'jordan@example.com']);

    $this->post(route('password.request'), ['email' => 'Jordan@Example.COM'])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

test('password can be reset with the email typed in capital letters', function () {
    $user = User::factory()->create(['email' => 'jordan@example.com']);

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => 'JORDAN@example.com',
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('a-long-passphrase', $user->refresh()->password))->toBeTrue();
});

test('an invited person who sets a password through password reset is no longer shown as invited', function () {
    $staff = User::factory()->invited()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $staff->refresh();

    expect($staff->invitationIsPending())->toBeFalse();
    expect($staff->invitation_accepted_at)->not->toBeNull();
    expect($staff->email_verified_at)->not->toBeNull();
});

test('an outstanding invitation link stops working once the person has reset their password', function () {
    $staff = User::factory()->invited()->create();

    $invitationToken = Password::broker('invitations')->createToken($staff);

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($staff),
        'email' => $staff->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseMissing('staff_invitation_tokens', ['email' => $staff->email]);

    $this->post(route('invitation.store'), [
        'token' => $invitationToken,
        'email' => $staff->email,
        'password' => 'a-second-passphrase',
        'password_confirmation' => 'a-second-passphrase',
    ])->assertSessionHasErrors('email');

    expect(Hash::check('a-long-passphrase', $staff->refresh()->password))->toBeTrue();
});

test('resetting a password does not change the invitation record of someone who was never invited', function () {
    $user = User::factory()->unverified()->create();

    $this->post(route('password.update'), [
        'token' => Password::broker()->createToken($user),
        'email' => $user->email,
        'password' => 'a-long-passphrase',
        'password_confirmation' => 'a-long-passphrase',
    ])->assertSessionHasNoErrors();

    $user->refresh();

    expect($user->invitation_accepted_at)->toBeNull();
    expect($user->email_verified_at)->toBeNull();
});

test('password can be reset with valid token', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.request'), ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $response = $this->post(route('password.update'), [
            'token' => $notification->token,
            'email' => $user->email,
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login', absolute: false));

        return true;
    });
});
