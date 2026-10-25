<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Compensation\RecordProductionBonus;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ReconcileJobCosts;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\ProductionBonusCredit;
use App\Models\ProductionBonusMonth;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use Livewire\Livewire;

/*
 * The Production Bonus, step 20b (docs/slice-20-compensation-plan.md;
 * decision 172, question 8; product-architecture.md, 6.1).
 *
 * Pat is a Production Manager at Lakeview on the Production Bonus plan:
 * 10% of a month's gross-margin dollars above $37,500, at a margin of 55%
 * or more, paid at the end of the next month if it holds 55% too. Finley is
 * Finance for every Location.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    app(ManagePay::class)->setPlan($this->pat, 'production_bonus', '2026-01-01', null, $this->finley);
});

/**
 * Jobs closed for Pat in a month, as one credit: revenue and gross margin in dollars.
 */
function patClosed($test, string $month, int $revenueDollars, int $marginDollars): void
{
    $project = soldProject($test->lakeview, $test->sam);
    $step = new ProjectJobCostingStep;
    $step->forceFill(['project_id' => $project->id, 'kind' => 'closed', 'revenue_cents' => $revenueDollars * 100, 'payouts_cents' => 0, 'costs_cents' => 0, 'margin_cents' => $marginDollars * 100])->save();

    $credit = new ProductionBonusCredit;
    $credit->forceFill(['user_id' => $test->pat->id, 'user_name' => $test->pat->name, 'project_id' => $project->id, 'project_job_costing_step_id' => $step->id, 'month' => $month, 'revenue_cents' => $revenueDollars * 100, 'margin_cents' => $marginDollars * 100])->save();
}

function settleOctober(): ?ProductionBonusMonth
{
    app(RecordProductionBonus::class)->settle('2026-10');

    return ProductionBonusMonth::query()->where('month', '2026-10')->first();
}

test('a financial close counts for the job\'s Production Manager in its month; closed again, the difference counts', function () {
    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-01', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $this->pat);
    $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    $this->travelTo('2026-11-23 15:00:00');
    $project = $project->fresh();
    app(RecordJobCosts::class)->add($project, 'materials', '400', 'Sherwin-Williams', '2026-11-20', 'PO-1', null, null, $this->pat);
    app(RecordJobCosts::class)->submit($project, $this->pat);
    $record = app(RecordPayments::class);
    $invoice = $record->recordInvoice($project, 'final', '1077', '8,450', '2026-11-23', $this->finley);
    $record->recordPayment($project, $invoice->id, '8,450', 'ach', '2026-11-23', 'ach_1', null, $this->finley);
    app(ReconcileJobCosts::class)->approve($project, $this->finley);
    app(ReconcileJobCosts::class)->close($project, $this->finley);

    $this->travelTo('2026-12-02 15:00:00');
    app(ReconcileJobCosts::class)->reopen($project, 'A supplier invoice came late.', $this->finley);
    app(RecordJobCosts::class)->add($project, 'materials', '300', 'Sherwin-Williams', '2026-12-01', 'PO-3', 'The late invoice.', null, $this->pat);
    app(ReconcileJobCosts::class)->approve($project, $this->finley);
    app(ReconcileJobCosts::class)->close($project, $this->finley);

    expect(ProductionBonusCredit::query()->orderBy('id')->get(['user_id', 'month', 'revenue_cents', 'margin_cents'])->toArray())->toBe([
        ['user_id' => $this->pat->id, 'month' => '2026-11', 'revenue_cents' => 845000, 'margin_cents' => 380000],
        ['user_id' => $this->pat->id, 'month' => '2026-12', 'revenue_cents' => 0, 'margin_cents' => -30000],
    ]);
});

test('a month at 55% or more earns 10% above the floor, and is paid in the next month if it holds too', function () {
    patClosed($this, '2026-10', 100000, 58000);
    patClosed($this, '2026-11', 50000, 28000);

    // Not until November is over.
    $this->travelTo('2026-11-30 15:00:00');
    expect(settleOctober())->toBeNull();

    $this->travelTo('2026-12-01 06:00:00');
    $settled = settleOctober();

    expect($settled->earned_cents)->toBe(205000)
        ->and($settled->payable)->toBeTrue()
        ->and(CompensationEntry::query()->sole()->only(['user_id', 'kind', 'amount_cents', 'period']))->toBe(['user_id' => $this->pat->id, 'kind' => 'bonus', 'amount_cents' => 205000, 'period' => '2026-11'])
        ->and(CompensationEntry::query()->sole()->explanation)->toBe('October 2026: $58,000 of gross-margin dollars at 58%; 10% of the $20,500 above the $37,500 floor. November 2026 held 55%.');

    // Settled once.
    expect(settleOctober()->id)->toBe($settled->id)->and(ProductionBonusMonth::query()->count())->toBe(1);
});

test('an earned bonus is not paid when the next month falls below 55%, or closes nothing', function (?int $novemberMargin) {
    patClosed($this, '2026-10', 100000, 58000);

    if ($novemberMargin !== null) {
        patClosed($this, '2026-11', 50000, $novemberMargin);
    }

    $this->travelTo('2026-12-01 06:00:00');

    expect(settleOctober()->only(['earned_cents', 'payable']))->toBe(['earned_cents' => 205000, 'payable' => false])
        ->and(CompensationEntry::query()->count())->toBe(0);
})->with([
    'November at 50%' => [25000],
    'nothing closed in November' => [null],
]);

test('a month below 55%, or not above the floor, earns nothing', function (int $revenue, int $margin) {
    patClosed($this, '2026-10', $revenue, $margin);
    patClosed($this, '2026-11', 50000, 30000);

    $this->travelTo('2026-12-01 06:00:00');

    expect(settleOctober()->earned_cents)->toBe(0)->and(CompensationEntry::query()->count())->toBe(0);
})->with([
    '54%' => [100000, 54000],
    '60% of too little' => [50000, 30000],
]);

test('the nightly command settles each month whose next month is over', function () {
    patClosed($this, '2026-10', 100000, 58000);
    patClosed($this, '2026-11', 50000, 28000);

    $this->travelTo('2026-12-01 06:00:00');
    $this->artisan('backoffice:production-bonus')->assertSuccessful();

    expect(ProductionBonusMonth::query()->pluck('month')->all())->toBe(['2026-10']);
});

test('the Production Manager sees each month and where it stands', function () {
    patClosed($this, '2026-10', 100000, 58000);
    patClosed($this, '2026-11', 50000, 25000);

    $this->travelTo('2026-11-20 15:00:00');
    Livewire::actingAs($this->pat)->test('pages::pay.ledger')
        ->assertSeeInOrder([
            'November 2026', 'In progress: it earns at a 55% margin and above $37,500 of gross-margin dollars.',
            'October 2026', 'Earned $2,050. Paid at the end of November 2026 if November 2026 finishes at 55% or more.', '58% of $100,000',
        ]);

    $this->travelTo('2026-12-01 06:00:00');
    settleOctober();

    Livewire::actingAs($this->pat)->test('pages::pay.ledger')
        ->assertSee('Earned $2,050, not paid: November 2026 finished with $25,000 of gross-margin dollars at 50%.');
});
