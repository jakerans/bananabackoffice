<?php

use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Projects\NeedsDatesSoon;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Production > Schedule and "needs dates soon", step 11c
 * (docs/slice-11-schedule-plan.md; product-architecture.md, 6.5; decision
 * 144, questions 8 and 9).
 *
 * At Lakeview it is Thursday 8 October 2026, 3:00 pm Eastern. This week's
 * Monday is the 5th.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
});

/**
 * A job at Lakeview that Pat has taken, called and given a rough timeframe:
 * ready to schedule.
 */
function readyJob($test, string $title, string $earliest, string $latest, ?Location $location = null, ?User $productionManager = null): Project
{
    $location ??= $test->lakeview;
    $productionManager ??= $test->pat;
    $salesperson = $location->is($test->lakeview) ? $test->sam : User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();

    $project = soldProject($location, $salesperson, ['title' => $title]);
    completeHandoff($project, $salesperson);
    app(TakeProject::class)->handle($project, $productionManager);
    app(RecordWelcomeCall::class)->handle($project, '2026-10-08', null, $productionManager);
    app(SetProjectTimeframe::class)->handle($project, $earliest, $latest, null, null, $productionManager);

    return $project->fresh();
}

/**
 * Give a job one package and confirm these dates for it.
 */
function bookedFor($test, Project $project, string $first, string $last, string $name = 'Whole job', ?User $productionManager = null): Project
{
    $productionManager ??= $test->pat;
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => $name, 'scope' => 'The work.', 'workingDays' => '3', 'minimumCrew' => '2'], $productionManager);
    $window = app(ChangeWorkPackages::class)->proposeWindow($project, $package->id, $first, $last, $productionManager);
    app(ChangeWorkPackages::class)->confirmWindow($project, $window->id, 'Pat Customer', 'phone', '2026-10-08', $productionManager);

    return $project->fresh();
}

function schedulePage(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test('pages::production.schedule');
}

/**
 * The text of each week shown, by its Monday.
 *
 * @return array<string, string>
 */
function weeksOf(Testable $page): array
{
    $weeks = [];

    foreach (explode('data-test="schedule-week"', $page->html()) as $index => $part) {
        if ($index === 0) {
            continue;
        }

        $text = (string) str($part)->before('data-test="schedule-week"')->stripTags()->squish();
        $weeks[(string) str($text)->after('Week of ')->before(' This week')->before(' 0 bookings')->before(' 1 booking')->before(' 2 bookings')->trim()] = $text;
    }

    return $weeks;
}

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('schedule is a live page in the production center, and refused to people without production access', function (Role $role) {
    $item = app(Navigation::class)->find('production.schedule');

    expect($item->isLive())->toBeTrue();
    expect($item->route)->toBe('production.schedule');

    $this->actingAs($this->pat)->get(route('production.schedule'))->assertOk()->assertSee('data-test="production-schedule"', false);

    $person = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($person)->get(route('production.schedule'))->assertForbidden();
    Livewire::actingAs($person)->test('pages::production.schedule')->assertForbidden();
})->with([Role::SalesConsultant, Role::CallCenterAgent, Role::Finance]);

test('the booked work is listed week by week, in every week it covers, and two jobs on the same days are both shown', function () {
    bookedFor($this, readyJob($this, 'Exterior — 1 Oak St', '2026-10-12', '2026-10-23'), '2026-10-15', '2026-10-20');
    bookedFor($this, readyJob($this, 'Deck — 2 Elm St', '2026-10-12', '2026-10-23'), '2026-10-15', '2026-10-16');

    $page = schedulePage($this->manager)
        ->assertSee('Work booked at Lakeview, week by week.')
        ->assertSee('Oct 5 to Nov 15, 2026');

    $weeks = weeksOf($page);

    expect($weeks)->toHaveCount(6);
    expect($weeks['Mon, Oct 5'])->toContain('This week')->toContain('Nothing booked.');
    expect($weeks['Mon, Oct 12'])->toContain('2 bookings')->toContain('1 Oak St')->toContain('2 Elm St')->toContain('Thu, Oct 15 – Tue, Oct 20, 2026');
    expect($weeks['Mon, Oct 19'])->toContain('1 booking')->toContain('1 Oak St')->not->toContain('2 Elm St');
    expect($weeks['Mon, Oct 26'])->toContain('Nothing booked.');

    // Each says who has it and where its colors stand.
    expect($weeks['Mon, Oct 12'])->toContain('Pat Production')->toContain('Colors: not started')->toContain('smallest crew: 2 people');
});

