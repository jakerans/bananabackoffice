<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\CompleteProject;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\RecordWork;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\ProjectCompletionStep;
use App\Models\PunchItem;
use App\Models\Subcontractor;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The walkthrough and signoff, step 14b (docs/slice-14-work-and-closeout-plan.md;
 * product-architecture.md, 6.8; decision 155, questions 5 to 8).
 *
 * At Lakeview, Sandia Brush Co. painted the whole exterior of "Oak St" from
 * 16 to 20 November 2026. It is Monday 23 November; the work is complete.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);

    $this->sandia = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($this->sandia, 'active', null, $this->pat);
    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($this->sandia, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $this->pat);
    }

    app(AwardWorkPackage::class)->award($this->project, $this->package->id, $this->sandia->id, $window->id, 'Rosa Alvarez', 'phone', '2026-10-14', $this->pat);
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $window->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    $this->travelTo('2026-11-16 14:00:00');
    app(RecordWork::class)->start($this->project, $this->package->id, '2026-11-16', $this->pat);
    $this->travelTo('2026-11-23 14:00:00');
    app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-20', $this->pat);
    $this->project = $this->project->fresh();
});

function inspected($test): ProjectCompletionStep
{
    return app(CompleteProject::class)->inspect($test->project, '2026-11-23', 'Pat Production', null, $test->pat);
}

function walkedThrough($test, ?int $subcontractorId = null): ProjectCompletionStep
{
    return app(CompleteProject::class)->walkthrough($test->project, '2026-11-23', $subcontractorId === null ? 'Pat Production' : 'Rosa Alvarez', $subcontractorId, null, $test->pat);
}

test('inspection, walkthrough, punch list and signoff, in that order, and the job is Signed off', function () {
    expect(fn () => walkedThrough($this))->toThrow(ValidationException::class, 'Record the internal inspection first.');

    $inspection = inspected($this);

    expect($inspection->only(['kind', 'person', 'recorded_by']))->toBe(['kind' => 'inspection', 'person' => 'Pat Production', 'recorded_by' => $this->pat->id]);
    expect(fn () => app(CompleteProject::class)->addPunchItem($this->project, 'Trim', 'banana', $this->pat))->toThrow(ValidationException::class, 'Record the customer walkthrough first.');

    walkedThrough($this, $this->sandia->id);

    $item = app(CompleteProject::class)->addPunchItem($this->project, '  Touch up the garage door trim.  ', 'subcontractor', $this->pat);

    expect($item->only(['description', 'fixer', 'done_on']))->toBe(['description' => 'Touch up the garage door trim.', 'fixer' => 'subcontractor', 'done_on' => null]);
    expect(fn () => app(CompleteProject::class)->signOff($this->project, 'Pat Customer', 'phone', '2026-11-23', true, null, $this->pat))
        ->toThrow(ValidationException::class, 'Tick the 1 open punch item done before the customer signs off.');

    app(CompleteProject::class)->finishPunchItem($this->project, $item->id, '2026-11-23', $this->pat);

    expect($item->fresh()->isDone())->toBeTrue();

    app(CompleteProject::class)->signOff($this->project, 'Pat Customer', 'in_person', '2026-11-23', true, null, $this->pat);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::SignedOff);
    expect(fn () => inspected($this))->toThrow(ValidationException::class, 'The customer has signed off this job.');

    expect(CustomerActivity::actions())->toContain(AuditAction::ProjectSignedOff)->not->toContain(AuditAction::ProjectPunchItemAdded);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectSignedOff)->sole()->values_after)->toEqual(['day' => '2026-11-23', 'person' => 'Pat Customer']);
});

test('a customer not fully satisfied is recorded with what is wrong, and the job waits', function () {
    inspected($this);
    walkedThrough($this);

    expect(fn () => app(CompleteProject::class)->signOff($this->project, 'Pat Customer', 'phone', '2026-11-23', false, ' ', $this->pat))
        ->toThrow(ValidationException::class, 'Say what they are not satisfied with.');

    app(CompleteProject::class)->signOff($this->project, 'Pat Customer', 'phone', '2026-11-23', false, 'A drip on the porch railing.', $this->pat);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::WorkComplete);
    expect(ProjectCompletionStep::query()->where('kind', 'not_satisfied')->sole()->note)->toBe('A drip on the porch railing.');
});

test('nothing is recorded before the work is complete, nor in the future, nor by those who may not', function () {
    $other = soldProject($this->lakeview, $this->sam);
    completeHandoff($other, $this->sam);
    app(TakeProject::class)->handle($other, $this->pat);

    expect(fn () => app(CompleteProject::class)->inspect($other, '2026-11-23', 'Pat', null, $this->pat))->toThrow(ValidationException::class, 'Record that all the work has finished first.');
    expect(fn () => app(CompleteProject::class)->inspect($this->project, '2026-11-24', 'Pat', null, $this->pat))->toThrow(ValidationException::class, 'The day cannot be in the future.');
    expect(fn () => app(CompleteProject::class)->inspect($this->project, '2026-11-23', 'Pat', null, $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => walkedThrough($this, Subcontractor::factory()->create()->id))->toThrow(ValidationException::class);

    // A step and a punch item are kept as they were.
    $step = inspected($this);
    walkedThrough($this);
    $item = app(CompleteProject::class)->addPunchItem($this->project, 'Trim', 'banana', $this->pat);
    app(CompleteProject::class)->finishPunchItem($this->project, $item->id, '2026-11-23', $this->pat);

    expect(fn () => $step->forceFill(['person' => 'Someone'])->save())->toThrow(LogicException::class);
    expect(fn () => $item->fresh()->forceFill(['done_on' => '2026-11-22'])->save())->toThrow(LogicException::class);
    expect(fn () => PunchItem::query()->first()->delete())->toThrow(LogicException::class);
});

test('the page takes the job from inspection to signoff, and the project shows Signed off', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])->assertSeeHtml('data-test="project-completion"');

    $part = Livewire::actingAs($this->pat)->test('pages::projects.completion', ['project' => $this->project])
        ->assertSee('Not done yet.')
        ->call('openStep', 'inspection')
        ->assertSet('completionPerson', 'Pat Production')
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSee('Done on Nov 23, 2026 by Pat Production.')
        ->call('openStep', 'walkthrough')
        ->set('walkthroughSubcontractorId', (string) $this->sandia->id)
        ->assertSet('completionPerson', 'Rosa Alvarez')
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSee('conducted by Rosa Alvarez (Sandia Brush Co.)')
        ->call('openPunch')
        ->set('punchDescription', 'Garage door trim.')
        ->call('savePunch')
        ->assertHasNoErrors()
        ->assertSee('1 punch item is still open.');

    $item = PunchItem::query()->sole();

    $part->call('openPunchDone', $item->id)
        ->call('savePunchDone')
        ->assertHasNoErrors()
        ->call('openStep', 'signoff')
        ->call('saveStep')
        ->assertHasErrors(['signoffMethod'])
        ->set('signoffMethod', 'in_person')
        ->call('saveStep')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="completion-signed-off"')
        ->assertDontSeeHtml('data-test="add-punch-item"');

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSee('Production: Signed off')
        ->assertSee('Customer signed off, 100% satisfied');
});
