<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ReconcileJobCosts;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordPayments;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\User;
use App\Support\Projects\JobCosting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Finance's reconciliation and the financial close, step 19c
 * (docs/slice-19-job-costing-plan.md; decision 170, question 8;
 * product-architecture.md, 6.8, 6.9 and 9.3).
 *
 * It is Monday 23 November 2026 at Lakeview. "Oak St" sold for $8,450; its
 * whole exterior paid $4,250, the customer signed off, and Pat submitted job
 * costing with $400 of materials. Fran is Finance at Lakeview, Hana at
 * Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->hana = User::factory()->withRoleAt(Role::Finance, $this->harbor)->create(['name' => 'Hana Harbor']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $this->pat);
    $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();

    $this->travelTo('2026-11-23 15:00:00');
    $this->project = $project->fresh();
    app(RecordJobCosts::class)->add($this->project, 'materials', '400', 'Sherwin-Williams', '2026-11-20', 'PO-1', null, null, $this->pat);
    app(RecordJobCosts::class)->submit($this->project, $this->pat);
});

function closeStateOf($test): string
{
    return JobCosting::for(Project::query()->findOrFail($test->project->id))->state();
}

function oakStPaidInFull($test): void
{
    $record = app(RecordPayments::class);
    $deposit = $record->recordInvoice($test->project, 'deposit', '1042', '4,225', '2026-10-20', $test->fran);
    $record->recordPayment($test->project, $deposit->id, '4,225', 'card', '2026-10-20', 'ch_1', null, $test->fran);
    $final = $record->recordInvoice($test->project, 'final', '1077', '4,225', '2026-11-23', $test->fran);
    $record->recordPayment($test->project, $final->id, '4,225', 'ach', '2026-11-23', 'ach_1', null, $test->fran);
}

/*
|--------------------------------------------------------------------------
| Reconciling and approving
|--------------------------------------------------------------------------
*/

test('Finance approves the final gross margin, and the Production Manager can no longer change a cost', function () {
    $step = app(ReconcileJobCosts::class)->approve($this->project, $this->fran);

    expect($step->kind)->toBe('approved')
        ->and($step->marginWords())->toBe('$3,800 (45%)')
        ->and(closeStateOf($this))->toBe(JobCosting::APPROVED)
        ->and(JobCosting::for($this->project->fresh())->final()?->id)->toBe($step->id);

    expect(fn () => app(RecordJobCosts::class)->add($this->project, 'materials', '50', 'Home Depot', '2026-11-23', 'PO-2', 'Late.', null, $this->pat))
        ->toThrow(ValidationException::class, 'Finance approved this job costing.');
});

test('Finance adds and reclassifies costs, with why, and never earns a strike', function () {
    $cost = app(ReconcileJobCosts::class)->addCost($this->project, 'disposal', '900', 'City dump', '2026-11-20', null, 'Unassigned dump fees for this job.', $this->fran);
    app(ReconcileJobCosts::class)->voidCostAsFinance($this->project, $cost->id, 'Reclassified as materials.', $this->fran);
    app(ReconcileJobCosts::class)->addCost($this->project, 'materials', '900', 'City dump', '2026-11-20', null, 'Reclassified from disposal.', $this->fran);

    expect($cost->fresh()->by_finance)->toBeTrue()
        ->and(ProjectAccuracyStrike::query()->count())->toBe(0);
    expect(fn () => app(ReconcileJobCosts::class)->addCost($this->project, 'other', '10', 'Somewhere', '2026-11-20', null, '', $this->fran))
        ->toThrow(ValidationException::class, 'Say why this cost is added now.');
});

test('only Finance approves, and only what was submitted', function () {
    expect(fn () => app(ReconcileJobCosts::class)->approve($this->project, $this->pat))->toThrow(AuthorizationException::class);
    expect(fn () => app(ReconcileJobCosts::class)->approve($this->project, $this->hana))->toThrow(AuthorizationException::class);

    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);
    expect(fn () => app(ReconcileJobCosts::class)->approve($this->project, $this->fran))->toThrow(ValidationException::class, 'Only submitted job costing is approved.');
});

/*
|--------------------------------------------------------------------------
| Closing and reopening
|--------------------------------------------------------------------------
*/

test('a job closes once approved, signed off and paid in full', function () {
    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);

    expect(fn () => app(ReconcileJobCosts::class)->close($this->project, $this->fran))->toThrow(ValidationException::class, 'It cannot close yet: payment in full.');

    oakStPaidInFull($this);
    app(ReconcileJobCosts::class)->close($this->project, $this->fran);

    expect(closeStateOf($this))->toBe(JobCosting::CLOSED);

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="project-financially-closed"')
        ->assertSee('Financially closed');
});

test('Finance reopens with why; the Production Manager corrects again, and Finance approves again', function () {
    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);

    expect(fn () => app(ReconcileJobCosts::class)->reopen($this->project, ' ', $this->fran))->toThrow(ValidationException::class, 'Say why it is reopened.');
    app(ReconcileJobCosts::class)->reopen($this->project, 'A supplier invoice came late.', $this->fran);

    expect(closeStateOf($this))->toBe(JobCosting::REOPENED)
        ->and(JobCosting::for($this->project->fresh())->final())->toBeNull();

    app(RecordJobCosts::class)->add($this->project, 'materials', '300', 'Sherwin-Williams', '2026-11-23', 'PO-3', 'The late invoice.', null, $this->pat);
    expect(ProjectAccuracyStrike::query()->sole()->cause)->toBe('large_correction');

    $again = app(ReconcileJobCosts::class)->approve($this->project, $this->fran);
    expect($again->marginWords())->toBe('$3,500 (41.4%)');
});

/*
|--------------------------------------------------------------------------
| The pages
|--------------------------------------------------------------------------
*/

test('the job offers Finance the approval, the close and the reopening in turn', function () {
    $card = fn () => Livewire::actingAs($this->fran)->test('pages::projects.job-costing', ['project' => $this->project]);

    $card()->assertSeeHtml('data-test="approve-job-costing"')
        ->call('openApprove')
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSee('Final gross margin, approved by Fran Finance: $3,800 (45%)')
        ->assertSee('It closes once it has payment in full.')
        ->assertDontSeeHtml('data-test="close-job"');

    oakStPaidInFull($this);

    $card()->assertSeeHtml('data-test="close-job"')
        ->call('openClose')
        ->call('close')
        ->assertHasNoErrors()
        ->assertSee('Financially closed by Fran Finance');

    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertDontSeeHtml('data-test="approve-job-costing"')
        ->assertDontSeeHtml('data-test="add-job-cost"');
});

test('Finance > Job Costing lists what waits, at the Locations the person has Finance for', function () {
    $page = fn (User $user) => Livewire::actingAs($user)->test('pages::finance.job-costing');

    $page($this->fran)->assertSeeInOrder(['Waiting for reconciliation', 'Whole exterior — 1 Oak St', 'Approved, waiting to close'])
        ->assertSeeInOrder(['Costs missing receipts', 'Whole exterior — 1 Oak St', '1 cost is missing its receipt.']);

    app(ReconcileJobCosts::class)->approve($this->project, $this->fran);
    $page($this->fran)->assertSeeInOrder(['Approved, waiting to close', 'Whole exterior — 1 Oak St', 'Waiting for payment in full.']);

    $page($this->hana)->assertDontSee('Whole exterior — 1 Oak St');
    $this->actingAs($this->fran)->get(route('finance.job-costing'))->assertOk();
    $this->actingAs($this->pat)->get(route('finance.job-costing'))->assertForbidden();
});
