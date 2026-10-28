<?php

use App\Actions\Projects\EditProject;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\SubmitAfterActionReport;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Project;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * A Project is made the moment a proposal is Sold, one for each sold proposal
 * (docs/slice-8-projects-plan.md, step 8a; rulebook, rule 13; decision 130).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales at 123 Oak St, who marked it run and added one proposal: "Whole
 * exterior", $8,450. Nothing is sold yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->property->forceFill(['address_line1' => '123 Oak St'])->save();
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * Mark a proposal sold through the action and give back its Project.
 */
function sell($proposal, User $by, array $details = []): Project
{
    app(RecordProposalResult::class)->handle($proposal->lead()->firstOrFail(), $proposal, 'sold', $details, $by);

    return Project::query()->where('proposal_id', $proposal->id)->sole();
}

/*
|--------------------------------------------------------------------------
| The sale makes the Project
|--------------------------------------------------------------------------
*/

test('marking a proposal sold makes its project in the same save, with what the lead had', function () {
    expect(Project::query()->count())->toBe(0);

    $this->travelTo('2026-10-09 15:00:00');

    $project = sell($this->proposal, $this->sam, ['soldAmount' => '8,200']);

    expect($project->only(['proposal_id', 'lead_id', 'location_id', 'account_id', 'contact_id', 'property_id']))->toBe([
        'proposal_id' => $this->proposal->id,
        'lead_id' => $this->lead->id,
        'location_id' => $this->lakeview->id,
        'account_id' => $this->lead->account_id,
        'contact_id' => $this->lead->contact_id,
        'property_id' => $this->lead->property_id,
    ]);

    // "What was sold — street address", not the Lead's main service (decision 134).
    expect($project->title)->toBe('Whole exterior — 123 Oak St');

    // The sold amount is the proposal's and is never typed again (decision 36).
    expect($project->sold_cents)->toBe(820000);
    expect($project->soldFor())->toBe('$8,200');
    expect($project->sold_at->toDateTimeString())->toBe('2026-10-09 15:00:00');

    expect($project->only(['salesperson_id', 'salesperson_name', 'created_by', 'created_by_name']))
        ->toBe(['salesperson_id' => $this->sam->id, 'salesperson_name' => 'Sam Sales', 'created_by' => $this->sam->id, 'created_by_name' => 'Sam Sales']);
    expect($project->production_state)->toBe(ProjectProductionState::NewFromSales);
    expect($project->isCancelled())->toBeFalse();

    // Its own copy of the services, with the main one, and of the size.
    expect($project->services->pluck('id')->all())->toBe($this->lead->services->pluck('id')->all());
    expect($project->services->filter(fn ($service) => $service->pivot->is_primary)->pluck('id')->all())->toBe([$this->lead->primaryService()->id]);
    expect($project->only(['project_size_id', 'project_size_min_value', 'project_size_max_value']))
        ->toBe($this->lead->only(['project_size_id', 'project_size_min_value', 'project_size_max_value']));

    $event = AuditEvent::query()->where('action', AuditAction::ProjectCreated)->sole();

    expect($event->subject_id)->toBe($project->id);
    expect($event->subject_type)->toBe('project');
    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->location_id)->toBe($this->lakeview->id);
    expect($event->values_after)->toEqual(['title' => $project->title, 'proposal' => 'Whole exterior', 'sold_for' => '$8,200', 'salesperson' => 'Sam Sales']);
    expect(CustomerActivity::label(AuditAction::ProjectCreated))->toBe('Project created from a sale');
});

test('each sold proposal has a project of its own (decision 130)', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    $deck = addProposal($this->lead, $this->sam, ['name' => 'Deck', 'price' => '1800']);

    $first = sell($this->proposal, $this->sam);
    $second = sell($cabinets, $this->sam, ['soldAmount' => '3,000']);

    expect(Project::query()->count())->toBe(2);
    expect($this->lead->projects()->pluck('id')->all())->toBe([$first->id, $second->id]);
    expect([$first->sold_cents, $second->sold_cents])->toBe([845000, 300000]);

    // Each is named for what was sold, so two at one address read differently (decision 134).
    expect([$first->title, $second->title])->toBe(['Whole exterior — 123 Oak St', 'Cabinets only — 123 Oak St']);

    // A proposal that is not sold has none.
    expect($deck->project)->toBeNull();
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('a proposal that is lost or still pending has no project', function () {
    $thinking = Objection::query()->where('name', 'Wants to think it over')->sole();
    $price = SalesLossReason::query()->where('name', 'Price')->sole();
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);

    app(RecordProposalResult::class)->handle($this->lead, $this->proposal, 'pending', ['objectionId' => $thinking->id], $this->sam);
    app(RecordProposalResult::class)->handle($this->lead, $cabinets, 'lost', ['lossReasonId' => $price->id, 'objectionId' => $thinking->id], $this->sam);

    expect(Project::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectCreated)->count())->toBe(0);
});

