<?php

use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Where a Project is seen: its own page, the Lead's Proposals card and the
 * customer's page (docs/slice-8-projects-plan.md, step 8a).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales at 123 Oak St. "Whole exterior", $8,450, was sold the same day.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->lead->property->forceFill(['address_line1' => '123 Oak St'])->save();
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'sold', ['note' => 'Signed at the kitchen table.'], $this->sam);

    $this->lead = $this->lead->fresh();
    $this->project = Project::query()->sole();
});

/**
 * A Project's page, as a staff member opens it.
 */
function projectPage(User $user, Project $project): Testable
{
    return Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project]);
}

test('the project page shows the sale, who it is for and the work', function () {
    $this->actingAs($this->sam)->get(route('projects.show', $this->project))
        ->assertOk()
        ->assertSee($this->project->title)
        ->assertSee('Sold')
        ->assertSee('Production: New from Sales')
        ->assertSee('Lakeview')
        ->assertSeeInOrder(['What was sold', 'Whole exterior', 'Priced at $8,450', '$8,450', 'Sam Sales', 'Thu, Oct 8, 2026 at 3:00 PM EDT', 'Signed at the kitchen table.'])
        ->assertSeeInOrder(['Who and where', 'Pat Customer', '123 Oak St'])
        ->assertSee($this->lead->contact->fullName())
        ->assertSee($this->lead->primaryService()->name)
        ->assertSee('Project created from a sale');
});

test('who may open a project\'s page', function (Closure $person, int $status) {
    $this->actingAs($person($this))->get(route('projects.show', $this->project))->assertStatus($status);
})->with([
    'the salesperson' => [fn ($test) => $test->sam, 200],
    'the Location Manager there' => [fn ($test) => $test->manager, 200],
    'a Production Manager there' => [fn ($test) => $test->pat, 200],
    'Finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), 200],
    'a Call Center Agent' => [fn ($test) => $test->agent, 403],
    'a Production Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create(), 403],
    'a Sales Consultant somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create(), 403],
]);

test('a guest is sent to sign in from a project', function () {
    $this->get(route('projects.show', $this->project))->assertRedirect(route('login'));
});

test('a production manager is shown what they need without the lead or the customer\'s page', function () {
    // A Production Manager cannot open Leads, nor a customer reached only through one.
    expect($this->pat->can('view', $this->lead))->toBeFalse();
    expect($this->pat->can('view', $this->lead->account))->toBeFalse();

    projectPage($this->pat, $this->project)
        ->assertSee('Pat Customer')
        ->assertSee($this->lead->contact->fullName())
        ->assertSee('123 Oak St')
        ->assertSee('Whole exterior')
        ->assertDontSeeHtml('data-test="project-lead-link"')
        ->assertDontSeeHtml(route('customers.show', $this->lead->account_id))
        ->assertDontSeeHtml('data-test="edit-project"');

    // The salesperson can go back to both.
    projectPage($this->sam, $this->project)
        ->assertSeeHtml('data-test="project-lead-link"')
        ->assertSeeHtml(route('customers.show', $this->lead->account_id));
});

test('a manager edits the title from the page, and nobody else is offered it', function () {
    projectPage($this->sam, $this->project)->assertDontSeeHtml('data-test="edit-project"')->call('openEdit')->assertForbidden();
    projectPage($this->pat, $this->project)->call('openEdit')->assertForbidden();

    projectPage($this->manager, $this->project)
        ->assertSeeHtml('data-test="edit-project"')
        ->call('openEdit')
        ->assertSet('projectTitle', $this->project->title)
        ->set('projectTitle', '   ')
        ->call('saveEdit')
        ->assertHasErrors('projectTitle')
        ->assertSee('Give the project a title.')
        ->set('projectTitle', 'Oak St exterior, two coats')
        ->set('projectDescription', 'Gate code 4411.')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('Oak St exterior, two coats')
        ->assertSee('Gate code 4411.')
        ->assertSee('Title or description changed');

    expect($this->project->fresh()->title)->toBe('Oak St exterior, two coats');
});

test('a cancelled project says so, with why, and is no longer edited', function () {
    app(CorrectProposalResult::class)->handle($this->lead, $this->proposal, 'Sam marked the wrong lead.', $this->manager);

    projectPage($this->manager, $this->project->fresh())
        ->assertSee('Cancelled: sale corrected')
        ->assertSee('This project was cancelled because its sale was corrected.')
        ->assertSee('Sam marked the wrong lead.')
        ->assertSee('Morgan Manager')
        ->assertSee('Was sold for')
        ->assertSee('Project cancelled: sale corrected')
        ->assertDontSee('Production: New from Sales')
        ->assertDontSeeHtml('data-test="edit-project"')
        ->call('openEdit')
        ->assertForbidden();
});

test('the lead\'s proposals card links a sold proposal to its project, for those who may open it', function () {
    proposalsCard($this->sam, $this->lead)
        ->assertSee('Project:')
        ->assertSee($this->project->title)
        ->assertSeeHtml('href="'.route('projects.show', $this->project).'"');

    // The Contact Center sees that there is one, and cannot open it.
    proposalsCard($this->agent, $this->lead)
        ->assertSee('Project:')
        ->assertSee($this->project->title)
        ->assertDontSeeHtml(route('projects.show', $this->project));

    // A cancelled Project is not shown against a proposal that is pending again.
    app(CorrectProposalResult::class)->handle($this->lead, $this->proposal, 'Wrong lead.', $this->manager);

    proposalsCard($this->sam, $this->lead->fresh())->assertDontSeeHtml('data-test="proposal-project"');
});

test('the customer page lists the customer\'s projects, with a link for those who may open one', function () {
    Livewire::actingAs($this->manager)->test('pages::customers.show', ['account' => $this->lead->account])
        ->assertSeeHtml('data-test="customer-projects"')
        ->assertSeeInOrder(['Projects', $this->project->title, 'Whole exterior', 'Lakeview', 'Sam Sales', '$8,450', 'Sold'])
        ->assertSeeHtml('href="'.route('projects.show', $this->project).'"');

    // The plan lets the Contact Center see the sale and its amount (11.4), not the Project itself.
    Livewire::actingAs($this->agent)->test('pages::customers.show', ['account' => $this->lead->account])
        ->assertSee($this->project->title)
        ->assertSee('$8,450')
        ->assertDontSeeHtml(route('projects.show', $this->project));

    app(CorrectProposalResult::class)->handle($this->lead, $this->proposal, 'Wrong lead.', $this->manager);

    Livewire::actingAs($this->manager)->test('pages::customers.show', ['account' => $this->lead->account])
        ->assertSee('Cancelled: sale corrected');
});

test('a customer with no sale has no projects to show', function () {
    $other = bookableLead($this->lakeview);

    Livewire::actingAs($this->manager)->test('pages::customers.show', ['account' => $other->account])
        ->assertSeeHtml('data-test="customer-projects"')
        ->assertSee('No projects to show.');
});

test('the customer\'s activity shows the project being made', function () {
    Livewire::actingAs($this->manager)->test('pages::customers.workspace', ['account' => $this->lead->account])
        ->assertSee('Project created from a sale')
        ->assertSee($this->project->title)
        ->assertSeeHtml('href="'.route('projects.show', $this->project).'"');
});
