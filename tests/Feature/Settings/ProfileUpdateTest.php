<?php

use App\Models\User;
use Livewire\Livewire;

test('profile page is displayed', function () {
    $this->actingAs($user = User::factory()->create());

    $this->get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', 'test@example.com')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->name)->toEqual('Test User');
    expect($user->email)->toEqual('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('name', 'Test User')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('a profile email typed with capital letters is stored in lowercase and still signs in', function () {
    $user = User::factory()->create(['email' => 'jordan@example.com']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'Jordan.New@Example.COM')
        ->call('updateProfileInformation')
        ->assertHasNoErrors()
        ->assertSet('email', 'jordan.new@example.com');

    expect($user->refresh()->email)->toBe('jordan.new@example.com');

    auth()->logout();

    $this->post(route('login.store'), ['email' => 'Jordan.New@Example.COM', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('retyping the same email with different capital letters does not ask for it to be confirmed again', function () {
    $user = User::factory()->create(['email' => 'jordan@example.com']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'JORDAN@Example.com')
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    $user->refresh();

    expect($user->email)->toBe('jordan@example.com');
    expect($user->email_verified_at)->not->toBeNull();
});

test('a profile email cannot be changed to one that differs from another account only by capital letters', function () {
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'jordan@example.com']);

    Livewire::actingAs($user)
        ->test('pages::settings.profile')
        ->set('name', $user->name)
        ->set('email', 'Taken@Example.com')
        ->call('updateProfileInformation')
        ->assertHasErrors(['email' => 'The email has already been taken.']);

    expect($user->refresh()->email)->toBe('jordan@example.com');
});

test('staff cannot delete their own account', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('profile.edit'))
        ->assertOk()
        ->assertDontSee('Delete account');
});