test('a sale reported in the after-action report makes its project too', function () {
    $lead = bookableLead($this->lakeview);
    $this->travelTo('2026-10-08 20:00:00');
    $appointment = bookAppointment($lead, $this->riley, $this->manager, '2026-10-08', '17:00', 30)->appointment;
    $exterior = estimateFor($lead->fresh(), $this->riley);
    $deck = estimateFor($lead->fresh(), $this->riley, ['Deck' => '1800', 'Deck and railings' => '2400']);
    $this->travelTo('2026-10-08 22:00:00');

    app(SubmitAfterActionReport::class)->handle($lead->fresh(), $appointment, ['outcome' => 'run', 'presented' => 'yes', 'estimates' => [
        $exterior->id => ['presented' => true, 'result' => 'sold', 'optionId' => $exterior->options->sole()->id],
        $deck->id => ['presented' => true, 'result' => 'sold', 'optionId' => $deck->options->last()->id],
    ]], $this->riley);

    $projects = $lead->projects()->get();

    expect($projects->pluck('sold_cents')->all())->toBe([845000, 240000]);
    expect($projects->pluck('title')->map(fn (string $title): string => explode(' — ', $title)[0])->all())->toBe(['Whole exterior', 'Deck and railings']);
    expect($projects->pluck('salesperson_name')->unique()->all())->toBe(['Riley Rep']);
});

test('the salesperson is the one the lead is with, whoever records the sale', function () {
    $project = sell($this->proposal, $this->manager);

    expect($project->only(['salesperson_id', 'salesperson_name', 'created_by', 'created_by_name']))
        ->toBe(['salesperson_id' => $this->sam->id, 'salesperson_name' => 'Sam Sales', 'created_by' => $this->manager->id, 'created_by_name' => 'Morgan Manager']);
});

test('a project keeps what the lead had at the sale when the lead is changed later', function () {
    $project = sell($this->proposal, $this->sam);
    $size = $project->only(['project_size_id', 'project_size_min_value', 'project_size_max_value']);

    $this->lead->forceFill(['project_size_min_value' => 1, 'project_size_max_value' => 2])->save();
    $this->lead->services()->detach();

    expect($project->fresh()->only(['project_size_id', 'project_size_min_value', 'project_size_max_value']))->toBe($size);
    expect($project->services()->count())->toBeGreaterThan(0);
});

/*
|--------------------------------------------------------------------------
| A corrected sale (decision 130, answer 4)
|--------------------------------------------------------------------------
*/

test('correcting the sale cancels its project and keeps it, and selling again brings the same one back', function () {
    $project = sell($this->proposal, $this->sam);

    $this->travelTo('2026-10-10 15:00:00');
    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'Sam marked the wrong lead.', $this->manager);

    $cancelled = $project->fresh();

    expect($cancelled->isCancelled())->toBeTrue();
    expect($cancelled->only(['cancelled_by', 'cancelled_by_name', 'cancellation_note']))
        ->toBe(['cancelled_by' => $this->manager->id, 'cancelled_by_name' => 'Morgan Manager', 'cancellation_note' => 'Sam marked the wrong lead.']);
    expect($cancelled->cancelled_at->toDateTimeString())->toBe('2026-10-10 15:00:00');
    // Kept on the record with what it sold for, and out of the Projects that stand.
    expect($cancelled->sold_cents)->toBe(845000);
    expect(Project::query()->count())->toBe(1);
    expect(Project::query()->standing()->count())->toBe(0);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectCancelled)->sole();

    expect($event->reason)->toBe('Sam marked the wrong lead.');
    expect($event->subject_id)->toBe($project->id);
    expect(CustomerActivity::label(AuditAction::ProjectCancelled))->toBe('Project cancelled: sale corrected');

    // Sold again, for another amount: the same Project, not a second one.
    $this->travelTo('2026-10-12 15:00:00');
    $again = sell($this->proposal, $this->sam, ['soldAmount' => '8,000']);

    expect($again->id)->toBe($project->id);
    expect($again->isCancelled())->toBeFalse();
    expect($again->only(['cancelled_at', 'cancelled_by', 'cancelled_by_name', 'cancellation_note']))->toBe(array_fill_keys(['cancelled_at', 'cancelled_by', 'cancelled_by_name', 'cancellation_note'], null));
    expect($again->sold_cents)->toBe(800000);
    expect($again->sold_at->toDateTimeString())->toBe('2026-10-12 15:00:00');
    expect(Project::query()->count())->toBe(1);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectBroughtBack)->sole()->values_after)->toMatchArray(['cancelled' => false, 'sold_for' => '$8,000']);
    // It was created once.
    expect(AuditEvent::query()->where('action', AuditAction::ProjectCreated)->count())->toBe(1);
});

test('correcting one sale leaves the lead\'s other project as it is', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    $first = sell($this->proposal, $this->sam);
    $second = sell($cabinets, $this->sam);

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $cabinets, 'They only bought the exterior.', $this->manager);

    expect($first->fresh()->isCancelled())->toBeFalse();
    expect($second->fresh()->isCancelled())->toBeTrue();
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

/*
|--------------------------------------------------------------------------
| The record itself
|--------------------------------------------------------------------------
*/

