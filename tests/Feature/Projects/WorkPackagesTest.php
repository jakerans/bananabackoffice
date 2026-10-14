<?php

use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageWindow;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Work Packages and the dates offered to the customer, step 11a
 * (docs/slice-11-schedule-plan.md; product-architecture.md, 6.5 and 6.6;
 * decision 144).
 *
 * At Lakeview, "Oak St" was sold by Sam Sales and handed over, and Pat
 * Production took it, called the customer and set a rough timeframe on
 * Thursday 8 October 2026 at 3:00 pm Eastern. It is ready to schedule. Tests
 * start at that moment.
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
    $this->project = $this->project->fresh();
});

/**
 * A package as a person types it: the whole exterior, unless told otherwise.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function workPackage(array $with = []): array
{
    return ['name' => 'Whole exterior', 'scope' => 'All four sides, trim and both doors. Two coats.', 'workingDays' => '5', 'minimumCrew' => '3', ...$with];
}

/**
 * The whole exterior, written by Pat.
 */
function wholeExterior($test): WorkPackage
{
    return app(ChangeWorkPackages::class)->addPackage($test->project, workPackage(), $test->pat);
}

/**
 * The Work and schedule part of the Project page, as a staff member sees it.
 */
function workPart(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.work', ['project' => $project]);
}

dataset('people around a project\'s work', [
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a manager at the location' => [fn ($test) => $test->manager, true],
    'an Owner' => [fn ($test) => $test->owner, true],
    'another Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson who sold it' => [fn ($test) => $test->sam, false],
    'a manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

/*
|--------------------------------------------------------------------------
| Work Packages
|--------------------------------------------------------------------------
*/

test('a package is written with its scope, working days and smallest crew, and only the audit record has it', function () {
    $package = app(ChangeWorkPackages::class)->addPackage($this->project, workPackage(['name' => '  Whole exterior  ']), $this->pat);

    expect($package->only(['project_id', 'position', 'name', 'scope', 'working_days', 'minimum_crew', 'created_by', 'created_by_name']))->toBe([
        'project_id' => $this->project->id,
        'position' => 1,
        'name' => 'Whole exterior',
        'scope' => 'All four sides, trim and both doors. Two coats.',
        'working_days' => 5,
        'minimum_crew' => 3,
        'created_by' => $this->pat->id,
        'created_by_name' => 'Pat Production',
    ]);

    $added = AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageAdded)->sole();

    expect($added->subject_id)->toBe($this->project->id);
    expect($added->actor_id)->toBe($this->pat->id);
    expect($added->values_after)->toEqual(['name' => 'Whole exterior', 'scope' => 'All four sides, trim and both doors. Two coats.', 'working_days' => 5, 'minimum_crew' => 3]);
    expect(CustomerActivity::actions())->not->toContain(AuditAction::ProjectWorkPackageAdded);

    // A second package splits the job and comes after the first.
    $deck = app(ChangeWorkPackages::class)->addPackage($this->project, workPackage(['name' => 'Deck', 'workingDays' => '2', 'minimumCrew' => '1']), $this->manager);

    expect($deck->position)->toBe(2);
    expect($this->project->workPackages()->pluck('name')->all())->toBe(['Whole exterior', 'Deck']);
});

test('a package that is incomplete or out of range is refused at each field, and nothing is saved', function (array $with, string $field) {
    expect(fn () => app(ChangeWorkPackages::class)->addPackage($this->project, workPackage($with), $this->pat))
        ->toThrow(ValidationException::class);

    try {
        app(ChangeWorkPackages::class)->addPackage($this->project, workPackage($with), $this->pat);
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe([$field]);
    }

    expect(WorkPackage::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageAdded)->count())->toBe(0);
})->with([
    'no name' => [['name' => '  '], 'package.name'],
    'a name too long' => [['name' => str_repeat('a', WorkPackage::NAME_LENGTH + 1)], 'package.name'],
    'no scope' => [['scope' => ''], 'package.scope'],
    'a scope too long' => [['scope' => str_repeat('a', WorkPackage::SCOPE_LENGTH + 1)], 'package.scope'],
    'no working days' => [['workingDays' => ''], 'package.workingDays'],
    'no working days at all' => [['workingDays' => '0'], 'package.workingDays'],
    'half a day' => [['workingDays' => '2.5'], 'package.workingDays'],
    'too many working days' => [['workingDays' => (string) (WorkPackage::MAX_WORKING_DAYS + 1)], 'package.workingDays'],
    'no crew' => [['minimumCrew' => '0'], 'package.minimumCrew'],
    'a crew that is not a number' => [['minimumCrew' => 'three'], 'package.minimumCrew'],
    'too large a crew' => [['minimumCrew' => (string) (WorkPackage::MAX_CREW + 1)], 'package.minimumCrew'],
]);

