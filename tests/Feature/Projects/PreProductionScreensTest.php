<?php

use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Where people meet pre-production, step 10a
 * (docs/slice-10-pre-production-plan.md): the Project page, Production >
 * Workspace, and the Lead's Proposals card.
 *
 * At Lakeview, "Oak St" was sold by Sam Sales and handed over, and Pat
 * Production took it on Thursday 8 October 2026 at 3:00 pm Eastern. Tests
 * start at that moment.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();
});

/**
 * The Project page, as a staff member opens it.
 */
function preProductionPage(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project]);
}

/**
 * Another job at Lakeview that Pat has taken.
 */
function anotherJobOfPats($test, string $title): Project
{
    $project = soldProject($test->lakeview, $test->sam, ['title' => $title]);

    completeHandoff($project, $test->sam);
    app(TakeProject::class)->handle($project, $test->pat);

    return $project->fresh();
}

/*
|--------------------------------------------------------------------------
| The Project page
|--------------------------------------------------------------------------
*/

test('a project has its pre-production part once a production manager has it', function () {
    $waiting = soldProject($this->lakeview, $this->sam);
    completeHandoff($waiting, $this->sam);

    preProductionPage($this->manager, $waiting)->assertDontSeeHtml('data-test="project-pre-production"');

    preProductionPage($this->pat, $this->project)
        ->assertSeeHtml('data-test="project-pre-production"')
        ->assertSee('Customer not yet called.')
        ->assertDontSeeHtml('data-test="welcome-call-behind"')
        ->assertSee('No rough timeframe yet.')
        ->assertSeeHtml('data-test="tick-welcome-call"')
        ->assertSeeHtml('data-test="set-timeframe"');
});

test('the production manager ticks the welcome call from the page, and can untick it', function () {
    preProductionPage($this->pat, $this->project)
        ->call('openWelcomeCall')
        // It starts on today, in the Project's own Location.
        ->assertSet('welcomeCallOn', '2026-10-08')
        ->set('welcomeCallOn', '2026-10-09')
        ->call('saveWelcomeCall')
        ->assertHasErrors(['welcomeCallOn'])
        ->assertSee('The call cannot be on a day that has not come yet.')
        ->set('welcomeCallOn', '2026-10-08')
        ->set('welcomeCallNote', 'Spoke to both owners.')
        ->call('saveWelcomeCall')
        ->assertHasNoErrors()
        ->assertSee('Made on Thu, Oct 8, 2026')
        ->assertSee('Spoke to both owners.')
        ->assertSee('Pat Production')
        ->assertDontSee('Customer not yet called.')
        ->assertSee('Welcome call made')
        ->call('openUndoWelcomeCall')
        ->call('undoWelcomeCall')
        ->assertHasErrors(['welcomeCallUndoNote'])
        ->set('welcomeCallUndoNote', 'Ticked on the wrong job.')
        ->call('undoWelcomeCall')
        ->assertHasNoErrors()
        ->assertSee('Customer not yet called.')
        ->assertSee('Welcome call unticked');

    expect($this->project->fresh()->welcome_call_on)->toBeNull();
});

test('the rough timeframe is set and changed from the page, and what it was is shown', function () {
    $page = preProductionPage($this->pat, $this->project)
        ->call('openTimeframe')
        ->assertSet('timeframeEarliest', '')
        ->set('timeframeEarliest', '2026-12-12')
        ->set('timeframeLatest', '2026-11-24')
        ->call('saveTimeframe')
        ->assertHasErrors(['timeframeLatest'])
        ->assertSee('The latest start cannot be before the earliest.')
        ->set('timeframeEarliest', '2026-11-24')
        ->set('timeframeLatest', '2026-12-12')
        ->set('timeframeNote', 'After the roof is done.')
        ->call('saveTimeframe')
        ->assertHasNoErrors()
        ->assertSee('Late November to mid December')
        ->assertSee('Work expected to start between Nov 24, 2026 and Dec 12, 2026. Not a booked date.')
        ->assertSee('After the roof is done.')
        ->assertDontSeeHtml('data-test="timeframe-history"');

    // Changing it asks why, and starts from what is there.
    $page->call('openTimeframe')
        ->assertSet('timeframeEarliest', '2026-11-24')
        ->assertSet('timeframeLatest', '2026-12-12')
        ->assertSee('Why it is being changed (required)')
        ->set('timeframeEarliest', '2027-01-11')
        ->set('timeframeLatest', '2027-01-29')
        ->call('saveTimeframe')
        ->assertHasErrors(['timeframeReason'])
        ->set('timeframeReason', 'The customer asked to wait until after the holidays.')
        ->call('saveTimeframe')
        ->assertHasNoErrors()
        ->assertSee('Mid to late January 2027')
        ->assertSeeHtml('data-test="timeframe-history"')
        ->assertSeeInOrder(['Now', 'Mid to late January 2027', 'The customer asked to wait until after the holidays.', 'Was', 'Late November to mid December']);
});

