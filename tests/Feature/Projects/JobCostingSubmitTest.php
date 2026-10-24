<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectAccuracyStrike;
use App\Models\ProjectCost;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Projects\JobCosting;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Submitting job costing, corrections and accuracy strikes, step 19b
 * (docs/slice-19-job-costing-plan.md; decision 170, questions 6 and 7;
 * decision 133).
 *
 * It is Monday 23 November 2026 at Lakeview. "Oak St" sold for $8,450; its
 * whole exterior paid $4,250 and the customer has signed off. Pat is its
 * Production Manager, Morgan manages Lakeview, Lee is in Leadership, Sam
 * sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->lee = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leadership']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = jobCostedProject($this, 'Whole exterior — 1 Oak St');
    $this->travelTo('2026-11-23 15:00:00');
});

/**
 * A sold job at Lakeview with Pat as its Production Manager and one paid-out
 * package, signed off unless asked otherwise.
 */
function jobCostedProject($test, string $title, bool $signedOff = true): Project
{
    $project = soldProject($test->lakeview, $test->sam, ['title' => $title]);
    completeHandoff($project, $test->sam);
    app(TakeProject::class)->handle($project, $test->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-14', null, $test->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-16', '2026-11-27', null, null, $test->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $test->pat);
    app(ChangeWorkPackages::class)->setPayout($project, $package->id, '4250', null, $test->pat);

    if ($signedOff) {
        $project->forceFill(['production_state' => ProjectProductionState::SignedOff])->save();
    }

    return $project->fresh();
}

function jobCost($test, Project $project, string $kind = 'materials', string $amount = '400', ?string $purchaseOrder = 'PO-1', ?string $reason = null): ProjectCost
{
    return app(RecordJobCosts::class)->add($project, $kind, $amount, 'Sherwin-Williams', '2026-11-20', $purchaseOrder, $reason, null, $test->pat);
}

function costingState($test): string
{
    return JobCosting::for($test->project->fresh())->state();
}

/*
|--------------------------------------------------------------------------
| Submitting
|--------------------------------------------------------------------------
*/

test('job costing is submitted once the customer has signed off, keeping the provisional gross margin', function () {
    jobCost($this, $this->project);

    $step = app(RecordJobCosts::class)->submit($this->project, $this->pat);

    expect($step->only(['kind', 'revenue_cents', 'payouts_cents', 'costs_cents', 'margin_cents']))
        ->toBe(['kind' => 'submitted', 'revenue_cents' => 845000, 'payouts_cents' => 425000, 'costs_cents' => 40000, 'margin_cents' => 380000])
        ->and($step->marginWords())->toBe('$3,800 (45%)')
        ->and(costingState($this))->toBe(JobCosting::SUBMITTED);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectJobCostingSubmitted)->sole()->values_after)->toEqual(['revenue_cents' => 845000, 'margin_cents' => 380000]);

    expect(fn () => app(RecordJobCosts::class)->submit($this->project, $this->pat))->toThrow(ValidationException::class, 'Job costing was submitted already.');
});

test('it is not submitted before the signoff, nor with a work package missing its payout', function () {
    $early = jobCostedProject($this, 'Kitchen — 9 Elm St', signedOff: false);
    expect(fn () => app(RecordJobCosts::class)->submit($early, $this->pat))->toThrow(ValidationException::class, 'The customer has not signed off yet.');

    app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Garage', 'scope' => 'The garage.', 'workingDays' => '1', 'minimumCrew' => '2'], $this->pat);
    expect(fn () => app(RecordJobCosts::class)->submit($this->project, $this->pat))->toThrow(ValidationException::class, 'Every work package needs its payout first.');
});

/*
|--------------------------------------------------------------------------
| Corrections and strikes
|--------------------------------------------------------------------------
*/

test('materials and rentals without a purchase order are struck at submission; labour is not', function () {
    jobCost($this, $this->project, 'materials', '400', null);
    jobCost($this, $this->project, 'rental', '120', null);
    jobCost($this, $this->project, 'labour', '600', null);

    app(RecordJobCosts::class)->submit($this->project, $this->pat);

    expect(ProjectAccuracyStrike::query()->pluck('cause')->all())->toBe(['missing_purchase_order', 'missing_purchase_order'])
        ->and(ProjectAccuracyStrike::query()->first()->production_manager_name)->toBe('Pat Production');
});

test('after submission a cost needs why, and a large one is a strike', function () {
    app(RecordJobCosts::class)->submit($this->project, $this->pat);

    expect(fn () => jobCost($this, $this->project))->toThrow(ValidationException::class, 'Job costing was submitted. Say why this cost is added now.');

    $small = jobCost($this, $this->project, 'materials', '200', 'PO-2', 'A forgotten gallon.');
    expect($small->after_submission)->toBeTrue()
        ->and(ProjectAccuracyStrike::query()->count())->toBe(0);

    jobCost($this, $this->project, 'materials', '300', 'PO-3', 'The trim primer.');
    expect(ProjectAccuracyStrike::query()->sole()->cause)->toBe('large_correction');
});

test('voiding a large cost after submission is a strike too', function () {
    $cost = jobCost($this, $this->project, 'labour', '900', null);
    app(RecordJobCosts::class)->submit($this->project, $this->pat);

    app(RecordJobCosts::class)->void($this->project, $cost->id, 'Recorded on the wrong job.', $this->pat);

    expect(ProjectAccuracyStrike::query()->sole()->only(['cause', 'amount_cents']))->toBe(['cause' => 'large_correction', 'amount_cents' => 90000]);
});

test('the third strike in 90 days tells the Location\'s managers and Leadership, and nobody else', function () {
    Notification::fake();
    app(RecordJobCosts::class)->submit($this->project, $this->pat);

    jobCost($this, $this->project, 'materials', '100', null, 'First.');
    jobCost($this, $this->project, 'materials', '100', null, 'Second.');
    Notification::assertNothingSent();

    jobCost($this, $this->project, 'materials', '100', null, 'Third.');

    Notification::assertSentTo([$this->manager, $this->lee], ProjectNotice::class, fn (ProjectNotice $notice): bool => $notice->kind === ProjectNotice::ACCURACY_STRIKES && $notice->name === 'Pat Production');
    Notification::assertNotSentTo([$this->pat, $this->sam], ProjectNotice::class);
});

test('strikes older than 90 days do not count toward the third', function () {
    Notification::fake();
    app(RecordJobCosts::class)->submit($this->project, $this->pat);
    jobCost($this, $this->project, 'materials', '100', null, 'First.');
    jobCost($this, $this->project, 'materials', '100', null, 'Second.');

    $this->travelTo('2027-03-01 15:00:00');
    jobCost($this, $this->project, 'materials', '100', null, 'Much later.');

    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the job offers the submission once signed off, and shows the provisional margin and the strikes', function () {
    jobCost($this, $this->project, 'materials', '400', null);

    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertSee('Open')
        ->call('openSubmit')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertDispatched('project-job-costing-changed')
        ->assertSee('Provisional gross margin, as submitted by Pat Production: $3,800 (45%)')
        ->assertSee('Accuracy strike: no purchase order, $400')
        ->assertSee('Pat Production has 1 accuracy strike in the last 90 days.')
        ->assertDontSeeHtml('data-test="submit-job-costing"');

    $early = jobCostedProject($this, 'Kitchen — 9 Elm St', signedOff: false);
    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $early])
        ->assertSee('Job costing is submitted once the customer has signed off.')
        ->assertDontSeeHtml('data-test="submit-job-costing"');
});