test('a package is changed, and saving it unchanged records nothing', function () {
    $package = wholeExterior($this);

    app(ChangeWorkPackages::class)->changePackage($this->project, $package->id, workPackage(), $this->pat);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageChanged)->count())->toBe(0);

    app(ChangeWorkPackages::class)->changePackage($this->project, $package->id, workPackage(['workingDays' => '6']), $this->pat);

    expect($package->fresh()->working_days)->toBe(6);

    $changed = AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageChanged)->sole();

    expect($changed->values_before)->toMatchArray(['working_days' => 5]);
    expect($changed->values_after)->toMatchArray(['working_days' => 6]);
});

test('a package is removed with the dates offered for it', function () {
    $package = wholeExterior($this);
    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);

    app(ChangeWorkPackages::class)->removePackage($this->project, $package->id, $this->pat);

    expect(WorkPackage::query()->count())->toBe(0);
    expect(WorkPackageWindow::query()->count())->toBe(0);

    $removed = AuditEvent::query()->where('action', AuditAction::ProjectWorkPackageRemoved)->sole();

    expect($removed->values_before)->toMatchArray(['name' => 'Whole exterior', 'windows' => [['package' => 'Whole exterior', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20']]]);
});

test('a package of another job is refused as no longer here', function () {
    $other = soldProject($this->lakeview, $this->sam);
    $theirs = WorkPackage::query()->forceCreate(['project_id' => $other->id, 'position' => 1, 'name' => 'Theirs', 'scope' => 'Theirs', 'working_days' => 1, 'minimum_crew' => 1]);

    foreach ([
        fn () => app(ChangeWorkPackages::class)->changePackage($this->project, $theirs->id, workPackage(), $this->pat),
        fn () => app(ChangeWorkPackages::class)->removePackage($this->project, $theirs->id, $this->pat),
        fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $theirs->id, '2026-11-16', '2026-11-20', $this->pat),
    ] as $attempt) {
        expect($attempt)->toThrow(ValidationException::class, 'This work package is no longer here. Reload the page.');
    }

    expect($theirs->fresh()->name)->toBe('Theirs');
});

/*
|--------------------------------------------------------------------------
| Dates offered to the customer
|--------------------------------------------------------------------------
*/

test('dates are offered as a first and a last working day, and are in the customer\'s activity', function () {
    $package = wholeExterior($this);

    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);

    expect($window->first_day->toDateString())->toBe('2026-11-16');
    expect($window->last_day->toDateString())->toBe('2026-11-20');
    expect($window->only(['work_package_id', 'proposed_by', 'proposed_by_name']))->toBe(['work_package_id' => $package->id, 'proposed_by' => $this->pat->id, 'proposed_by_name' => 'Pat Production']);
    expect($window->workingDays())->toBe(5);
    expect($window->words())->toBe('Mon, Nov 16 – Fri, Nov 20, 2026');

    $offered = AuditEvent::query()->where('action', AuditAction::ProjectWindowProposed)->sole();

    expect($offered->values_after)->toEqual(['package' => 'Whole exterior', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20']);
    expect(CustomerActivity::actions())->toContain(AuditAction::ProjectWindowProposed);
    expect(CustomerActivity::label(AuditAction::ProjectWindowProposed))->toBe('Dates offered to the customer');

    // More than one window can be offered; they are listed earliest first.
    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-09', '2026-11-16', $this->pat);

    expect($package->windows()->get()->map->words()->all())->toBe(['Mon, Nov 9 – Mon, Nov 16, 2026', 'Mon, Nov 16 – Fri, Nov 20, 2026']);
});

test('the working days in a window count Monday to Friday', function (string $first, string $last, int $days) {
    expect(WorkPackageWindow::workingDaysBetween(Carbon::parse($first), Carbon::parse($last)))->toBe($days);
})->with([
    'one Monday' => ['2026-11-16', '2026-11-16', 1],
    'Monday to Friday' => ['2026-11-16', '2026-11-20', 5],
    'Friday to Monday' => ['2026-11-20', '2026-11-23', 2],
    'two weeks' => ['2026-11-16', '2026-11-27', 10],
    'a weekend' => ['2026-11-21', '2026-11-22', 0],
]);

test('dates that cannot be offered are refused, and nothing is saved', function (?string $first, ?string $last, string $field, string $message) {
    $package = wholeExterior($this);

    expect(fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, $first, $last, $this->pat))
        ->toThrow(ValidationException::class, $message);

    try {
        app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, $first, $last, $this->pat);
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe([$field]);
    }

    expect(WorkPackageWindow::query()->count())->toBe(0);
})->with([
    'no first day' => [null, '2026-11-20', 'windowFirstDay', 'Choose the first day.'],
    'a day that does not exist' => ['2026-11-31', '2026-12-04', 'windowFirstDay', 'Choose the first day.'],
    'no last day' => ['2026-11-16', '', 'windowLastDay', 'Choose the last day.'],
    'in the past' => ['2026-10-07', '2026-10-09', 'windowFirstDay', 'The first day cannot be in the past.'],
    'more than two years ahead' => ['2028-10-09', '2028-10-13', 'windowFirstDay', 'Offer dates within the next two years.'],
    'the wrong way round' => ['2026-11-20', '2026-11-16', 'windowLastDay', 'The last day cannot be before the first.'],
    'too long' => ['2026-11-16', '2027-06-01', 'windowLastDay', 'A window can be 180 days long at most.'],
    'a weekend only' => ['2026-11-21', '2026-11-22', 'windowLastDay', 'A window needs at least one working day, Monday to Friday.'],
]);

test('today, in the job\'s own location, can be offered; the same dates twice cannot', function () {
    $package = wholeExterior($this);

    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-10-08', '2026-10-09', $this->pat);

    expect(fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-10-08', '2026-10-09', $this->pat))
        ->toThrow(ValidationException::class, 'These dates are already offered for this work.');

    expect(WorkPackageWindow::query()->count())->toBe(1);
});

test('no more than ten windows are offered for one package at once', function () {
    $package = wholeExterior($this);

    foreach (range(0, WorkPackage::MAX_WINDOWS - 1) as $week) {
        $monday = Carbon::parse('2026-11-16')->addWeeks($week);
        app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, $monday->toDateString(), $monday->copy()->addDays(4)->toDateString(), $this->pat);
    }

    expect(fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2027-03-01', '2027-03-05', $this->pat))
        ->toThrow(ValidationException::class, '10 windows are offered for this work already. Withdraw one first.');
});

test('offered dates are withdrawn, and that is in the customer\'s activity', function () {
    $package = wholeExterior($this);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);

    app(ChangeWorkPackages::class)->withdrawWindow($this->project, $window->id, $this->manager);

    expect(WorkPackageWindow::query()->count())->toBe(0);

    $withdrawn = AuditEvent::query()->where('action', AuditAction::ProjectWindowWithdrawn)->sole();

    expect($withdrawn->actor_id)->toBe($this->manager->id);
    expect($withdrawn->values_before)->toEqual(['package' => 'Whole exterior', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20']);
    expect(CustomerActivity::label(AuditAction::ProjectWindowWithdrawn))->toBe('Offered dates withdrawn');

    // Withdrawn twice, or a window of another job, is refused.
    expect(fn () => app(ChangeWorkPackages::class)->withdrawWindow($this->project, $window->id, $this->pat))
        ->toThrow(ValidationException::class, 'These dates are no longer offered. Reload the page.');
});

/*
|--------------------------------------------------------------------------
| Who may, and when
|--------------------------------------------------------------------------
*/

test('who may write the work and offer dates', function (Closure $person, bool $may) {
    $person = $person($this);
    $attempt = fn () => app(ChangeWorkPackages::class)->addPackage($this->project, workPackage(), $person);

    if ($may) {
        expect($attempt()->exists)->toBeTrue();

        $package = WorkPackage::query()->sole();

        expect(app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $person)->exists)->toBeTrue();

        return;
    }

    expect($attempt)->toThrow(AuthorizationException::class);

    $package = wholeExterior($this);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);

    foreach ([
        fn () => app(ChangeWorkPackages::class)->changePackage($this->project, $package->id, workPackage(['name' => 'Changed']), $person),
        fn () => app(ChangeWorkPackages::class)->removePackage($this->project, $package->id, $person),
        fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-23', '2026-11-27', $person),
        fn () => app(ChangeWorkPackages::class)->withdrawWindow($this->project, $window->id, $person),
    ] as $refused) {
        expect($refused)->toThrow(AuthorizationException::class);
    }

    expect(WorkPackage::query()->count())->toBe(1);
    expect($package->fresh()->name)->toBe('Whole exterior');
    expect(WorkPackageWindow::query()->count())->toBe(1);
})->with('people around a project\'s work');

test('nothing is written on a job that is not ready to schedule, and it says what is missing', function () {
    $package = wholeExterior($this);
    app(RecordWelcomeCall::class)->undo($this->project, 'Ticked on the wrong job.', $this->pat);

    foreach ([
        fn () => app(ChangeWorkPackages::class)->addPackage($this->project, workPackage(['name' => 'Deck']), $this->pat),
        fn () => app(ChangeWorkPackages::class)->changePackage($this->project, $package->id, workPackage(['name' => 'Changed']), $this->pat),
        fn () => app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat),
    ] as $refused) {
        expect($refused)->toThrow(ValidationException::class, 'This job is not ready to schedule yet. It still needs the welcome call.');
    }

    expect(WorkPackage::query()->pluck('name')->all())->toBe(['Whole exterior']);
    expect(WorkPackageWindow::query()->count())->toBe(0);
});

