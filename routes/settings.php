<?php

use App\Enums\Permission;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');

    Route::livewire('settings/locations', 'pages::settings.locations')
        ->can(Permission::ManageLocations)
        ->name('settings.locations');

    Route::livewire('settings/users', 'pages::settings.users')
        ->can(Permission::ManageUsers)
        ->name('settings.users');

    Route::livewire('settings/users/{user}', 'pages::settings.user')
        ->can(Permission::ManageUsers)
        ->name('settings.users.show');

    Route::livewire('settings/lists', 'pages::settings.lists')
        ->can(Permission::ManageLeadSalesLists)
        ->name('settings.lists');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
