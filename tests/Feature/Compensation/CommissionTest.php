<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\CreateProjectForSoldProposal;
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
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;

/*
 * The sales commission ledger, step 20a (docs/slice-20-compensation-plan.md;
 * decision 172; product-architecture.md, 5.7).
 *
 * It is Wednesday 14 October 2026 at Lakeview. Sam sells "Oak St" for
 * $8,450 on straight commission, 15% of gross-margin dollars. Pat is its
 * Production Manager; its whole exterior pays $4,250. Finley is Finance for
 * every Location.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
});

function planFor($test, User $person, string $plan, string $from = '2026-10-01'): void
{
    app(ManagePay::class)->setPlan($person, $plan, $from, 'Hired on it.', $test->finley);
}

function sellOakSt($test): Project
{
    $lead = bookableLead($test->lakeview, $test->sam);
    $test->proposal = Proposal::factory()->forLead($lead)->create(['name' => 'Whole exterior', 'result' => 'sold', 'sold_cents' => 845000, 'result_at' => now()]);

    return $test->project = app(CreateProjectForSoldProposal::class)->apply($lead, $test->proposal, $test->sam);
}

/**
 * @return array<int, array{kind: string, amount: int, period: string|null}>
 */
function ledgerOf(User $person): array
{
    return CompensationEntry::query()->where('user_id', $person->id)->orderBy('id')->get()
        ->map(fn (CompensationEntry $entry): array => ['kind' => $entry->kind, 'amount' => $entry->amount_cents, 'period' => $entry->period])
        ->all();
}

function depositPaid($test, string $amount = '4,225'): void
{
    $test->travelTo('2026-10-20 15:00:00');
    $record = app(RecordPayments::class);
    $deposit = $record->recordInvoice($test->project, 'deposit', '1042', $amount, '2026-10-20', $test->finley);
    $record->recordPayment($test->project, $deposit->id, $amount, 'card', '2026-10-20', 'ch_1', null, $test->finley);
}

/**
 * Pat runs the job and submits $400 of materials; the customer signs off
 * and pays in full; Finance approves and closes it on 23 November.
 */
function closeOakSt($test): void
{
    $project = $test->project->fresh();
    app(TakeProject::class)->handle($project, $test->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-20', null, $test->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $test->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $test->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $test->pat);
    $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    $test->travelTo('2026-11-23 15:00:00');
    $project = $project->fresh();
    app(RecordJobCosts::class)->add($project, 'materials', '400', 'Sherwin-Williams', '2026-11-20', 'PO-1', null, null, $test->pat);
    app(RecordJobCosts::class)->submit($project, $test->pat);

    $record = app(RecordPayments::class);
    $final = $record->recordInvoice($project, 'final', '1077', '4,225', '2026-11-23', $test->finley);
    $record->recordPayment($project, $final->id, '4,225', 'ach', '2026-11-23', 'ach_1', null, $test->finley);

    app(ReconcileJobCosts::class)->approve($project, $test->finley);
    app(ReconcileJobCosts::class)->close($project, $test->finley);
}

/*
|--------------------------------------------------------------------------
| Straight commission
|--------------------------------------------------------------------------
*/

test('the sale projects the commission at a 50% gross margin, never to be paid', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);

    expect(ledgerOf($this->sam))->toBe([['kind' => 'projected', 'amount' => 63375, 'period' => null]]);
    expect(CompensationEntry::query()->sole()->explanation)->toBe('15% of $4,225: the $8,450 sold at a projected 50% gross margin.');
});

test('the advance is half the projection, once the full deposit is collected and the handoff is complete', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);

    expect(ledgerOf($this->sam))->toHaveCount(1);

    depositPaid($this);

    expect(ledgerOf($this->sam)[1])->toBe(['kind' => 'advance', 'amount' => 31688, 'period' => '2026-10']);
});

