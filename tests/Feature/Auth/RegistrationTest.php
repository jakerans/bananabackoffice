<?php

use App\Models\User;

test('the registration screen is not available', function () {
    $this->get('/register')->assertNotFound();
});

test('an account cannot be created through public registration', function () {
    $response = $this->post('/register', [
        'name' => 'Uninvited Visitor',
        'email' => 'visitor@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();

    expect(User::query()->count())->toBe(0);
});