test('the salesperson and another production manager read it and are offered nothing', function () {
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-08', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project->fresh(), '2026-11-24', '2026-12-12', null, null, $this->pat);

    foreach ([$this->sam, $this->pam] as $reader) {
        preProductionPage($reader, $this->project->fresh())
            ->assertSee('Made on Thu, Oct 8, 2026')
            ->assertSee('Late November to mid December')
            ->assertDontSeeHtml('data-test="untick-welcome-call"')
            ->assertDontSeeHtml('data-test="set-timeframe"')
            ->call('openWelcomeCall')
            ->assertForbidden();

        preProductionPage($reader, $this->project->fresh())->call('openTimeframe')->assertForbidden();
        preProductionPage($reader, $this->project->fresh())->call('openUndoWelcomeCall')->assertForbidden();
    }

    // A manager is offered both.
    preProductionPage($this->manager, $this->project->fresh())
        ->assertSeeHtml('data-test="untick-welcome-call"')
        ->assertSeeHtml('data-test="set-timeframe"');
});

test('a job nobody has called is marked as falling behind after two business days, on its page and in the workspace', function () {
    // Monday 12 October, 2:59 pm Eastern: one minute short of two business days.
    $this->travelTo('2026-10-12 18:59:00');

    preProductionPage($this->pat, $this->project)->assertDontSeeHtml('data-test="welcome-call-behind"');
    Livewire::actingAs($this->pat)->test('pages::production.workspace')
        ->assertSee('Customer not yet called')
        ->assertDontSeeHtml('data-test="workspace-behind"');

    $this->travelTo('2026-10-12 19:00:00');

    preProductionPage($this->pat, $this->project)
        ->assertSeeHtml('data-test="welcome-call-behind"')
        ->assertSee('Falling behind')
        ->assertSee('It was due within two business days of the job being taken');

    // A manager sees it too.
    Livewire::actingAs($this->manager)->test('pages::production.workspace')
        ->assertSeeHtml('data-test="workspace-behind"')
        ->assertSee('Falling behind');

    // Ticked, the mark goes.
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-12', null, $this->pat);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')
        ->assertDontSeeHtml('data-test="workspace-behind"')
        ->assertDontSee('Customer not yet called');
});

/*
|--------------------------------------------------------------------------
| Production > Workspace
|--------------------------------------------------------------------------
*/

test('the workspace says what each job still needs, the ones with no timeframe first and then the soonest start', function () {
    $december = anotherJobOfPats($this, 'Deck — 2 Elm St');
    $november = anotherJobOfPats($this, 'Interior — 3 Pine St');

    app(SetProjectTimeframe::class)->handle($december, '2026-12-01', '2026-12-18', null, null, $this->pat);
    app(SetProjectTimeframe::class)->handle($november, '2026-11-02', '2026-11-13', null, null, $this->pat);
    app(RecordWelcomeCall::class)->handle($november, '2026-10-08', null, $this->pat);

    Livewire::actingAs($this->pat)->test('pages::production.workspace')
        ->assertSeeInOrder([
            'Getting ready',
            // No timeframe yet: on top.
            'Whole exterior — 1 Oak St', 'Customer not yet called', 'No rough timeframe',
            'Deck — 2 Elm St', 'Customer not yet called', 'Early to mid December',
            // Called, and with a timeframe: it needs nothing more (step 10c).
            'Ready to schedule',
            'Interior — 3 Pine St', 'Early to mid November',
        ]);
});

/*
|--------------------------------------------------------------------------
| The Lead
|--------------------------------------------------------------------------
*/

test('the lead shows the rough timeframe of its sold job, so sales can answer the customer', function () {
    proposalsCard($this->sam, $this->project->lead)->assertDontSeeHtml('data-test="proposal-timeframe"');

    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-24', '2026-12-12', null, null, $this->pat);

    proposalsCard($this->sam, $this->project->lead->fresh())
        ->assertSeeHtml('data-test="proposal-timeframe"')
        ->assertSee('Rough timeframe: Late November to mid December. Not a booked date.');
});