test('the database refuses a second project for one proposal, and a project cannot be deleted', function () {
    $project = sell($this->proposal, $this->sam);

    expect(fn () => Project::factory()->forProposal($this->proposal)->create())->toThrow(UniqueConstraintViolationException::class);
    expect(fn () => $project->delete())->toThrow(LogicException::class, 'A project cannot be deleted.');
    expect(Project::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Who may see and change a Project
|--------------------------------------------------------------------------
*/

test('who may see a project, and who may edit it', function (Closure $person, bool $sees, bool $edits) {
    $project = sell($this->proposal, $this->sam);
    $user = $person($this);

    expect($user->can('view', $project))->toBe($sees);
    expect($user->can('update', $project))->toBe($edits);
})->with([
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true, true],
    'Leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true, true],
    'the salesperson who sold it' => [fn ($test) => $test->sam, true, false],
    // Location is the boundary, not whose Project it is (decision 31).
    'another Sales Consultant there' => [fn ($test) => $test->riley, true, false],
    'a Production Manager there' => [fn ($test) => $test->pat, true, false],
    'Finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), true, false],
    'a Call Center Agent' => [fn ($test) => $test->agent, false, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false, false],
    'a Production Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create(), false, false],
]);

test('a manager changes the title and description, and what they were is kept', function () {
    $project = sell($this->proposal, $this->sam);
    $was = $project->title;

    app(EditProject::class)->handle($project, '  Oak St exterior, two coats  ', "Gate code 4411.\nDog in the yard.", $this->manager);

    expect($project->fresh()->only(['title', 'description']))->toBe(['title' => 'Oak St exterior, two coats', 'description' => "Gate code 4411.\nDog in the yard."]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectEdited)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->values_before)->toEqual(['title' => $was, 'description' => null]);
    expect($event->values_after)->toEqual(['title' => 'Oak St exterior, two coats', 'description' => "Gate code 4411.\nDog in the yard."]);

    // Saving it unchanged records nothing.
    app(EditProject::class)->handle($project->fresh(), 'Oak St exterior, two coats', "Gate code 4411.\nDog in the yard.", $this->manager);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectEdited)->count())->toBe(1);
    // What was sold is not touched by an edit.
    expect($project->fresh()->sold_cents)->toBe(845000);
});

test('an edit is refused without a title, to people who may not, and on a cancelled project', function () {
    $project = sell($this->proposal, $this->sam);

    expect(fn () => app(EditProject::class)->handle($project, '   ', null, $this->manager))->toThrow(ValidationException::class, 'Give the project a title.');
    expect(fn () => app(EditProject::class)->handle($project, str_repeat('a', 161), null, $this->manager))->toThrow(ValidationException::class, 'Keep the title to 160 characters or fewer.');
    expect(fn () => app(EditProject::class)->handle($project, 'Mine now', null, $this->sam))->toThrow(AuthorizationException::class);
    expect(fn () => app(EditProject::class)->handle($project, 'Mine now', null, $this->pat))->toThrow(AuthorizationException::class);

    app(CorrectProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => app(EditProject::class)->handle($project->fresh(), 'After the fact', null, $this->manager))->toThrow(AuthorizationException::class);
    expect(AuditEvent::query()->where('action', AuditAction::ProjectEdited)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Proposals sold before Projects existed
|--------------------------------------------------------------------------
*/

test('a proposal that was sold before projects existed is given one, once', function () {
    $cabinets = addProposal($this->lead, $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    $made = sell($this->proposal, $this->manager, ['soldAmount' => '8,200']);
    sell($cabinets, $this->sam);

    $asMade = $made->only(['proposal_id', 'lead_id', 'location_id', 'account_id', 'contact_id', 'property_id', 'title', 'project_size_id', 'sold_cents', 'salesperson_id', 'salesperson_name', 'created_by', 'created_by_name']);
    $services = $made->services->pluck('pivot.is_primary', 'id')->map(fn ($main) => (bool) $main)->all();

    // As it was for the day between the two steps: sold, and no Project (nor the record of sales, which came later still).
    DB::table('sale_entries')->delete();
    DB::table('project_service')->delete();
    DB::table('projects')->delete();

    $step = require database_path('migrations/2026_10_13_132337_create_projects_for_proposals_already_sold.php');
    $step->up();

    $filled = Project::query()->where('proposal_id', $this->proposal->id)->sole();

    expect($filled->only(array_keys($asMade)))->toBe($asMade);
    expect($filled->sold_at->toDateTimeString())->toBe($this->proposal->fresh()->result_at->toDateTimeString());
    expect($filled->services->pluck('pivot.is_primary', 'id')->map(fn ($main) => (bool) $main)->all())->toBe($services);
    expect($filled->isCancelled())->toBeFalse();

    // The second one from the same Lead is named for what was sold, as a sale names it.
    expect(Project::query()->where('proposal_id', $cabinets->id)->sole()->title)->toBe('Cabinets only — 123 Oak St');

    // Run again, it makes nothing more; and a proposal that is not sold gets none.
    addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);
    $step->up();

    expect(Project::query()->count())->toBe(2);
});
