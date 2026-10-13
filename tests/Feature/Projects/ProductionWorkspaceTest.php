<?php

use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\TakeProject;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Navigation;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Production > Workspace: sold jobs from the sale until a Production Manager
 * has them (docs/slice-8-projects-plan.md, step 8b; product-architecture.md,
 * 6.2).
 *
 * At Lakeview: "Oak St" is sold and waits for Sam's handoff; "Elm St" is
 * handed over and waits for a Production Manager; "Pine St" is Pat's.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->oak = soldProject($this->lakeview, $this->sam, ['title' => 'Exterior Painting — 1 Oak St']);
    $this->elm = soldProject($this->lakeview, $this->sam, ['title' => 'Exterior Painting — 2 Elm St']);
    $this->pine = soldProject($this->lakeview, $this->sam, ['title' => 'Exterior Painting — 3 Pine St']);

    completeHandoff($this->elm, $this->sam);
    completeHandoff($this->pine, $this->sam);
    app(TakeProject::class)->handle($this->pine, $this->pat);

    $this->travelTo('2026-10-10 19:00:00');
});

/**
 * The Workspace page, as a staff member opens it.
 */
function workspacePage(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test('pages::production.workspace');
}

/**
 * The text of one of the page's lists.
 */
function workspaceList(Testable $page, string $list): string
{
    return (string) str($page->html())->after('data-test="workspace-'.$list.'"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();
}

test('workspace is a live page in the production center', function () {
    $item = app(Navigation::class)->find('production.workspace');

    expect($item->isLive())->toBeTrue();
    expect($item->route)->toBe('production.workspace');

    foreach ([$this->owner, $this->manager, $this->pat] as $person) {
        $this->actingAs($person)->get(route('production.workspace'))->assertOk()->assertSee('data-test="production-workspace"', false);
    }
});

test('workspace is refused to people without production access, and a guest is sent to sign in', function (Role $role) {
    $person = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($person)->get(route('production.workspace'))->assertForbidden();
    Livewire::actingAs($person)->test('pages::production.workspace')->assertForbidden();

    auth()->logout();

    $this->get(route('production.workspace'))->assertRedirect(route('login'));
})->with([Role::SalesConsultant, Role::CallCenterAgent, Role::Finance]);

test('each sold job is in the list for where it stands', function () {
    $page = workspacePage($this->pat)->assertSee('Sold jobs at Lakeview, from the sale until a Production Manager has them.');

    expect(workspaceList($page, 'new'))->toContain('2 Elm St')->not->toContain('1 Oak St')->not->toContain('3 Pine St');
    expect(workspaceList($page, 'awaiting-handoff'))->toContain('1 Oak St')->toContain('No handoff yet')->not->toContain('2 Elm St');
    expect(workspaceList($page, 'pre-production'))->toContain('3 Pine St')->toContain('Yours &middot; since')->not->toContain('2 Elm St');

    // Each says what was sold, for how much, by whom and how long it has waited.
    expect(workspaceList($page, 'new'))->toContain('$8,450')->toContain('Sold by Sam Sales, 2 days ago');

    // Someone else sees whose it is by name.
    expect(workspaceList(workspacePage($this->pam), 'pre-production'))->toContain('Pat Production &middot; since')->not->toContain('Yours &middot; since');
});

test('a production manager takes a ready job from the page, and it moves to their pre-production', function () {
    $page = workspacePage($this->pam)
        ->assertSeeHtml('data-test="take-project"')
        // A Production Manager takes; only a manager assigns.
        ->assertDontSeeHtml('data-test="assign-production-manager"')
        ->assertDontSeeHtml('data-test="change-production-manager"')
        ->call('take', $this->elm->id)
        ->assertHasNoErrors();

    expect($this->elm->fresh()->production_manager_id)->toBe($this->pam->id);
    expect($this->elm->fresh()->production_state)->toBe(ProjectProductionState::PreProduction);
    expect(workspaceList($page, 'new'))->toContain('Nothing is waiting for a Production Manager.');
    expect(workspaceList($page, 'pre-production'))->toContain('2 Elm St');
});

test('a job still waiting for its handoff is seen and cannot be taken, whatever the browser sends', function () {
    workspacePage($this->pat)->call('take', $this->oak->id)->assertForbidden();

    expect($this->oak->fresh()->production_manager_id)->toBeNull();
});

test('a job someone else took a moment ago says who has it', function () {
    $page = workspacePage($this->pam);

    app(TakeProject::class)->handle($this->elm, $this->pat);

    $page->call('take', $this->elm->id)
        ->assertHasErrors('productionManagerId')
        ->assertSee('Pat Production already has this project.');

    expect($this->elm->fresh()->production_manager_id)->toBe($this->pat->id);
});

test('a manager assigns a production manager from the page, and changes one with a note', function () {
    $page = workspacePage($this->manager)
        // A manager assigns; they do not take a job themselves.
        ->assertDontSeeHtml('data-test="take-project"')
        ->assertSeeHtml('data-test="assign-production-manager"')
        ->call('openAssign', $this->elm->id)
        ->assertSee('Assign a Production Manager')
        ->assertSeeInOrder(['Choose a Production Manager', 'Pam Production', 'Pat Production'])
        ->call('assign')
        ->assertHasErrors('productionManagerId')
        ->set('productionManagerId', $this->pam->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect($this->elm->fresh()->production_manager_name)->toBe('Pam Production');
    expect(workspaceList($page, 'pre-production'))->toContain('2 Elm St')->toContain('Pam Production');

    $page->call('openAssign', $this->pine->id)
        ->assertSee('Change the Production Manager')
        ->assertSet('productionManagerId', $this->pat->id)
        ->set('productionManagerId', $this->pam->id)
        ->call('assign')
        ->assertHasErrors('assignmentNote')
        ->set('assignmentNote', 'Pat is on leave.')
        ->call('assign')
        ->assertHasNoErrors();

    expect($this->pine->fresh()->production_manager_name)->toBe('Pam Production');
});

test('the openers refuse people who may not assign', function () {
    workspacePage($this->pat)->call('openAssign', $this->elm->id)->assertForbidden();

    workspacePage($this->pat)
        ->set('productionManagerId', $this->pat->id)
        ->call('assign')
        ->assertStatus(409);

    expect($this->elm->fresh()->production_manager_id)->toBeNull();
});

test('only the location shown is listed, and a location that is not theirs is never shown', function () {
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Harper Harbor']);
    $harborManager = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);
    $dock = soldProject($this->harbor, $harborSales, ['title' => 'Exterior Painting — 9 Dock Rd']);
    completeHandoff($dock, $harborSales);

    // A Lakeview Production Manager asking for Harbor is shown Lakeview, and cannot act on a Harbor job.
    $page = workspacePage($this->pat, ['location' => $this->harbor->id])
        ->assertSee('Sold jobs at Lakeview')
        ->assertDontSee('9 Dock Rd')
        ->assertDontSeeHtml('data-test="workspace-location"');

    $page->call('take', $dock->id)->assertNotFound();

    workspacePage($harborManager)->assertSee('Sold jobs at Harbor')->assertSee('9 Dock Rd')->assertDontSee('2 Elm St');

    // Someone with both chooses.
    workspacePage($this->owner, ['location' => $this->harbor->id])
        ->assertSeeHtml('data-test="workspace-location"')
        ->assertSee('9 Dock Rd')
        ->assertDontSee('2 Elm St')
        ->set('locationId', $this->lakeview->id)
        ->assertSee('2 Elm St')
        ->assertDontSee('9 Dock Rd');
});

test('a cancelled project is in none of the lists', function () {
    app(CancelProjectForCorrectedSale::class)->apply($this->elm->proposal, 'Wrong lead.', $this->manager);
    app(CancelProjectForCorrectedSale::class)->apply($this->pine->proposal, 'Wrong lead.', $this->manager);

    workspacePage($this->manager)->assertDontSee('2 Elm St')->assertDontSee('3 Pine St')->assertSee('1 Oak St');
});

test('a production manager takes a ready job from its own page too', function () {
    Livewire::actingAs($this->pam)->test('pages::projects.show', ['project' => $this->elm])
        ->assertSee('Production: New from Sales')
        ->assertSeeHtml('data-test="take-project"')
        ->assertSeeHtml('data-test="project-workspace-link"')
        ->call('take')
        ->assertHasNoErrors()
        ->assertSee('Production: Pre-production')
        ->assertSee('Production Manager: Pam Production')
        ->assertSee('Project taken by its Production Manager')
        ->assertDontSeeHtml('data-test="take-project"');

    // The salesperson sees who has it, with no way into Production's Workspace.
    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->elm->fresh()])
        ->assertSee('Production Manager: Pam Production')
        ->assertDontSeeHtml('data-test="project-workspace-link"')
        ->assertDontSeeHtml('data-test="take-project"')
        ->call('take')
        ->assertForbidden();
});
