<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Features;

test('login screen can be rendered', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertSessionHasErrorsIn('email');

    $this->assertGuest();
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $user = User::factory()->withTwoFactor()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect(route('two-factor.login'));
    $this->assertGuest();
});

test('users can authenticate with their email typed in capital letters', function () {
    $user = User::factory()->create(['email' => 'jordan@example.com']);

    $response = $this->post(route('login.store'), [
        'email' => 'JORDAN@Example.com',
        'password' => 'password',
    ]);

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user);
});

test('a password stored with outdated hashing settings is rehashed at sign-in', function () {
    $user = User::factory()->create();

    // Stored at a different cost from the one currently configured, as after a settings change.
    DB::table('users')->where('id', $user->id)->update([
        'password' => Hash::make('password', ['rounds' => (int) config('hashing.bcrypt.rounds') + 1]),
    ]);

    expect(Hash::needsRehash($user->refresh()->password))->toBeTrue();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertAuthenticatedAs($user);

    $user->refresh();

    expect(Hash::needsRehash($user->password))->toBeFalse();
    expect(Hash::check('password', $user->password))->toBeTrue();
});

test('a deactivated account is not rehashed or signed in', function () {
    $user = User::factory()->inactive()->create();

    $outdated = Hash::make('password', ['rounds' => (int) config('hashing.bcrypt.rounds') + 1]);

    DB::table('users')->where('id', $user->id)->update(['password' => $outdated]);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

    $this->assertGuest();

    expect($user->refresh()->password)->toBe($outdated);
});

test('users can logout', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('logout'));

    $response->assertRedirect(route('home'));

    $this->assertGuest();
});
