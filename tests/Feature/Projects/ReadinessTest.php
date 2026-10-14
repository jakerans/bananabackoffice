<?php

use App\Actions\Projects\AssignProductionManager;
use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\RecordColorApproval;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\Readiness;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Readiness, step 10c (docs/slice-10-pre-production-plan.md; decision 141,
 * question 8): whether a job is ready to schedule, worked out from what is
 * recorded and never ticked by hand.
 *
 * At Lakeview, Sam Sales sold "Oak St" on Thursday 8 October 2026 at 3:00 pm
 * Eastern. Tests start at that moment, with nothing else done to it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
});

/**
 * Bring a job as far as the named things: 'handoff', 'manager', 'call',
 * 'timeframe', 'colors'. Each goes through its own action.
 *
 * @param  list<string>  $things
 */
function broughtTo($test, Project $project, array $things, ?User $productionManager = null): Project
{
    $productionManager ??= $test->pat;

    if (in_array('handoff', $things, true)) {
        completeHandoff($project, $test->sam);
    }

    if (in_array('manager', $things, true)) {
        app(TakeProject::class)->handle($project, $productionManager);
    }

    if (in_array('call', $things, true)) {
        app(RecordWelcomeCall::class)->handle($project, '2026-10-08', null, $productionManager);
    }

    if (in_array('timeframe', $things, true)) {
        app(SetProjectTimeframe::class)->handle($project, '2026-11-24', '2026-12-12', null, null, $productionManager);
    }

    if (in_array('colors', $things, true)) {
        app(ChangeColorSpecification::class)->addLine($project, [
            'area' => 'Living room', 'surface' => 'Walls', 'manufacturer' => 'Sherwin-Williams', 'colorName' => 'Agreeable Gray',
            'colorCode' => 'SW 7029', 'sheen' => 'eggshell', 'product' => 'Duration Home', 'note' => '',
        ], $productionManager);
        app(RecordColorApproval::class)->handle($project, 'Pat Customer', 'phone', '2026-10-08', $productionManager);
    }

    return Project::query()->with('handoff')->findOrFail($project->id);
}

/**
 * Another sold job at Lakeview, brought as far as the named things.
 *
 * @param  list<string>  $things
 */
function anotherJob($test, string $title, array $things, ?User $productionManager = null): Project
{
    return broughtTo($test, soldProject($test->lakeview, $test->sam, ['title' => $title]), $things, $productionManager);
}

/**
 * The text of one of the Workspace's lists.
 */
