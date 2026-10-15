<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeWorkPackages;
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
use App\Models\User;
use App\Models\WorkPackage;
use App\Support\Customers\CustomerActivity;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The work in progress, step 14a (docs/slice-14-work-and-closeout-plan.md;
 * product-architecture.md, 6.8 and 7.9; decision 155, questions 1 to 3).
 *
 * At Lakeview, "Oak St" is given to Sandia Brush Co. for Monday 16 to Friday
 * 20 November 2026, and the customer confirmed those dates. Tests start on
 * the Monday the work is due to start.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);

    $sandia = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($sandia, 'active', null, $this->pat);
    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($sandia, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $this->pat);
    }

    app(AwardWorkPackage::class)->award($this->project, $this->package->id, $sandia->id, $window->id, 'Rosa Alvarez', 'phone', '2026-10-14', $this->pat);
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $window->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    $this->project = $this->project->fresh();
    $this->travelTo('2026-11-16 14:00:00');
});

function workStarted($test, string $day = '2026-11-16', ?User $by = null): WorkPackage
{
    return app(RecordWork::class)->start($test->project, $test->package->id, $day, $by ?? $test->pat);
}

test('work starts and finishes, and the job moves to In progress and Work complete', function () {
    expect($this->project->production_state)->toBe(ProjectProductionState::Scheduled);

    workStarted($this);

    expect($this->package->fresh()->work_started_on->toDateString())->toBe('2026-11-16');
    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::InProgress);

    $this->travelTo('2026-11-20 20:00:00');
    app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-20', $this->pat);

    expect($this->package->fresh()->work_finished_on->toDateString())->toBe('2026-11-20');
    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::WorkComplete);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkStarted)->sole()->values_after)->toEqual(['package' => 'Whole exterior', 'day' => '2026-11-16']);
    expect(CustomerActivity::label(AuditAction::ProjectWorkFinished))->toBe('Work finished');
});

test('a job with two parts is complete only when both have finished', function () {
    $deck = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Deck', 'scope' => 'Deck.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);
    $deck->forceFill(['scheduled_first_day' => '2026-11-23', 'scheduled_last_day' => '2026-11-23'])->save();

    workStarted($this);
    app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-16', $this->pat);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::InProgress);
});

test('what cannot be recorded is refused', function () {
    expect(fn () => app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-16', $this->pat))->toThrow(ValidationException::class, 'Record that the work started first.');
    expect(fn () => workStarted($this, '2026-11-17'))->toThrow(ValidationException::class, 'The day cannot be in the future.');

    workStarted($this);

    expect(fn () => workStarted($this))->toThrow(ValidationException::class, 'This work has already started.');
    expect(fn () => app(RecordWork::class)->finish($this->project, $this->package->id, '2026-11-13', $this->pat))->toThrow(ValidationException::class, 'It cannot finish before it started, on Nov 16, 2026.');

    // Work that is not given out or has no confirmed dates does not start.
    $deck = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Deck', 'scope' => 'Deck.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);

    expect(fn () => app(RecordWork::class)->start($this->project, $deck->id, '2026-11-16', $this->pat))
        ->toThrow(ValidationException::class, 'Give this work to a subcontractor and confirm its dates with the customer first.');
});

test('work not finished after its last day is running over, and more days keep whose cause it was', function () {
    workStarted($this);

    expect($this->package->fresh()->isRunningOver(CarbonImmutable::parse('2026-11-20')))->toBeFalse();
    expect($this->package->fresh()->isRunningOver(CarbonImmutable::parse('2026-11-21')))->toBeTrue();

    $this->travelTo('2026-11-23 14:00:00');

    expect(fn () => app(RecordWork::class)->extend($this->project, $this->package->id, '2026-11-20', 'weather', null, $this->pat))->toThrow(ValidationException::class, 'Choose a day after Nov 20, 2026, its last day now.');
    expect(fn () => app(RecordWork::class)->extend($this->project, $this->package->id, '2026-11-25', 'luck', null, $this->pat))->toThrow(ValidationException::class, 'Choose whose cause it was.');
    expect(fn () => app(RecordWork::class)->extend($this->project, $this->package->id, '2026-11-25', 'other', ' ', $this->pat))->toThrow(ValidationException::class, 'Say what the other reason was.');

    $extension = app(RecordWork::class)->extend($this->project, $this->package->id, '2026-11-25', 'weather', 'Two days of rain.', $this->pat);

    expect($extension->only(['cause', 'reason', 'recorded_by']))->toBe(['cause' => 'weather', 'reason' => 'Two days of rain.', 'recorded_by' => $this->pat->id]);
    expect([$extension->previous_last_day->toDateString(), $extension->new_last_day->toDateString()])->toBe(['2026-11-20', '2026-11-25']);
    expect($this->package->fresh()->scheduled_last_day->toDateString())->toBe('2026-11-25');
    expect($this->package->fresh()->isRunningOver(CarbonImmutable::parse('2026-11-23')))->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkExtended)->sole()->values_after)->toEqual(['package' => 'Whole exterior', 'day' => '2026-11-25', 'cause' => 'weather']);
    expect(fn () => $extension->forceFill(['cause' => 'customer'])->save())->toThrow(LogicException::class);
});

test('who may record the work', function (Closure $person, bool $may) {
    $person = $person($this);

    if ($may) {
        expect(workStarted($this, by: $person)->hasStarted())->toBeTrue();

        return;
    }

    expect(fn () => workStarted($this, by: $person))->toThrow(AuthorizationException::class);
})->with([
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson' => [fn ($test) => $test->sam, false],
]);

test('the page records the work, and the workspace and schedule show it under way and running over', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->assertSee('Not started yet')
        ->assertDontSeeHtml('data-test="work-finish"')
        ->call('openWorkDay', $this->package->id, 'start')
        ->assertSet('workDay', '2026-11-16')
        ->call('saveWorkDay')
        ->assertHasNoErrors()
        ->assertSee('In progress since Nov 16, 2026')
        ->assertDontSeeHtml('data-test="reschedule-work"')
        ->assertSeeHtml('data-test="work-finish"');

    $this->travelTo('2026-11-24 14:00:00');

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSeeHtml('data-test="work-running-over"')
        ->call('openExtend', $this->package->id)
        ->set('extendLastDay', '2026-11-25')
        ->set('extendCause', 'subcontractor')
        ->call('saveExtend')
        ->assertHasNoErrors()
        ->assertSee('Last day moved from Nov 20 to Nov 25, 2026')
        ->assertSee('The subcontractor');

    $this->travelTo('2026-11-26 14:00:00');

    $workspace = Livewire::actingAs($this->manager)->test('pages::production.workspace')->html();
    $inProgress = (string) str($workspace)->after('data-test="workspace-in-progress"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();

    expect($inProgress)->toContain('1 Oak St')->toContain('Running over')->toContain('Crew: Sandia Brush Co.');

    expect(Livewire::actingAs($this->manager)->withQueryParams(['from' => '2026-11-16'])->test('pages::production.schedule')->html())
        ->toContain('data-test="schedule-running-over"');

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSee('Production: In progress')
        ->assertSee('Work started: Whole exterior, Nov 16, 2026');
});