test('a cancelled job takes no work', function () {
    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => wholeExterior($this))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| The Project page
|--------------------------------------------------------------------------
*/

test('a ready job has the work part, and the first package starts from what was sold and handed over', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="project-work-schedule"');

    workPart($this->pat, $this->project)
        ->assertSee('No work package yet.')
        ->assertSee('Write the work package')
        ->call('openAdd')
        ->assertSet('package.name', $this->project->proposal->name)
        ->assertSet('package.scope', 'All four sides, trim and both doors. Two coats.')
        ->call('savePackage')
        ->assertHasErrors(['package.workingDays', 'package.minimumCrew'])
        ->assertSee('Give a whole number of working days, from 1 to 120.')
        ->set('package.workingDays', '5')
        ->set('package.minimumCrew', '3')
        ->call('savePackage')
        ->assertHasNoErrors()
        ->assertDispatched('project-work-changed')
        ->assertSee('5 working days')
        ->assertSee('smallest crew: 3 people')
        ->assertSee('No dates offered yet.')
        ->assertSee('Split off another package')
        // A second package starts empty.
        ->call('openAdd')
        ->assertSet('package.name', '')
        ->assertSet('package.scope', '');
});

test('dates are offered and withdrawn from the page, and a short window is pointed out', function () {
    $package = wholeExterior($this);

    workPart($this->pat, $this->project)
        ->call('openWindow', $package->id)
        ->assertSee('For Whole exterior, which takes 5 working days.')
        ->set('windowFirstDay', '2026-11-20')
        ->set('windowLastDay', '2026-11-16')
        ->call('proposeWindow')
        ->assertHasErrors(['windowLastDay'])
        ->set('windowFirstDay', '2026-11-18')
        ->set('windowLastDay', '2026-11-23')
        ->call('proposeWindow')
        ->assertHasNoErrors()
        ->assertSee('Wed, Nov 18 – Mon, Nov 23, 2026')
        ->assertSee('4 working days')
        ->assertSeeHtml('data-test="work-window-short"')
        ->assertSee('shorter than the 5 this work needs')
        ->assertSee('offered by Pat Production');

    $window = WorkPackageWindow::query()->sole();

    workPart($this->pat, $this->project)
        ->call('openWithdraw', $window->id)
        ->call('withdrawWindow')
        ->assertHasNoErrors()
        ->assertSee('No dates offered yet.');

    // Something the browser made up is refused.
    workPart($this->pat, $this->project)->call('openWithdraw', 987654)->assertStatus(409);
    workPart($this->pat, $this->project)->call('openChange', 987654)->assertStatus(409);
    workPart($this->pat, $this->project)->call('openWindow', 987654)->assertStatus(409);
});