function readinessList(Testable $page, string $list): string
{
    return (string) str($page->html())->after('data-test="workspace-'.$list.'"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();
}

/**
 * The Workspace, as a staff member opens it.
 */
function readinessWorkspace(User $user): Testable
{
    return Livewire::actingAs($user)->test('pages::production.workspace');
}

/*
|--------------------------------------------------------------------------
| The check itself
|--------------------------------------------------------------------------
*/

test('a job is ready to schedule when the four things are recorded, and says what it still needs until then', function (array $things, int $done, array $missing) {
    $readiness = Readiness::for(broughtTo($this, $this->project, $things));

    expect($readiness->done())->toBe($done);
    expect($readiness->missing())->toBe($missing);
    expect($readiness->isReadyToSchedule())->toBe($missing === []);
})->with([
    'just sold' => [[], 0, ['the salesperson\'s handoff', 'a Production Manager', 'the welcome call', 'a rough timeframe']],
    'handed over' => [['handoff'], 1, ['a Production Manager', 'the welcome call', 'a rough timeframe']],
    'taken by a production manager' => [['handoff', 'manager'], 2, ['the welcome call', 'a rough timeframe']],
    'called, with no timeframe' => [['handoff', 'manager', 'call'], 3, ['a rough timeframe']],
    'a timeframe, and nobody has called' => [['handoff', 'manager', 'timeframe'], 3, ['the welcome call']],
    'called, with a timeframe' => [['handoff', 'manager', 'call', 'timeframe'], 4, []],
    'colors approved do not stand in for the call' => [['handoff', 'manager', 'timeframe', 'colors'], 3, ['the welcome call']],
    'everything, colors too' => [['handoff', 'manager', 'call', 'timeframe', 'colors'], 4, []],
]);

test('the colors are shown beside the four and are not needed to schedule', function () {
    $ready = Readiness::for(broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe']));

    expect($ready->isReadyToSchedule())->toBeTrue();
    expect($ready->colorsApproved())->toBeFalse();
    expect(array_column($ready->items, 'key'))->toBe([Readiness::HANDOFF, Readiness::PRODUCTION_MANAGER, Readiness::WELCOME_CALL, Readiness::TIMEFRAME]);

    $approved = Readiness::for(broughtTo($this, $this->project, ['colors']));

    expect($approved->isReadyToSchedule())->toBeTrue();
    expect($approved->colorsApproved())->toBeTrue();
});

test('readiness follows the record: unticking the call takes it away again', function () {
    $project = broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe']);

    expect(Readiness::for($project)->isReadyToSchedule())->toBeTrue();

    app(RecordWelcomeCall::class)->undo($project, 'Ticked on the wrong job.', $this->pat);

    $readiness = Readiness::for($project->fresh());

    expect($readiness->isReadyToSchedule())->toBeFalse();
    expect($readiness->missing())->toBe(['the welcome call']);
});

test('each line says what is recorded', function () {
    expect(array_column(Readiness::for($this->project)->items, 'label'))->toBe([
        'Handoff from Sales not complete yet',
        'No Production Manager yet',
        'Customer not yet called',
        'No rough timeframe yet',
    ]);

    expect(array_column(Readiness::for(broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe']))->items, 'label'))->toBe([
        'Handoff from Sales complete',
        'Production Manager assigned: Pat Production',
        'Customer called on Oct 8, 2026',
        'Rough timeframe set: Late November to mid December',
    ]);
});

test('the two lists ask the same questions as the check, and a job is never in both', function () {
    $jobs = [
        $this->project,
        anotherJob($this, 'Handed over', ['handoff']),
        anotherJob($this, 'Taken', ['handoff', 'manager']),
        anotherJob($this, 'Called', ['handoff', 'manager', 'call']),
        anotherJob($this, 'Timeframe', ['handoff', 'manager', 'timeframe']),
        anotherJob($this, 'Ready', ['handoff', 'manager', 'call', 'timeframe']),
        anotherJob($this, 'Ready, colors too', ['handoff', 'manager', 'call', 'timeframe', 'colors']),
        anotherJob($this, 'Colors only', ['handoff', 'manager', 'colors']),
    ];

    $ready = Readiness::whereReady(Project::query())->pluck('title')->sort()->values()->all();
    $gettingReady = Readiness::whereGettingReady(Project::query())->pluck('title')->sort()->values()->all();

    expect($ready)->toBe(['Ready', 'Ready, colors too']);
    expect($gettingReady)->toBe(['Called', 'Colors only', 'Taken', 'Timeframe']);

    foreach ($jobs as $job) {
        $job = Project::query()->with('handoff')->findOrFail($job->id);
        $isReady = Readiness::for($job)->isReadyToSchedule();

        expect(in_array($job->title, $ready, true))->toBe($isReady);
        expect(in_array($job->title, $gettingReady, true))->toBe(! $isReady && $job->production_manager_id !== null);
    }
});

test('a job has been in pre-production since it first had a production manager, whoever has it now', function () {
    $project = broughtTo($this, $this->project, ['handoff', 'manager']);

    expect(Readiness::inPreProductionSince([$project->id])[$project->id]->toDateTimeString())->toBe('2026-10-08 19:00:00');

    $this->travelTo('2026-10-12 14:00:00');
    app(AssignProductionManager::class)->handle($project, $this->pam->id, 'Pat is on leave.', $this->manager);

    expect($project->fresh()->production_manager_assigned_at->toDateTimeString())->toBe('2026-10-12 14:00:00');
    expect(Readiness::inPreProductionSince([$project->id])[$project->id]->toDateTimeString())->toBe('2026-10-08 19:00:00');

    // A job nobody has taken is not in pre-production.
    $waiting = anotherJob($this, 'Handed over', ['handoff']);

    expect(Readiness::inPreProductionSince([$waiting->id]))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| The Project page
|--------------------------------------------------------------------------
*/

test('the project page has the checklist from the sale on, ticked by what is recorded', function () {
    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $this->project])
        ->assertSeeHtml('data-test="project-readiness"')
        ->assertSee('Getting ready: 0 of 4')
        ->assertSeeInOrder(['Handoff from Sales not complete yet', 'No Production Manager yet', 'Customer not yet called', 'No rough timeframe yet'])
        ->assertSee('Still needs: the salesperson\'s handoff, a Production Manager, the welcome call, a rough timeframe.')
        ->assertSee('Colors not approved yet: not started')
        ->assertSee('Not needed to schedule the job.')
        ->assertDontSeeHtml('data-done="yes"');

    $taken = broughtTo($this, $this->project, ['handoff', 'manager']);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $taken])
        ->assertSee('Getting ready: 2 of 4')
        ->assertSeeInOrder(['Handoff from Sales complete', 'Production Manager assigned: Pat Production', 'Customer not yet called', 'No rough timeframe yet'])
        ->assertSee('Still needs: the welcome call, a rough timeframe.');
});

test('ticking the call and setting the timeframe on the page make the job ready, with no approved colors', function () {
    $taken = broughtTo($this, $this->project, ['handoff', 'manager']);

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $taken])
        ->call('openWelcomeCall')
        ->call('saveWelcomeCall')
        ->assertHasNoErrors()
        ->assertSee('Getting ready: 3 of 4')
        ->assertSee('Customer called on Oct 8, 2026')
        ->assertSee('Still needs: a rough timeframe.')
        ->call('openTimeframe')
        ->set('timeframeEarliest', '2026-11-24')
        ->set('timeframeLatest', '2026-12-12')
        ->call('saveTimeframe')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="readiness-state"')
        ->assertDontSee('Getting ready:')
        ->assertSee('Ready to schedule')
        ->assertSee('Rough timeframe set: Late November to mid December')
        ->assertDontSeeHtml('data-test="readiness-missing"')
        ->assertSee('Colors not approved yet: not started');
});

