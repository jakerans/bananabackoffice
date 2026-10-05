<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Actions\VerifyPasskey;
use PragmaRX\Google2FA\Google2FA;

test('a deactivated staff member cannot sign in', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'This account has been deactivated. Please contact an administrator.',
    ]);

    $this->assertGuest();
});

test('a wrong password does not reveal that an account is deactivated', function () {
    $user = User::factory()->inactive()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrors([
        'email' => 'These credentials do not match our records.',
    ]);

    $this->assertGuest();
});

test('a staff member deactivated while signed in is signed out on their next request', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    $user->forceFill(['is_active' => false])->save();

    $this->get(route('dashboard'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'This account has been deactivated. Please contact an administrator.');

    $this->assertGuest();
});

test('a staff member with two-factor sign-in can complete it while their account is active', function (Closure $proof) {
    Event::fake([Login::class]);

    $user = userAwaitingTwoFactorChallenge();

    $this->post(route('two-factor.login.store'), $proof())
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Login::class);
})->with('two-factor proofs');

test('a staff member deactivated during two-factor sign-in cannot complete it', function (Closure $proof) {
    Event::fake([Login::class]);

    $user = userAwaitingTwoFactorChallenge();
    $user->forceFill(['is_active' => false])->save();

    $this->post(route('two-factor.login.store'), $proof())
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors([
            'email' => 'This account has been deactivated. Please contact an administrator.',
        ])
        ->assertSessionMissing('login.id');

    $this->assertGuest();
    Event::assertNotDispatched(Login::class);
    expect($user->fresh()->recoveryCodes())->toBe(['recovery-code-1']);
})->with('two-factor proofs');

test('a staff member deactivated during two-factor sign-in is not shown the challenge page', function () {
    $user = userAwaitingTwoFactorChallenge();
    $user->forceFill(['is_active' => false])->save();

    $this->get(route('two-factor.login'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('a staff member can sign in with a passkey while their account is active', function () {
    Event::fake([Login::class]);

    $user = User::factory()->create();

    submitAcceptedPasskeyFor($user)->assertOk();

    $this->assertAuthenticatedAs($user);
    Event::assertDispatched(Login::class);
});

test('a deactivated staff member cannot sign in with a passkey', function () {
    Event::fake([Login::class]);

    $user = User::factory()->inactive()->create();

    submitAcceptedPasskeyFor($user)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['credential' => 'Unable to sign in with this account.']);

    $this->assertGuest();
    Event::assertNotDispatched(Login::class);
});

dataset('two-factor proofs', [
    'a valid authenticator code' => [fn () => ['code' => app(Google2FA::class)->getCurrentOtp(TWO_FACTOR_TEST_SECRET)]],
    'a valid recovery code' => [fn () => ['recovery_code' => 'recovery-code-1']],
]);

const TWO_FACTOR_TEST_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

/**
 * Take a staff member through the password step, leaving them at the two-factor challenge.
 */
function userAwaitingTwoFactorChallenge(): User
{
    $user = User::factory()->withTwoFactor()->create([
        'two_factor_secret' => encrypt(TWO_FACTOR_TEST_SECRET),
    ]);

    test()->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('two-factor.login'));

    test()->assertGuest();

    return $user;
}

/**
 * Submit a passkey sign-in for the staff member whose passkey itself checks out.
 *
 * The cryptographic check of the passkey is replaced by one that accepts it, so
 * what is exercised is everything the application decides after that point.
 */
function submitAcceptedPasskeyFor(User $user): TestResponse
{
    $passkey = $user->passkeys()->create([
        'name' => 'Test passkey',
        'credential_id' => 'test-credential',
        'credential' => [],
    ]);

    test()->mock(VerifyPasskey::class)->shouldReceive('__invoke')->andReturn($passkey);

    test()->getJson(route('passkey.login-options'))->assertOk();

    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

    return test()->postJson(route('passkey.login'), [
        'credential' => [
            'id' => $encode('test-credential'),
            'rawId' => $encode('test-credential'),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $encode((string) json_encode([
                    'type' => 'webauthn.get',
                    'challenge' => $encode('challenge'),
                    'origin' => config('app.url'),
                ])),
                'authenticatorData' => $encode(str_repeat("\0", 37)),
                'signature' => $encode('signature'),
                'userHandle' => null,
            ],
        ],
    ]);
}