test('the deposit can come before the handoff; either order makes one advance', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    depositPaid($this);

    expect(ledgerOf($this->sam))->toHaveCount(1);

    completeHandoff($this->project->fresh(), $this->sam);
    completeHandoff($this->project->fresh(), $this->sam, ['otherNotes' => 'Corrected.']);

    expect(collect(ledgerOf($this->sam))->where('kind', 'advance'))->toHaveCount(1);
});

test('a smaller deposit than the policy asks for earns no advance', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);

    // 49% is not 50% (5.7).
    app(RecordPayments::class)->changeDeposit($this->project, '4,140', 'The customer asked.', User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create());
    depositPaid($this, '4,140');

    expect(collect(ledgerOf($this->sam))->where('kind', 'advance'))->toBeEmpty();
});

test('the financial close pays the final commission from the final gross margin, less the advance', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);
    depositPaid($this);
    closeOakSt($this);

    // $8,450 less $4,250 and $400 is $3,800; 15% is $570; less the $316.88 advance.
    expect(ledgerOf($this->sam)[2])->toBe(['kind' => 'final', 'amount' => 25312, 'period' => '2026-11'])
        ->and(CompensationEntry::query()->where('kind', 'final')->sole()->explanation)->toBe('Final gross margin $3,800: 15% is $570, less $316.88 already in the ledger for this job.');
});

test('a job closed again at another margin is a gross-margin adjustment', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);
    depositPaid($this);
    closeOakSt($this);

    $this->travelTo('2026-12-02 15:00:00');
    app(ReconcileJobCosts::class)->reopen($this->project, 'A supplier invoice came late.', $this->finley);
    app(RecordJobCosts::class)->add($this->project, 'materials', '300', 'Sherwin-Williams', '2026-12-01', 'PO-3', 'The late invoice.', null, $this->pat);
    app(ReconcileJobCosts::class)->approve($this->project, $this->finley);
    app(ReconcileJobCosts::class)->close($this->project, $this->finley);

    // $3,500 at 15% is $525: $45 less than the $570 already paid.
    expect(ledgerOf($this->sam)[3])->toBe(['kind' => 'margin_adjustment', 'amount' => -4500, 'period' => '2026-12']);
});

test('a cancelled job claws back the advance in the next pay period, and selling it again advances again', function () {
    planFor($this, $this->sam, 'sales_straight');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);
    depositPaid($this);

    $this->travelTo('2026-11-02 15:00:00');
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();
    app(CancelProjectForCorrectedSale::class)->apply($this->proposal, 'Sold by mistake.', $manager);

    expect(ledgerOf($this->sam)[2])->toBe(['kind' => 'clawback', 'amount' => -31688, 'period' => '2026-11']);

    app(CreateProjectForSoldProposal::class)->apply($this->project->lead, $this->proposal, $this->sam);

    expect(collect(ledgerOf($this->sam))->pluck('kind')->all())->toBe(['projected', 'advance', 'clawback', 'projected', 'advance']);
});

/*
|--------------------------------------------------------------------------
| The other plans
|--------------------------------------------------------------------------
*/

test('a base plan earns the final commission only', function () {
    planFor($this, $this->sam, 'sales_base_45000');
    sellOakSt($this);
    completeHandoff($this->project, $this->sam);
    depositPaid($this);
    closeOakSt($this);

    expect(ledgerOf($this->sam))->toBe([
        ['kind' => 'projected', 'amount' => 8450, 'period' => null],
        ['kind' => 'final', 'amount' => 7600, 'period' => '2026-11'],
    ]);
});

test('a sale pays on the plan of the day it sold, and nobody without a plan has entries', function () {
    sellOakSt($this);

    expect(ledgerOf($this->sam))->toBe([]);

    // A plan set later, from before the sale, is the sale's plan when the job closes.
    planFor($this, $this->sam, 'sales_base_35000', '2026-10-01');
    planFor($this, $this->sam, 'sales_straight', '2026-11-01');
    completeHandoff($this->project, $this->sam);
    depositPaid($this);
    closeOakSt($this);

    expect(ledgerOf($this->sam))->toBe([['kind' => 'final', 'amount' => 22800, 'period' => '2026-11']]);
});
