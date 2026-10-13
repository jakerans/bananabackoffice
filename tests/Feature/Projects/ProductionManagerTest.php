<?php

use App\Actions\Projects\AssignProductionManager;
use App\Actions\Projects\CancelProjectForCorrectedSale;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Notifications\ProjectNotice;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * A Project's Production Manager: taking a sold job, assigning it and
 * changing who has it (docs/slice-8-projects-plan.md, step 8b; decisions 130
 * and 132).
 *
 * Sam Sales sold a job at Lakeview and has completed its handoff. Pat and Pam
 * are Production Managers there.
 */

beforeEach(function () {
    $this->travelTo('2026-10-08 19:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);

    $this->project = soldProject($this->lakeview, $this->sam);
    completeHandoff($this->project, $this->sam);

    $this->travelTo('2026-10-09 14:00:00');
});

/**
 * The history of who has held a Project, oldest first.
 *
 * @return list<array<string, mixed>>
 */
function heldBy($project): array
{
    return DB::table('project_production_managers')->where('project_id', $project->id)->orderBy('id')
        ->get(['user_name', 'how', 'assigned_by_name', 'note'])
        ->map(fn ($row) => (array) $row)
        ->all();
}

/*
|--------------------------------------------------------------------------
| Taking a job
|--------------------------------------------------------------------------
*/

test('a production manager takes a ready job: it is theirs, in pre-production, and the salesperson is told', function () {
    Notification::fake();

    app(TakeProject::class)->handle($this->project, $this->pat);

    $project = $this->project->fresh();

    expect($project->only(['production_manager_id', 'production_manager_name']))->toBe(['production_manager_id' => $this->pat->id, 'production_manager_name' => 'Pat Production']);
    expect($project->production_manager_assigned_at->toDateTimeString())->toBe('2026-10-09 14:00:00');
    expect($project->production_state)->toBe(ProjectProductionState::PreProduction);
    expect(heldBy($project))->toBe([['user_name' => 'Pat Production', 'how' => 'taken', 'assigned_by_name' => 'Pat Production', 'note' => null]]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectTaken)->sole();

    expect($event->actor_id)->toBe($this->pat->id);
    expect($event->values_before)->toEqual(['production_manager' => null, 'production' => 'new_from_sales']);
    expect($event->values_after)->toEqual(['production_manager' => 'Pat Production', 'production' => 'pre_production']);
    expect(CustomerActivity::label(AuditAction::ProjectTaken))->toBe('Project taken by its Production Manager');

    // The salesperson is told who has it. Pat is not told about what Pat did.
    Notification::assertSentTo($this->sam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HAS_PRODUCTION_MANAGER
        && $notice->toArray($this->sam)['message'] === 'Pat Production is now the Production Manager for a job you sold.');
    Notification::assertNotSentTo([$this->pat, $this->pam, $this->manager], ProjectNotice::class);
});

test('who may take a ready job', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        app(TakeProject::class)->handle($this->project, $actor);

        expect($this->project->fresh()->production_manager_id)->toBe($actor->id);

        return;
    }

    expect(fn () => app(TakeProject::class)->handle($this->project, $actor))->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->production_manager_id)->toBeNull();
})->with([
    'a Production Manager there' => [fn ($test) => $test->pat, true],
    'a Production Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create(), false],
    // A manager assigns; they do not hold the job themselves without the role.
    'the Location Manager there' => [fn ($test) => $test->manager, false],
    'the salesperson' => [fn ($test) => $test->sam, false],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
]);

test('nobody takes or is given a project before its handoff is complete (decision 132)', function () {
    $waiting = soldProject($this->lakeview, $this->sam);

    expect(fn () => app(TakeProject::class)->handle($waiting, $this->pat))->toThrow(AuthorizationException::class);
    expect(fn () => app(AssignProductionManager::class)->handle($waiting, $this->pat->id, null, $this->manager))
        ->toThrow(ValidationException::class, 'The salesperson has not completed the handoff yet.');

    expect($waiting->fresh()->production_manager_id)->toBeNull();
    expect($waiting->fresh()->production_state)->toBe(ProjectProductionState::NewFromSales);

    completeHandoff($waiting, $this->sam);
    app(TakeProject::class)->handle($waiting->fresh(), $this->pat);

    expect($waiting->fresh()->production_manager_id)->toBe($this->pat->id);
});

test('two production managers cannot both take it: the second is told who has it', function () {
    app(TakeProject::class)->handle($this->project, $this->pat);

    // Pam still sees it as waiting on her screen and presses Take.
    expect(fn () => app(TakeProject::class)->handle($this->project, $this->pam))
        ->toThrow(ValidationException::class, 'Pat Production already has this project.');

    expect($this->project->fresh()->production_manager_id)->toBe($this->pat->id);
    expect(heldBy($this->project))->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Assigning and changing
|--------------------------------------------------------------------------
*/

test('a manager assigns the production manager, who is told, with the salesperson', function () {
    Notification::fake();

    app(AssignProductionManager::class)->handle($this->project, $this->pat->id, 'Pat knows this street.', $this->manager);

    $project = $this->project->fresh();

    expect($project->production_manager_id)->toBe($this->pat->id);
    expect($project->production_state)->toBe(ProjectProductionState::PreProduction);
    expect(heldBy($project))->toBe([['user_name' => 'Pat Production', 'how' => 'assigned', 'assigned_by_name' => 'Morgan Manager', 'note' => 'Pat knows this street.']]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectProductionManagerAssigned)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->reason)->toBe('Pat knows this street.');

    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::ASSIGNED_TO_YOU
        && $notice->toArray($this->pat)['message'] === 'You were made the Production Manager of a project.');
    Notification::assertSentTo($this->sam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HAS_PRODUCTION_MANAGER);
    Notification::assertNotSentTo([$this->manager, $this->pam], ProjectNotice::class);
});