test('the checklist says when the colors are approved, and a reader sees it and is offered nothing', function () {
    $project = broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe', 'colors']);

    foreach ([$this->sam, $this->pam, $this->pat] as $reader) {
        $html = Livewire::actingAs($reader)->test('pages::projects.show', ['project' => $project])
            ->assertSee('Ready to schedule')
            ->assertSeeHtml('data-test="readiness-colors" data-done="yes"')
            ->html();

        // Nothing in the checklist is a button: it is read, never ticked.
        expect((string) str($html)->after('data-test="project-readiness"')->before('data-test="project-handoff"'))->not->toContain('<button');
    }
});

test('a cancelled project has no checklist', function () {
    $project = broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe']);

    app(CancelProjectForCorrectedSale::class)->apply($project->proposal, 'Wrong lead.', $this->manager);

    Livewire::actingAs($this->manager)->test('pages::projects.show', ['project' => $project->fresh()])
        ->assertDontSeeHtml('data-test="project-readiness"');
});

/*
|--------------------------------------------------------------------------
| Production > Workspace
|--------------------------------------------------------------------------
*/

test('the workspace has a job under getting ready until it needs nothing more, then under ready to schedule', function () {
    $project = broughtTo($this, $this->project, ['handoff', 'manager']);
    $page = readinessWorkspace($this->pat);

    expect(readinessList($page, 'getting-ready'))->toContain('1 Oak St')->toContain('Customer not yet called')->toContain('No rough timeframe');
    expect(readinessList($page, 'ready'))->toContain('No job is ready to schedule yet.')->not->toContain('1 Oak St');

    app(RecordWelcomeCall::class)->handle($project, '2026-10-08', null, $this->pat);
    $page = readinessWorkspace($this->pat);

    expect(readinessList($page, 'getting-ready'))->toContain('1 Oak St')->toContain('No rough timeframe')->not->toContain('Customer not yet called');

    app(SetProjectTimeframe::class)->handle($project, '2026-11-24', '2026-12-12', null, null, $this->pat);
    $page = readinessWorkspace($this->pat);

    expect(readinessList($page, 'getting-ready'))->toContain('No job is getting ready.')->not->toContain('1 Oak St');
    expect(readinessList($page, 'ready'))->toContain('1 Oak St')->toContain('Late November to mid December')->toContain('Colors: not started');

    // Unticked, it goes back.
    app(RecordWelcomeCall::class)->undo($project, 'Ticked on the wrong job.', $this->pat);
    $page = readinessWorkspace($this->pat);

    expect(readinessList($page, 'getting-ready'))->toContain('1 Oak St');
    expect(readinessList($page, 'ready'))->not->toContain('1 Oak St');
});

