<?php

use App\Actions\Projects\AssignProductionManager;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ColorApprovalMethod;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackageSchedule;
use App\Models\WorkPackageWindow;
use App\Notifications\ProjectNotice;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The confirmed schedule, step 11b (docs/slice-11-schedule-plan.md;
 * product-architecture.md, 6.5; decision 144, questions 5 to 7).
 *
 * At Lakeview, "Oak St" was sold by Sam Sales and is ready to schedule: Pat
 * Production has it, called the customer and set a rough timeframe on
 * Thursday 8 October 2026 at 3:00 pm Eastern. Its whole exterior is one
 * package of five working days, and Pat offered the customer Monday 16 to
 * Friday 20 November. Tests start at that moment.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-08', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);

    $this->exterior = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    $this->offered = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->exterior->id, '2026-11-16', '2026-11-20', $this->pat);
    $this->project = $this->project->fresh();
});

/**
 * Pat records that the customer confirmed a window by phone today.
 */
function confirmed($test, ?WorkPackageWindow $window = null, ?User $by = null): WorkPackageSchedule
{
    return app(ChangeWorkPackages::class)->confirmWindow($test->project, ($window ?? $test->offered)->id, 'Pat Customer', 'phone', '2026-10-08', $by ?? $test->pat);
}

/**
 * The notices of one kind a person has.
 *
 * @return list<array<string, mixed>>
 */
function noticesOf(User $user, string $kind): array
{
    return $user->fresh()->notifications->map->data->filter(fn (array $data): bool => $data['kind'] === $kind)->values()->all();
}

/**
 * The Work and schedule part, as a staff member sees it.
 */
function schedulePart(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.work', ['project' => $project]);
}

/*
|--------------------------------------------------------------------------
| Confirming
|--------------------------------------------------------------------------
*/

test('the customer confirms a window: the work has those dates, the job is scheduled and the salesperson is told', function () {
    $schedule = confirmed($this);

    expect($schedule->only(['work_package_id', 'work_package_window_id', 'confirmed_by_customer', 'reason', 'recorded_by', 'recorded_by_name']))->toBe([
        'work_package_id' => $this->exterior->id,
        'work_package_window_id' => $this->offered->id,
        'confirmed_by_customer' => 'Pat Customer',
        'reason' => null,
        'recorded_by' => $this->pat->id,
        'recorded_by_name' => 'Pat Production',
    ]);
    expect($schedule->confirmation_method)->toBe(ColorApprovalMethod::Phone);
    expect([$schedule->first_day->toDateString(), $schedule->last_day->toDateString(), $schedule->confirmed_on->toDateString()])->toBe(['2026-11-16', '2026-11-20', '2026-10-08']);

    $package = $this->exterior->fresh();

    expect($package->isScheduled())->toBeTrue();
    expect([$package->scheduled_first_day->toDateString(), $package->scheduled_last_day->toDateString()])->toBe(['2026-11-16', '2026-11-20']);
    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::Scheduled);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectScheduleConfirmed)->sole();

    expect($event->values_after)->toEqual([
        'package' => 'Whole exterior', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20',
        'confirmed_by' => 'Pat Customer', 'method' => 'phone', 'confirmed_on' => '2026-10-08', 'production' => 'scheduled',
    ]);
    expect(CustomerActivity::actions())->toContain(AuditAction::ProjectScheduleConfirmed);
    expect(CustomerActivity::label(AuditAction::ProjectScheduleConfirmed))->toBe('Dates confirmed by the customer');

    // The notice names the days and nothing about the customer or the address.
    $notices = noticesOf($this->sam, ProjectNotice::SCHEDULED);

    expect($notices)->toHaveCount(1);
    expect($notices[0]['message'])->toBe('A job you sold is scheduled: Mon, Nov 16 – Fri, Nov 20, 2026. The customer confirmed the dates.');
    expect($notices[0]['message'])->not->toContain('Oak St');
    expect($notices[0]['url'])->toBe(route('projects.show', $this->project));
});

test('a job split in two is scheduled when both parts have their dates, and the salesperson is told once', function () {
    $deck = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Deck', 'scope' => 'The deck.', 'workingDays' => '2', 'minimumCrew' => '1'], $this->pat);
    $deckWindow = app(ChangeWorkPackages::class)->proposeWindow($this->project, $deck->id, '2026-11-23', '2026-11-24', $this->pat);

    confirmed($this);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::PreProduction);
    expect($this->project->fresh()->scheduledDays())->toBeNull();
    expect(noticesOf($this->sam, ProjectNotice::SCHEDULED))->toBe([]);

    confirmed($this, $deckWindow);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::Scheduled);
    expect(array_map(fn ($day) => $day->toDateString(), $this->project->fresh()->scheduledDays()))->toBe(['2026-11-16', '2026-11-24']);
    expect(noticesOf($this->sam, ProjectNotice::SCHEDULED))->toHaveCount(1);
    expect(noticesOf($this->sam, ProjectNotice::SCHEDULED)[0]['message'])->toContain('Mon, Nov 16 – Tue, Nov 24, 2026');
});