test('a package is changed and removed from the page', function () {
    $package = wholeExterior($this);

    workPart($this->pat, $this->project)
        ->call('openChange', $package->id)
        ->assertSet('package.workingDays', '5')
        ->set('package.workingDays', '1')
        ->call('savePackage')
        ->assertHasNoErrors()
        ->assertSee('1 working day');

    workPart($this->pat, $this->project)
        ->call('openRemove', $package->id)
        ->call('removePackage')
        ->assertHasNoErrors()
        ->assertSee('No work package yet.');
});

test('the salesperson and another production manager read the work and are offered nothing', function () {
    $package = wholeExterior($this);
    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);

    foreach ([$this->sam, $this->pam] as $reader) {
        workPart($reader, $this->project)
            ->assertSee('Whole exterior')
            ->assertSee('Mon, Nov 16 – Fri, Nov 20, 2026')
            ->assertDontSeeHtml('data-test="add-work-package"')
            ->assertDontSeeHtml('data-test="offer-work-window"')
            ->assertDontSeeHtml('data-test="withdraw-work-window"')
            ->call('openAdd')
            ->assertForbidden();

        workPart($reader, $this->project)->call('openWindow', $package->id)->assertForbidden();
    }

    // Someone who may not see the Project cannot open it at all.
    workPart(User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), $this->project)->assertForbidden();
});