test('a split job lists each part by name, and a part not yet confirmed is not listed', function () {
    $job = readyJob($this, 'Exterior — 1 Oak St', '2026-10-12', '2026-10-23');
    bookedFor($this, $job, '2026-10-15', '2026-10-16', 'Front porch');
    app(ChangeWorkPackages::class)->addPackage($job, ['name' => 'Back deck', 'scope' => 'Deck.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);

    $week = weeksOf(schedulePage($this->pat))['Mon, Oct 12'];

    expect($week)->toContain('Front porch')->not->toContain('Back deck')->toContain('Yours');
});

test('the weeks move on and back by six, and back to this week', function () {
    bookedFor($this, readyJob($this, 'Exterior — 1 Oak St', '2026-11-16', '2026-11-27'), '2026-11-23', '2026-11-25');

    $page = schedulePage($this->manager);

    expect(collect(weeksOf($page))->filter(fn (string $week): bool => str_contains($week, '1 Oak St')))->toBeEmpty();

    $page->call('move', 6)
        ->assertSet('from', '2026-11-16')
        ->assertSee('Nov 16 to Dec 27, 2026')
        ->assertSeeHtml('data-test="schedule-this-week"');

    expect(weeksOf($page)['Mon, Nov 23'])->toContain('1 Oak St');

    $page->call('move', 0)->assertSet('from', '')->assertSee('Oct 5 to Nov 15, 2026');

    // A day that is not a Monday starts its own week; one that does not exist is this week.
    schedulePage($this->manager, ['from' => '2026-11-18'])->assertSee('Nov 16 to Dec 27, 2026');
    schedulePage($this->manager, ['from' => '2026-02-30'])->assertSee('Oct 5 to Nov 15, 2026');
});

test('only the location shown is listed, and a cancelled job is not', function () {
    $harborManager = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);
    bookedFor($this, readyJob($this, 'Exterior — 9 Dock Rd', '2026-10-12', '2026-10-23', $this->harbor, $harborManager), '2026-10-15', '2026-10-16', productionManager: $harborManager);
    $cancelled = bookedFor($this, readyJob($this, 'Exterior — 1 Oak St', '2026-10-12', '2026-10-23'), '2026-10-15', '2026-10-16');
    app(CancelProjectForCorrectedSale::class)->apply($cancelled->proposal, 'Wrong lead.', $this->manager);

    schedulePage($this->manager)->assertDontSee('9 Dock Rd')->assertDontSee('1 Oak St');

    // Asking for a location they may not see shows their own.
    schedulePage($this->manager, ['location' => $this->harbor->id])->assertSee('Work booked at Lakeview')->assertDontSee('9 Dock Rd');

    schedulePage($harborManager)->assertSee('9 Dock Rd');
});

/*
|--------------------------------------------------------------------------
| Needs dates soon
|--------------------------------------------------------------------------
*/

test('a ready job with no dates is flagged when its rough start is less than three weeks away', function (string $earliest, bool $flagged) {
    $job = readyJob($this, 'Exterior — 1 Oak St', $earliest, '2026-12-18');

    expect(NeedsDatesSoon::applies($job))->toBe($flagged);
    expect(NeedsDatesSoon::where(Project::query(), $this->lakeview)->pluck('id')->all())->toBe($flagged ? [$job->id] : []);
})->with([
    'starting next week' => ['2026-10-12', true],
    'twenty days away' => ['2026-10-28', true],
    'exactly three weeks away' => ['2026-10-29', false],
    'in a month' => ['2026-11-09', false],
]);

test('a job is not flagged once it is scheduled, nor when it is not ready, and is flagged again when it stops being scheduled', function () {
    $job = readyJob($this, 'Exterior — 1 Oak St', '2026-10-19', '2026-10-30');

    expect(NeedsDatesSoon::applies($job))->toBeTrue();

    $job = bookedFor($this, $job, '2026-10-19', '2026-10-21');

    expect(NeedsDatesSoon::applies($job))->toBeFalse();
    expect(NeedsDatesSoon::where(Project::query(), $this->lakeview)->count())->toBe(0);

    // A new part with no dates takes it back to pre-production, and it needs dates soon again.
    app(ChangeWorkPackages::class)->addPackage($job, ['name' => 'Deck', 'scope' => 'Deck.', 'workingDays' => '1', 'minimumCrew' => '1'], $this->pat);

    expect(NeedsDatesSoon::applies($job->fresh()))->toBeTrue();

    // Not ready, not flagged.
    app(RecordWelcomeCall::class)->undo($job, 'Ticked on the wrong job.', $this->pat);

    expect(NeedsDatesSoon::applies($job->fresh()))->toBeFalse();
    expect(NeedsDatesSoon::where(Project::query(), $this->lakeview)->count())->toBe(0);
});

test('the schedule and the workspace show the jobs that need dates soon', function () {
    readyJob($this, 'Exterior — 1 Oak St', '2026-10-19', '2026-10-30');
    readyJob($this, 'Deck — 2 Elm St', '2026-12-07', '2026-12-18');

    $page = schedulePage($this->manager);
    $soon = (string) str($page->html())->after('data-test="schedule-needs-dates"')->before('data-test="schedule-range"')->stripTags()->squish();

    expect($soon)->toContain('1 Oak St')->toContain('Rough timeframe: Mid to late October')->toContain('Pat Production')->not->toContain('2 Elm St');

    $workspace = Livewire::actingAs($this->pat)->test('pages::production.workspace')->html();
    $ready = (string) str($workspace)->after('data-test="workspace-ready"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();

    expect(substr_count($workspace, 'data-test="workspace-needs-dates-soon"'))->toBe(1);
    expect((string) str($ready)->before('2 Elm St'))->toContain('Needs dates soon');

    // With none, it says so.
    Project::query()->update(['timeframe_earliest' => '2027-01-04', 'timeframe_latest' => '2027-01-15']);

    schedulePage($this->manager)->assertSee('No ready job needs its dates soon.');
});
