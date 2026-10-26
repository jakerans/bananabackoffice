<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ReconcileJobCosts;
use App\Actions\Projects\RecordBalanceAdjustments;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\User;
use App\Support\Performance\FinanceBoard;
use App\Support\Projects\JobCosting;
use App\Support\Projects\Payments;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Bad-debt write-offs, step 24c (docs/slice-24-refunds-credits-plan.md;
 * decision 181, questions 5 to 7; product-architecture.md, 10.7).
 *
 * It is Monday 23 November 2026 at Lakeview. "Oak St" sold for $8,450 on
 * Sam's straight commission; the customer paid $6,450 of the final invoice
 * sent on 1 October, and $2,000 has been with Collections since. Pat is its
 * Production Manager, Morgan manages Lakeview, Fran is Finance there.
 */

beforeEach(function () {
    $this->travelTo('2026-09-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $finley = User::factory()->withRoleEverywhere(Role::Finance)->create();
    app(ManagePay::class)->setPlan($this->sam, 'sales_straight', '2026-09-01', null, $finley);

    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-09-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-09-21', '2026-09-25', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $this->pat);
    $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    $this->travelTo('2026-10-01 15:00:00');
    $this->project = $project->fresh();
    $final = app(RecordPayments::class)->recordInvoice($this->project, 'final', '1077', '8,450', '2026-10-01', $this->fran);
    app(RecordPayments::class)->recordPayment($this->project, $final->id, '6,450', 'ach', '2026-10-01', 'ach_1', null, $this->fran);
    app(RecordJobCosts::class)->submit($this->project, $this->pat);
    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);

    $this->travelTo('2026-11-23 15:00:00');
});

function writeOffs(): RecordBalanceAdjustments
{
    return app(RecordBalanceAdjustments::class);
}

test('Finance proposes a write-off of a balance with Collections, and a manager approves it', function () {
    expect(fn () => writeOffs()->proposeWriteOff($this->project, '2,000', 'Gone.', $this->pat))->toThrow(AuthorizationException::class);

    $writeOff = writeOffs()->proposeWriteOff($this->project, '2,000', 'The customer moved away; Collections gave up.', $this->fran);

    expect(fn () => writeOffs()->approve($this->project, $writeOff->id, null, $this->fran))->toThrow(AuthorizationException::class);
    expect(fn () => writeOffs()->proposeWriteOff($this->project, '2,500', 'Too much.', $this->fran))->toThrow(ValidationException::class, 'A write-off cannot be more than the balance, $2,000.');

    writeOffs()->approve($this->project, $writeOff->id, 'JE-31', $this->manager);

    expect(Payments::for($this->project)->balanceCents())->toBe(0)
        ->and(Payments::for($this->project)->state())->toBe(Payments::PAID_IN_FULL)
        ->and(Payments::for($this->project)->writtenOffCents())->toBe(200000);
});

test('a balance not yet with Collections is not written off', function () {
    $this->travelTo('2026-10-20 15:00:00');

    expect(fn () => writeOffs()->proposeWriteOff($this->project, '2,000', 'Slow payer.', $this->fran))
        ->toThrow(ValidationException::class, 'Only a balance with Collections is written off: 30 days after the final invoice.');
});

test('once written off the job closes; its gross margin is unchanged, and the commission on the bad debt is taken back', function () {
    $writeOff = writeOffs()->proposeWriteOff($this->project, '2,000', 'Collections gave up.', $this->fran);
    writeOffs()->approve($this->project, $writeOff->id, null, $this->manager);

    app(ReconcileJobCosts::class)->close($this->project, $this->fran);
    $costing = JobCosting::for($this->project->fresh());

    expect($costing->state())->toBe(JobCosting::CLOSED)
        ->and($costing->final()->margin_cents)->toBe(420000)
        ->and($costing->badDebtCents())->toBe(200000);

    $entries = CompensationEntry::query()->where('user_id', $this->sam->id)->orderBy('id')->get(['kind', 'amount_cents'])->toArray();
    expect(collect($entries)->firstWhere('kind', 'bad_debt')['amount_cents'])->toBe(-30000)
        // The final commission is 15% of $4,200, less the advance, and does not pay the bad debt back.
        ->and(collect($entries)->firstWhere('kind', 'final')['amount_cents'])->toBe(63000 - (int) collect($entries)->where('kind', 'advance')->sum('amount_cents'));
});

test('Receivables lists what waits; the job and Finance > Performance show the bad debt', function () {
    $writeOff = writeOffs()->proposeWriteOff($this->project, '2,000', 'Collections gave up.', $this->fran);

    $this->actingAs($this->fran)->get(route('finance.receivables'))->assertOk()
        ->assertSeeInOrder(['Credits, refunds and write-offs waiting for approval', 'Whole exterior — 1 Oak St', 'Bad-debt write-off', '$2,000']);

    Livewire::actingAs($this->manager)->test('pages::projects.adjustments', ['project' => $this->project])
        ->call('openApprove', $writeOff->id)
        ->call('approve')
        ->assertHasNoErrors();

    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertSee('$2,000 written off as bad debt; after it, $2,200');

    expect(FinanceBoard::for([$this->lakeview->id], '2026-11')->adjustments())->toBe(['credits' => 0, 'refunds' => 0, 'writeOffs' => 200000]);
});