test('a confirmation that is not complete is refused at each field, and nothing is saved', function (array $with, string $field, string $message) {
    $values = ['by' => 'Pat Customer', 'method' => 'phone', 'on' => '2026-10-08', ...$with];

    expect(fn () => app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->offered->id, $values['by'], $values['method'], $values['on'], $this->pat))
        ->toThrow(ValidationException::class, $message);

    try {
        app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->offered->id, $values['by'], $values['method'], $values['on'], $this->pat);
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe([$field]);
    }

    expect(WorkPackageSchedule::query()->count())->toBe(0);
    expect($this->exterior->fresh()->isScheduled())->toBeFalse();
    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::PreProduction);
})->with([
    'nobody named' => [['by' => ' '], 'confirmedBy', 'Say who confirmed the dates.'],
    'a name too long' => [['by' => str_repeat('a', 121)], 'confirmedBy', 'Keep this to 120 characters or fewer.'],
    'no way given' => [['method' => ''], 'confirmationMethod', 'Choose how they confirmed.'],
    'a way that is not one' => [['method' => 'carrier_pigeon'], 'confirmationMethod', 'Choose how they confirmed.'],
    'no day' => [['on' => ''], 'confirmedOn', 'Choose the day they confirmed.'],
    'a day that does not exist' => [['on' => '2026-09-31'], 'confirmedOn', 'Choose the day they confirmed.'],
    'a day to come' => [['on' => '2026-10-09'], 'confirmedOn', 'The day they confirmed cannot be in the future.'],
]);

test('work that has its dates is not confirmed again, and dates that have started cannot be confirmed', function () {
    $second = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->exterior->id, '2026-11-23', '2026-11-27', $this->pat);
    confirmed($this);

    expect(fn () => confirmed($this, $second))->toThrow(ValidationException::class, 'This work already has dates the customer confirmed. Reschedule it to change them.');

    // Another job's window offered on Monday the 12th, confirmed only once it has started.
    $other = soldProject($this->lakeview, $this->sam);
    completeHandoff($other, $this->sam);
    app(TakeProject::class)->handle($other, $this->pat);
    app(RecordWelcomeCall::class)->handle($other, '2026-10-08', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($other, '2026-10-12', '2026-10-16', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($other, ['name' => 'Trim', 'scope' => 'Trim.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);
    $soon = app(ChangeWorkPackages::class)->proposeWindow($other, $package->id, '2026-10-12', '2026-10-12', $this->pat);

    $this->travelTo('2026-10-13 14:00:00');

    expect(fn () => app(ChangeWorkPackages::class)->confirmWindow($other, $soon->id, 'Pat Customer', 'phone', '2026-10-13', $this->pat))
        ->toThrow(ValidationException::class, 'These dates have started or passed. Offer new ones.');

    // A window of another job is refused as no longer offered.
    expect(fn () => app(ChangeWorkPackages::class)->confirmWindow($this->project, $soon->id, 'Pat Customer', 'phone', '2026-10-13', $this->pat))
        ->toThrow(ValidationException::class, 'These dates are no longer offered. Reload the page.');
});

test('once scheduled, more dates are not offered and the work is not removed; new work takes the job back to pre-production', function () {
    confirmed($this);

    expect(fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->exterior->id, '2026-11-23', '2026-11-27', $this->pat))
        ->toThrow(ValidationException::class, 'This work has dates the customer confirmed. Reschedule it instead.');
    expect(fn () => app(ChangeWorkPackages::class)->removePackage($this->project, $this->exterior->id, $this->pat))
        ->toThrow(ValidationException::class, 'This work has dates the customer confirmed. Reschedule it rather than removing it.');

    app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Fence', 'scope' => 'The fence.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::PreProduction);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageAdded)->latest('id')->first()->values_after)->toMatchArray(['name' => 'Fence', 'production' => 'pre_production']);
});

test('changing the production manager of a scheduled job keeps it scheduled', function () {
    confirmed($this);

    app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pam->id, 'Pat is on leave.', $this->manager);

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::Scheduled);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectProductionManagerChanged)->sole()->values_after)->toMatchArray(['production' => 'scheduled']);
});