test('only a production manager at the project\'s own location can be given it', function (Closure $person) {
    expect(fn () => app(AssignProductionManager::class)->handle($this->project, $person($this), null, $this->manager))
        ->toThrow(ValidationException::class, 'Choose one of the Production Managers at Lakeview.');

    expect($this->project->fresh()->production_manager_id)->toBeNull();
})->with([
    'nobody chosen' => [fn ($test) => null],
    'the salesperson' => [fn ($test) => $test->sam->id],
    'the manager themselves' => [fn ($test) => $test->manager->id],
    'a Production Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create()->id],
    'a Production Manager who was deactivated' => [function ($test) {
        $test->pam->forceFill(['is_active' => false])->save();

        return $test->pam->id;
    }],
    'someone who does not exist' => [fn ($test) => 987654],
]);

test('who may assign the production manager', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        app(AssignProductionManager::class)->handle($this->project, $this->pat->id, null, $actor);

        expect($this->project->fresh()->production_manager_id)->toBe($this->pat->id);

        return;
    }

    expect(fn () => app(AssignProductionManager::class)->handle($this->project, $this->pat->id, null, $actor))->toThrow(AuthorizationException::class);
    expect($this->project->fresh()->production_manager_id)->toBeNull();
})->with([
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'a Production Manager there' => [fn ($test) => $test->pam, false],
    'the salesperson' => [fn ($test) => $test->sam, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('changing the production manager needs a note, tells both, and keeps who held it', function () {
    app(TakeProject::class)->handle($this->project, $this->pat);

    Notification::fake();

    expect(fn () => app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pam->id, '  ', $this->manager))
        ->toThrow(ValidationException::class, 'Say why the Production Manager is being changed.');
    expect(fn () => app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pat->id, 'Again.', $this->manager))
        ->toThrow(ValidationException::class, 'Pat Production is already this project\'s Production Manager.');

    app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pam->id, 'Pat is on leave.', $this->manager);

    expect($this->project->fresh()->production_manager_name)->toBe('Pam Production');
    expect(heldBy($this->project))->toBe([
        ['user_name' => 'Pat Production', 'how' => 'taken', 'assigned_by_name' => 'Pat Production', 'note' => null],
        ['user_name' => 'Pam Production', 'how' => 'changed', 'assigned_by_name' => 'Morgan Manager', 'note' => 'Pat is on leave.'],
    ]);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectProductionManagerChanged)->sole();

    expect($event->reason)->toBe('Pat is on leave.');
    expect($event->values_before)->toMatchArray(['production_manager' => 'Pat Production']);
    expect($event->values_after)->toMatchArray(['production_manager' => 'Pam Production']);

    Notification::assertSentTo($this->pam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::ASSIGNED_TO_YOU);
    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::GIVEN_TO_ANOTHER);
    Notification::assertSentTo($this->sam, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::HAS_PRODUCTION_MANAGER
        && $notice->toArray($this->sam)['message'] === 'Pam Production is now the Production Manager for a job you sold.');
});

/*
|--------------------------------------------------------------------------
| A corrected sale
|--------------------------------------------------------------------------
*/

test('when a sale is corrected its production manager is told, and the cancelled project is not taken or assigned', function () {
    app(TakeProject::class)->handle($this->project, $this->pat);

    Notification::fake();

    app(CancelProjectForCorrectedSale::class)->apply($this->project->proposal, 'Wrong lead.', $this->manager);

    Notification::assertSentTo($this->pat, ProjectNotice::class, fn (ProjectNotice $notice) => $notice->kind === ProjectNotice::CANCELLED
        && $notice->toArray($this->pat)['message'] === 'A project of yours was cancelled because its sale was corrected.');

    expect(fn () => app(AssignProductionManager::class)->handle($this->project->fresh(), $this->pam->id, 'Moving it.', $this->manager))->toThrow(AuthorizationException::class);

    $other = soldProject($this->lakeview, $this->sam);
    completeHandoff($other, $this->sam);
    app(CancelProjectForCorrectedSale::class)->apply($other->proposal, 'Wrong lead.', $this->manager);

    expect(fn () => app(TakeProject::class)->handle($other->fresh(), $this->pat))->toThrow(AuthorizationException::class);
});

test('holding a project grants nothing: a production manager who loses their access can no longer open it', function () {
    app(TakeProject::class)->handle($this->project, $this->pat);

    expect($this->pat->can('view', $this->project->fresh()))->toBeTrue();

    $this->pat->forceFill(['is_active' => false])->save();

    // Still recorded as the Production Manager; the record is not their key.
    expect($this->project->fresh()->production_manager_id)->toBe($this->pat->id);
    expect($this->pat->fresh()->can('view', $this->project->fresh()))->toBeFalse();
});