test('a manager sees both lists for the whole location, and how long each job has been in pre-production', function () {
    broughtTo($this, $this->project, ['handoff', 'manager']);
    $pams = anotherJob($this, 'Deck — 2 Elm St', ['handoff', 'manager', 'call', 'timeframe'], $this->pam);

    // Four days on, the manager gives Pam's job to Pat. It has still been in pre-production for six.
    $this->travelTo('2026-10-12 19:00:00');
    app(AssignProductionManager::class)->handle($pams, $this->pat->id, 'Pam is on leave.', $this->manager);
    $this->travelTo('2026-10-14 19:00:00');

    $page = readinessWorkspace($this->manager);

    expect(readinessList($page, 'getting-ready'))->toContain('1 Oak St')->toContain('Pat Production')->toContain('In pre-production for 6 days');
    expect(readinessList($page, 'ready'))->toContain('2 Elm St')->toContain('Pat Production')->toContain('In pre-production for 6 days');

    // A Production Manager sees the same two lists, and a job that is not theirs by name.
    $page = readinessWorkspace($this->pam);

    expect(readinessList($page, 'getting-ready'))->toContain('1 Oak St')->toContain('Pat Production');
    expect(readinessList($page, 'ready'))->toContain('2 Elm St');
});

test('ready to schedule lists your own first, then the soonest expected start', function () {
    $things = ['handoff', 'manager', 'call'];
    $pamsNovember = anotherJob($this, 'Fence — 4 Birch St', $things, $this->pam);
    $patsDecember = anotherJob($this, 'Deck — 2 Elm St', $things);
    $patsNovember = anotherJob($this, 'Interior — 3 Pine St', $things);

    app(SetProjectTimeframe::class)->handle($pamsNovember, '2026-11-02', '2026-11-06', null, null, $this->pam);
    app(SetProjectTimeframe::class)->handle($patsDecember, '2026-12-01', '2026-12-18', null, null, $this->pat);
    app(SetProjectTimeframe::class)->handle($patsNovember, '2026-11-16', '2026-11-20', null, null, $this->pat);

    readinessWorkspace($this->pat)->assertSeeInOrder(['Ready to schedule', '3 Pine St', '2 Elm St', '4 Birch St']);
    readinessWorkspace($this->pam)->assertSeeInOrder(['Ready to schedule', '4 Birch St', '3 Pine St', '2 Elm St']);
});

test('a ready job that was cancelled, or is at another location, is in neither list', function () {
    $cancelled = broughtTo($this, $this->project, ['handoff', 'manager', 'call', 'timeframe']);
    app(CancelProjectForCorrectedSale::class)->apply($cancelled->proposal, 'Wrong lead.', $this->manager);

    $harbor = Location::factory()->create(['name' => 'Harbor']);
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $harbor)->create(['name' => 'Harper Harbor']);
    $harborManager = User::factory()->withRoleAt(Role::ProductionManager, $harbor)->create(['name' => 'Hal Harbor']);
    $dock = soldProject($harbor, $harborSales, ['title' => 'Exterior Painting — 9 Dock Rd']);
    completeHandoff($dock, $harborSales);
    app(TakeProject::class)->handle($dock, $harborManager);
    app(RecordWelcomeCall::class)->handle($dock, '2026-10-08', null, $harborManager);
    app(SetProjectTimeframe::class)->handle($dock, '2026-11-24', '2026-12-12', null, null, $harborManager);

    readinessWorkspace($this->manager)
        ->assertDontSee('1 Oak St')
        ->assertDontSee('9 Dock Rd')
        ->assertSee('No job is getting ready.')
        ->assertSee('No job is ready to schedule yet.');

    expect(readinessList(readinessWorkspace($harborManager), 'ready'))->toContain('9 Dock Rd');
});
