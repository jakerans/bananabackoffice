<?php

use App\Enums\Permission;
use App\Http\Controllers\CenterController;
use App\Http\Controllers\InvitationAcceptanceController;
use App\Http\Controllers\NavigationLandingController;
use App\Http\Controllers\OpenAttachmentController;
use App\Http\Controllers\PasswordResetLinkController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StopImpersonationController;
use App\Http\Controllers\SwitchLocationController;
use App\Models\Account;
use App\Models\Lead;
use Illuminate\Support\Facades\Route;

Route::redirect('/', 'dashboard')->name('home');

// Public terms and privacy pages, which Intuit asks every QuickBooks app for (decision 201).
Route::view('legal/terms', 'legal.terms')->name('legal.terms');
Route::view('legal/privacy', 'legal.privacy')->name('legal.privacy');

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
    Route::livewire('leads/{lead}/appointments/{appointment}/report', 'pages::leads.report')->can('view', 'lead')->name('leads.report');
    Route::livewire('leads/{lead}/estimates/{estimate}', 'pages::leads.estimate')->can('view', 'estimate')->scopeBindings()->name('leads.estimate');
    Route::livewire('leads/{lead}/estimates/{estimate}/present', 'pages::leads.estimate-present')->can('view', 'estimate')->scopeBindings()->name('leads.estimate.present');
    // The customer signs for the option chosen, and the signed agreement (slice 33, step 33b; decision 211). Signing checks who may sell; the agreement page checks who may see it.
    Route::livewire('leads/{lead}/estimates/{estimate}/options/{option}/sign', 'pages::leads.estimate-sign')->can('manageProposals', 'lead')->scopeBindings()->name('leads.estimate.sign');
    Route::livewire('leads/{lead}/agreements/{agreement}', 'pages::leads.agreement')->scopeBindings()->name('leads.agreement');

    // A Location's weekly scorecard (slice 27, step 27a) is reached from Home and Company > Leadership Scorecards.
    // Its owner, the Owner and Leadership may open it; the page checks.
    Route::livewire('scorecards/{location}', 'pages::scorecards.show')->name('scorecards.show');

    // A Project is reached from its Lead, its customer and, later, Production's Workspace. It has no menu item
    // of its own (product-architecture.md, 6.2) and is authorized by its own location.
    Route::livewire('projects/{project}', 'pages::projects.show')->can('view', 'project')->name('projects.show');
    Route::livewire('projects/{project}/handoff', 'pages::projects.handoff')->can('completeHandoff', 'project')->name('projects.handoff');
    // A work order shows the payout, so it needs payouts.view (slice 13, step 13c).
    Route::livewire('projects/{project}/work-orders/{workPackage}', 'pages::projects.work-order')->can('viewPayouts', 'project')->scopeBindings()->name('projects.work-order');
    Route::livewire('production/workspace', 'pages::production.workspace')->can(Permission::ProductionAccess)->name('production.workspace');
    Route::livewire('production/schedule', 'pages::production.schedule')->can(Permission::ProductionAccess)->name('production.schedule');
    // Warranty (slice 22): the cases at the Location, and each case on its job.
    Route::livewire('production/warranty', 'pages::production.warranty')->can(Permission::ProductionAccess)->name('production.warranty');
    Route::livewire('projects/{project}/warranty/{warrantyCase}', 'pages::projects.warranty-case')->can('view', 'warrantyCase')->scopeBindings()->name('projects.warranty-case');

    // Subcontractors are seen by Production and managers only (slice 12; decision 148, question 9).
    Route::livewire('production/subcontractors', 'pages::production.subcontractors')->can(Permission::ViewSubcontractors)->name('production.subcontractors');
    Route::livewire('production/subcontractors/{subcontractor}', 'pages::production.subcontractor')->can('view', 'subcontractor')->name('production.subcontractors.show');

    // Finance > Receivables: what customers still owe, at the Locations the person has Finance for (slice 17, step 17c).
    Route::livewire('finance/receivables', 'pages::finance.receivables')->can(Permission::FinanceAccess)->name('finance.receivables');

    // Finance > Job Costing: jobs to reconcile and to close, at the Locations the person has Finance for (slice 19, step 19c).
    Route::livewire('finance/job-costing', 'pages::finance.job-costing')->can(Permission::FinanceAccess)->name('finance.job-costing');

    // Finance > Payroll: everyone's variable pay, for Finance and the Owner; and each person's own (slice 20, step 20a).
    Route::livewire('finance/payroll', 'pages::finance.payroll')->can(Permission::ManageCompensation)->name('finance.payroll');
    Route::livewire('finance/payroll/people/{user}', 'pages::pay.ledger')->can('viewPay', 'user')->name('finance.payroll.person');
    Route::livewire('pay', 'pages::pay.ledger')->name('pay');

    // An attached file opens by a five-minute link, given after checking the person may see
    // what it belongs to (slice 16; decision 33). Never a lasting address.
    Route::get('attachments/{attachment}', OpenAttachmentController::class)->name('attachments.open');

    // The Call Queue is a Marketing > Contact Center page. It is opened by the queue permission,
    // like Leads; every action on it checks the permission again and each Lead by its own location.
    Route::livewire('marketing/contact-center/queue', 'pages::marketing.call-queue')->can(Permission::WorkLeadQueue)->name('marketing.call_center.queue');
    Route::livewire('sales/calendar', 'pages::sales.calendar')->can(Permission::SalesAccess)->name('sales.calendar');
    // Sales > Workspace > Today: the salesperson's day (slice 34, step 34a; decision 212).
    Route::livewire('sales/workspace', 'pages::sales.workspace')->can(Permission::SalesAccess)->name('sales.workspace');
    Route::livewire('sales/performance-board', 'pages::sales.performance')->can(Permission::SalesAccess)->name('sales.performance');
    // Marketing spend, typed in until ad platforms are connected (slice 25, step 25a).
    Route::livewire('marketing/spend', 'pages::marketing.spend')->can(Permission::MarketingSpendManage)->name('marketing.performance.spend');

    // Sales > Leaderboard: company-wide, for everyone with Sales access (slice 23, step 23c).
    Route::livewire('sales/leaderboard', 'pages::sales.leaderboard')->can(Permission::SalesAccess)->name('sales.leaderboard');
    // The leaderboard in TV Mode (slice 27, step 27c).
    Route::livewire('sales/leaderboard/tv', 'pages::sales.leaderboard-tv')->can(Permission::SalesAccess)->name('sales.leaderboard.tv');

    // Customers is one shared page too. A customer has no location: each one is authorized by
    // what the person can already see of it, never by the location that happens to be selected.
    Route::livewire('customers', 'pages::customers.index')->can(Permission::ViewAccounts)->name('customers');
    Route::livewire('customers/new', 'pages::customers.create')->can('create', Account::class)->name('customers.create');
    Route::livewire('customers/{account}/workspace', 'pages::customers.workspace')->can('view', 'account')->name('customers.workspace');
    Route::livewire('customers/{account}', 'pages::customers.show')->can('view', 'account')->name('customers.show');

    // A center has no page of its own: its address opens the center's primary section.
    Route::get('company', CenterController::class)->defaults('center', 'company')->name('company.home');
    Route::get('marketing', CenterController::class)->defaults('center', 'marketing')->name('marketing.home');
    Route::get('sales', CenterController::class)->defaults('center', 'sales')->name('sales.home');
    Route::get('production', CenterController::class)->defaults('center', 'production')->name('production.home');
    Route::get('finance', CenterController::class)->defaults('center', 'finance')->name('finance.home');
    Route::get('color-studio', CenterController::class)->defaults('center', 'color_studio')->name('color_studio.home');
    Route::get('settings', NavigationLandingController::class)->name('settings.home');

    // Primary sections that are not built yet. Each becomes a real page when its feature is built.
    // Company > Executive Board (slice 25, step 25c).
    Route::livewire('company/performance', 'pages::company.performance')->can(Permission::CompanyOverviewAccess)->name('company.performance');
    // The Executive Board in TV Mode (slice 27, step 27c).
    Route::livewire('company/performance/tv', 'pages::company.performance-tv')->can(Permission::CompanyOverviewAccess)->name('company.performance.tv');
    // Company > Leadership Scorecards (slice 27, step 27b).
    Route::livewire('company/leadership-scorecards', 'pages::company.leadership-scorecards')->can(Permission::CompanyOverviewAccess)->name('company.leadership_scorecards');
    // Company > Needs Attention (slice 26, step 26b).
    Route::livewire('company/needs-attention', 'pages::company.needs-attention')->can(Permission::CompanyOverviewAccess)->name('company.needs_attention');
    // Marketing > Performance (slice 25, step 25b).
    Route::livewire('marketing/performance-board', 'pages::marketing.performance')->can(Permission::MarketingAccess)->name('marketing.performance');
    // Production > Performance (slice 23, step 23a).
    Route::livewire('production/performance-board', 'pages::production.performance')->can(Permission::ProductionAccess)->name('production.performance');
    // Finance > Performance (slice 23, step 23b).
    Route::livewire('finance/performance-board', 'pages::finance.performance')->can(Permission::FinanceAccess)->name('finance.performance');
    Route::get('color-studio/color-projects', SectionController::class)->defaults('section', 'color_studio.projects')->name('color_studio.projects');
});

require __DIR__.'/settings.php';
