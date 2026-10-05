<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Features;
use Symfony\Component\Mailer\Exception\TransportException;

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

test('requesting a reset link gives the same answer whether or not the email has an account', function (Closure $email) {
    Notification::fake();

    User::factory()->create(['email' => 'jordan@example.com']);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $email()])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'If that email address belongs to an account, we have emailed a password reset link.');
})->with([
    'an email with an account' => [fn () => 'jordan@example.com'],
    'an email without an account' => [fn () => 'nobody@example.com'],
]);

test('no reset link is sent for an email without an account', function () {
    Notification::fake();

    User::factory()->create(['email' => 'jordan@example.com']);

    $this->post(route('password.email'), ['email' => 'nobody@example.com']);

    Notification::assertNothingSent();
});

test('asking again before a new link is allowed gives the same answer and sends nothing more', function () {
    Notification::fake();

    $user = User::factory()->create();

    $this->post(route('password.email'), ['email' => $user->email]);

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'If that email address belongs to an account, we have emailed a password reset link.');

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

test('a reset email that fails to send gives the same answer and is reported', function () {
    Exceptions::fake();
    useFailingMailer();

    $user = User::factory()->create();

    $this->from(route('password.request'))
        ->post(route('password.email'), ['email' => $user->email])
        ->assertRedirect(route('password.request'))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'If that email address belongs to an account, we have emailed a password reset link.');

    Exceptions::assertReported(TransportException::class);
});

test('reset link requests are limited per visitor whether or not the email has an account', function () {
    Notification::fake();

    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('password.email'), ['email' => "nobody{$attempt}@example.com"])->assertRedirect();
    }

    $this->post(route('password.email'), ['email' => $user->email])->assertRedirect();

    $this->post(route('password.email'), ['email' => $user->email])->assertTooManyRequests();
    $this->post(route('password.email'), ['email' => 'nobody@example.com'])->assertTooManyRequests();
});

test('a malformed email address is still rejected when requesting a reset link', function () {
    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});