/*
|--------------------------------------------------------------------------
| Rescheduling
|--------------------------------------------------------------------------
*/

test('rescheduling needs a reason, keeps the dates it had and tells the salesperson', function () {
    $first = confirmed($this);

    $this->travelTo('2026-10-12 15:00:00');

    $moved = app(ChangeWorkPackages::class)->reschedule($this->project, $this->exterior->id, '2026-11-30', '2026-12-04', '  Rain forecast all week.  ', 'Pat Customer', 'text', '2026-10-12', $this->pat);

    expect($moved->reason)->toBe('Rain forecast all week.');
    expect($moved->work_package_window_id)->toBeNull();
    expect($moved->confirmation_method)->toBe(ColorApprovalMethod::Text);
    expect([$this->exterior->fresh()->scheduled_first_day->toDateString(), $this->exterior->fresh()->scheduled_last_day->toDateString()])->toBe(['2026-11-30', '2026-12-04']);

    // The dates it had are kept exactly as they were.
    expect($first->fresh()->only(['reason', 'confirmed_by_customer']))->toBe(['reason' => null, 'confirmed_by_customer' => 'Pat Customer']);
    expect($first->fresh()->first_day->toDateString())->toBe('2026-11-16');
    expect($this->exterior->schedules()->pluck('id')->all())->toBe([$moved->id, $first->id]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectRescheduled)->sole();

    expect($event->reason)->toBe('Rain forecast all week.');
    expect($event->values_before)->toEqual(['package' => 'Whole exterior', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20']);
    expect($event->values_after)->toMatchArray(['first_day' => '2026-11-30', 'last_day' => '2026-12-04']);
    expect(CustomerActivity::label(AuditAction::ProjectRescheduled))->toBe('Rescheduled');

    expect($this->project->fresh()->production_state)->toBe(ProjectProductionState::Scheduled);
    expect(noticesOf($this->sam, ProjectNotice::RESCHEDULED)[0]['message'])->toBe('A job you sold was rescheduled: it is now Mon, Nov 30 – Fri, Dec 4, 2026.');
});

test('a reschedule that cannot be made is refused, and nothing changes', function (array $with, string $field, string $message) {
    confirmed($this);
    $values = ['first' => '2026-11-30', 'last' => '2026-12-04', 'reason' => 'Rain.', 'by' => 'Pat Customer', 'method' => 'phone', 'on' => '2026-10-08', ...$with];

    try {
        app(ChangeWorkPackages::class)->reschedule($this->project, $this->exterior->id, $values['first'], $values['last'], $values['reason'], $values['by'], $values['method'], $values['on'], $this->pat);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe([$field => [$message]]);
    }

    expect(WorkPackageSchedule::query()->count())->toBe(1);
    expect($this->exterior->fresh()->scheduled_first_day->toDateString())->toBe('2026-11-16');
    expect(noticesOf($this->sam, ProjectNotice::RESCHEDULED))->toBe([]);
})->with([
    'no reason' => [['reason' => '  '], 'rescheduleReason', 'Say why the dates are changing.'],
    'a reason too long' => [['reason' => str_repeat('a', WorkPackageSchedule::REASON_LENGTH + 1)], 'rescheduleReason', 'Keep this to 1000 characters or fewer.'],
    'the same dates' => [['first' => '2026-11-16', 'last' => '2026-11-20'], 'scheduleFirstDay', 'These are the dates it has now.'],
    'in the past' => [['first' => '2026-10-05', 'last' => '2026-10-09'], 'scheduleFirstDay', 'The first day cannot be in the past.'],
    'the wrong way round' => [['first' => '2026-12-04', 'last' => '2026-11-30'], 'scheduleLastDay', 'The last day cannot be before the first.'],
    'nobody agreed' => [['by' => ''], 'confirmedBy', 'Say who confirmed the dates.'],
]);

test('work without confirmed dates is not rescheduled', function () {
    expect(fn () => app(ChangeWorkPackages::class)->reschedule($this->project, $this->exterior->id, '2026-11-30', '2026-12-04', 'Rain.', 'Pat Customer', 'phone', '2026-10-08', $this->pat))
        ->toThrow(ValidationException::class, 'This work has no confirmed dates yet.');
});

test('dates a job had cannot be changed or deleted', function () {
    $kept = confirmed($this);

    expect(fn () => $kept->forceFill(['reason' => 'Changed'])->save())->toThrow(LogicException::class);
    expect(fn () => $kept->delete())->toThrow(LogicException::class);
});

test('who may confirm and reschedule', function (Closure $person, bool $may) {
    $person = $person($this);

    if ($may) {
        expect(confirmed($this, by: $person)->recorded_by)->toBe($person->id);
        expect(app(ChangeWorkPackages::class)->reschedule($this->project, $this->exterior->id, '2026-11-30', '2026-12-04', 'Rain.', 'Pat Customer', 'phone', '2026-10-08', $person)->exists)->toBeTrue();

        return;
    }

    expect(fn () => confirmed($this, by: $person))->toThrow(AuthorizationException::class);

    confirmed($this);

    expect(fn () => app(ChangeWorkPackages::class)->reschedule($this->project, $this->exterior->id, '2026-11-30', '2026-12-04', 'Rain.', 'Pat Customer', 'phone', '2026-10-08', $person))
        ->toThrow(AuthorizationException::class);
    expect(WorkPackageSchedule::query()->count())->toBe(1);
})->with([
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'an Owner' => [fn ($test) => $test->owner, true],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

/*
|--------------------------------------------------------------------------
| Where people see it
|--------------------------------------------------------------------------
*/

test('the production manager confirms and reschedules from the page, and the history shows', function () {
    $part = schedulePart($this->pat, $this->project)
        ->assertSeeHtml('data-test="confirm-work-window"')
        ->call('openConfirm', $this->offered->id)
        ->assertSet('confirmedOn', '2026-10-08')
        ->assertSee('Mon, Nov 16 – Fri, Nov 20, 2026 for Whole exterior.')
        ->call('confirmWindow')
        ->assertHasErrors(['confirmationMethod'])
        ->set('confirmationMethod', 'phone')
        ->call('confirmWindow')
        ->assertHasNoErrors()
        ->assertDispatched('project-work-changed')
        ->assertSeeHtml('data-test="work-scheduled"')
        ->assertSee('Confirmed with the customer')
        ->assertSee('5 working days')
        ->assertDontSeeHtml('data-test="offer-work-window"')
        ->assertDontSeeHtml('data-test="remove-work-package"')
        ->assertDontSeeHtml('data-test="work-schedule-history"');

    $part->call('openReschedule', $this->exterior->id)
        ->assertSet('scheduleFirstDay', '2026-11-16')
        ->set('scheduleFirstDay', '2026-11-30')
        ->set('scheduleLastDay', '2026-12-04')
        ->set('confirmationMethod', 'email')
        ->call('reschedule')
        ->assertHasErrors(['rescheduleReason'])
        ->set('rescheduleReason', 'Rain forecast all week.')
        ->call('reschedule')
        ->assertHasNoErrors()
        ->assertSee('Mon, Nov 30 – Fri, Dec 4, 2026')
        ->assertSeeHtml('data-test="work-schedule-history"')
        ->assertSeeInOrder(['Now: Mon, Nov 30', 'Rain forecast all week.', 'Was: Mon, Nov 16']);

    $this->assertStringContainsString('confirmed by', strtolower($part->html()));

    // A window the browser made up is refused.
    schedulePart($this->pat, $this->project)->call('openConfirm', 987654)->assertStatus(409);
});

test('the salesperson reads the dates and is offered nothing; the job, the lead and the workspace say it is scheduled', function () {
    confirmed($this);

    schedulePart($this->sam, $this->project->fresh())
        ->assertSee('Mon, Nov 16 – Fri, Nov 20, 2026')
        ->assertDontSeeHtml('data-test="reschedule-work"')
        ->call('openReschedule', $this->exterior->id)
        ->assertForbidden();

    schedulePart($this->pam, $this->project->fresh())->call('openConfirm', $this->offered->id)->assertForbidden();

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSee('Production: Scheduled')
        ->assertSee('Dates confirmed by the customer: Whole exterior, Mon, Nov 16 – Fri, Nov 20, 2026');

    proposalsCard($this->sam, $this->project->lead)
        ->assertSeeHtml('data-test="proposal-scheduled"')
        ->assertSee('Scheduled: Mon, Nov 16 – Fri, Nov 20, 2026. Confirmed with the customer.')
        ->assertDontSeeHtml('data-test="proposal-timeframe"');

    $page = Livewire::actingAs($this->manager)->test('pages::production.workspace');
    $scheduled = (string) str($page->html())->after('data-test="workspace-scheduled"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();
    $ready = (string) str($page->html())->after('data-test="workspace-ready"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();

    // The rough timeframe is not repeated beside the dates.
    expect($scheduled)->toContain('1 Oak St')->toContain('Scheduled: Mon, Nov 16 – Fri, Nov 20, 2026')->not->toContain('Mid to late November');
    expect($ready)->not->toContain('1 Oak St')->toContain('No job is ready to schedule yet.');
});
