<?php

use App\Enums\Permission;
use App\Http\Controllers\CenterController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StopImpersonationController;
use App\Http\Controllers\SwitchLocationController;
use App\Models\Account;
use App\Models\Lead;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'dashboard')->name('home');

Route::middleware('guest')->group(function () {
    Route::get('invitation/{token}', [InvitationAcceptanceController::class, 'create'])->name('invitation.accept');

    Route::post('invitation', [InvitationAcceptanceController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitation.store');

    // Replaces Fortify's route of the same address, which reveals whether an email has an account.
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:password-reset-link')
        ->name('password.email');
});

// Always handled as the person who is really signed in, whoever they are viewing Backoffice as.
Route::post('impersonation/stop', StopImpersonationController::class)->middleware('auth')->name('impersonation.stop');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    Route::post('location', SwitchLocationController::class)->name('location.switch');

    // Leads is one shared page, shown in the Marketing and Sales menus. It is opened by the
    // lead permission; each Lead is then authorized by its own location, not the selected one.
    Route::livewire('leads', 'pages::leads.index')->can(Permission::ViewLeads)->name('leads');
    Route::livewire('leads/new', 'pages::leads.create')->can('create', Lead::class)->name('leads.create');
    Route::livewire('leads/{lead}', 'pages::leads.show')->can('view', 'lead')->name('leads.show');
    Route::livewire('leads/{lead}/customer', 'pages::leads.customer')->can('resolveCustomer', 'lead')->name('leads.customer');

    // Customers is one shared page too. A customer has no location: each one is authorized by
    // what the person can already see of it, never by the location that happens to be selected.
    Route::livewire('customers', 'pages::customers.index')->can(Permission::ViewAccounts)->name('customers');
    Route::livewire('customers/new', 'pages::customers.create')->can('create', Account::class)->name('customers.create');
    Route::livewire('customers/{account}', 'pages::customers.show')->can('view', 'account')->name('customers.show');

    // A center has no page of its own: its address opens the center's primary section.
    Route::get('company', CenterController::class)->defaults('center', 'company')->name('company.home');
    Route::get('marketing', CenterController::class)->defaults('center', 'marketing')->name('marketing.home');
    Route::get('sales', CenterController::class)->defaults('center', 'sales')->name('sales.home');
    Route::get('production', CenterController::class)->defaults('center', 'production')->name('production.home');
    Route::get('finance', CenterController::class)->defaults('center', 'finance')->name('finance.home');
    Route::get('color-studio', CenterController::class)->defaults('center', 'color_studio')->name('color_studio.home');
    Route::get('settings', CenterController::class)->defaults('center', 'settings')->name('settings.home');

    // Primary sections that are not built yet. Each becomes a real page when its feature is built.
    Route::get('company/performance', SectionController::class)->defaults('section', 'company.performance')->name('company.performance');
    Route::get('marketing/performance-board', SectionController::class)->defaults('section', 'marketing.performance')->name('marketing.performance');
    Route::get('sales/performance-board', SectionController::class)->defaults('section', 'sales.performance')->name('sales.performance');
    Route::get('production/performance-board', SectionController::class)->defaults('section', 'production.performance')->name('production.performance');
    Route::get('finance/performance-board', SectionController::class)->defaults('section', 'finance.performance')->name('finance.performance');
    Route::get('color-studio/color-projects', SectionController::class)->defaults('section', 'color_studio.projects')->name('color_studio.projects');
});

require __DIR__.'/settings.php';
