<?php

use App\Enums\Permission;
use App\Http\Controllers\QuickBooksController;
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

    Route::livewire('settings/contact-center', 'pages::settings.contact-center')
        ->can(Permission::ManageContactCenterSettings)
        ->name('settings.contact_center');

    // Settings > Integrations: each Location's QuickBooks company (slice 28, step 28a). Connecting sends
    // the Owner to QuickBooks' own page and back to the callback.
    Route::livewire('settings/integrations', 'pages::settings.integrations')
        ->can(Permission::ManageLocations)
        ->name('settings.integrations');

    Route::get('settings/integrations/quickbooks/{location}/connect', [QuickBooksController::class, 'connect'])
        ->can(Permission::ManageLocations)
        ->name('quickbooks.connect');

    // Settings > Pricing Settings: the price books and production rates (slice 31, step 31a; decision 197).
    // Seeing them and changing them are two permissions; the page checks both.
    Route::livewire('settings/pricing', 'pages::settings.pricing')->name('settings.pricing');

    // Settings > Audit Log: every recorded change, for the Owner (slice 29, decision 191).
    Route::livewire('settings/audit-log', 'pages::settings.audit-log')
        ->can(Permission::ViewAuditLog)
        ->name('settings.audit_log');

    Route::get('settings/integrations/quickbooks/callback', [QuickBooksController::class, 'callback'])
        ->can(Permission::ManageLocations)
        ->name('quickbooks.callback');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