test('a job that stops being ready keeps its work in view and offers nothing', function () {
    wholeExterior($this);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->call('openUndoWelcomeCall')
        ->set('welcomeCallUndoNote', 'Ticked on the wrong job.')
        ->call('undoWelcomeCall')
        ->assertDispatched('project-readiness-changed');

    // The page opened again still has it, because the job has work.
    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSeeHtml('data-test="project-work-schedule"');

    workPart($this->pat, $this->project->fresh())
        ->assertSeeHtml('data-test="work-not-ready"')
        ->assertSee('This job is not ready to schedule now, so nothing here can be changed. It still needs the welcome call.')
        ->assertSee('Whole exterior')
        ->assertDontSeeHtml('data-test="add-work-package"');
});

test('a job that is not ready and has no work has no work part', function () {
    $taken = soldProject($this->lakeview, $this->sam);
    completeHandoff($taken, $this->sam);
    app(TakeProject::class)->handle($taken, $this->pat);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $taken->fresh()])
        ->assertDontSeeHtml('data-test="project-work-schedule"');
});

test('the project\'s activity says which work and which days were offered and withdrawn', function () {
    $package = wholeExterior($this);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);
    $oneDay = app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-24', '2026-11-24', $this->pat);

    expect($oneDay->words())->toBe('Tue, Nov 24, 2026');

    app(ChangeWorkPackages::class)->withdrawWindow($this->project, $window->id, $this->pat);

    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project->fresh()])
        ->assertSeeInOrder([
            'Offered dates withdrawn: Whole exterior, Mon, Nov 16 – Fri, Nov 20, 2026',
            'Dates offered to the customer: Whole exterior, Tue, Nov 24, 2026',
            'Dates offered to the customer: Whole exterior, Mon, Nov 16 – Fri, Nov 20, 2026',
        ]);
});

test('the workspace says whether dates have been offered for each ready job', function () {
    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee('No dates offered yet');

    $package = wholeExterior($this);
    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-16', '2026-11-20', $this->pat);
    app(ChangeWorkPackages::class)->proposeWindow($this->project, $package->id, '2026-11-23', '2026-11-27', $this->pat);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')->assertSee('Dates offered: 2 windows');
});
