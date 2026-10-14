<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ProjectProductionState;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ProjectNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AssignProductionManager
{
    public const string ASSIGNED = 'assigned';

    public const string CHANGED = 'changed';

    public const string TAKEN = 'taken';

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Make someone the Production Manager of a Project, or change who it is
     * (docs/slice-8-projects-plan.md, step 8b; decision 130, answer 7). A
     * manager where the Project is. Changing it needs a note.
     *
     * Only a person with the Production Manager role at the Project's own
     * Location can hold it, and only once the salesperson's handoff is
     * complete (decision 132). Holding it grants nothing: Location decides
     * who may see a Project (product-architecture.md, 2).
     *
     * The first time, the Project moves from "New from Sales" to
     * "Pre-production". Who held it before stays in its history. The new
     * Production Manager, the one it was taken from and the salesperson are
     * each told inside the app. All of it is saved together or not at all.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, ?int $managerId, ?string $note, User $actor): void
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        DB::transaction(function () use ($project, $managerId, $note, $actor): void {
            // Read as it is now, and held so two people cannot assign at once.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('assignProductionManager', $project);

            $this->place($project, $this->eligible($project, $managerId), $project->production_manager_id === null ? self::ASSIGNED : self::CHANGED, $note, $actor);
        });
    }

    /**
     * Put the Project in a Production Manager's hands and record it. The
     * caller has authorized the person acting and holds the Project's row.
     *
     * @param  self::ASSIGNED|self::CHANGED|self::TAKEN  $how
     *
     * @throws ValidationException
     */
    public function place(Project $project, User $manager, string $how, ?string $note, User $actor): void
    {
        if (! $project->hasHandoff()) {
            throw ValidationException::withMessages([
                'productionManagerId' => __('The salesperson has not completed the handoff yet. Production takes a project once they have.'),
            ]);
        }

        if ($project->production_manager_id === $manager->id) {
            throw ValidationException::withMessages([
                'productionManagerId' => __(':name is already this project\'s Production Manager.', ['name' => $manager->name]),
            ]);
        }

        if ($how === self::CHANGED && $note === null) {
            throw ValidationException::withMessages(['assignmentNote' => __('Say why the Production Manager is being changed.')]);
        }

        $earlier = $project->production_manager_id === null ? null : User::query()->find($project->production_manager_id);
        $before = ['production_manager' => $project->production_manager_name, 'production' => $project->production_state->value];

        // A job just sold comes into pre-production. One already further on, scheduled say, stays where it is.
        $state = $project->production_state === ProjectProductionState::NewFromSales ? ProjectProductionState::PreProduction : $project->production_state;

        $project->forceFill([
            'production_manager_id' => $manager->id,
            'production_manager_name' => $manager->name,
            'production_manager_assigned_at' => now(),
            'production_state' => $state,
        ])->save();

        DB::table('project_production_managers')->insert([
            'project_id' => $project->id,
            'user_id' => $manager->id,
            'user_name' => $manager->name,
            'how' => $how,
            'assigned_by' => $actor->id,
            'assigned_by_name' => $actor->name,
            'note' => $note,
            'created_at' => now(),
        ]);

        $this->recordAuditEvent->handle(
            match ($how) {
                self::TAKEN => AuditAction::ProjectTaken,
                self::CHANGED => AuditAction::ProjectProductionManagerChanged,
                default => AuditAction::ProjectProductionManagerAssigned,
            },
            $project,
            actor: $actor,
            before: $before,
            after: ['production_manager' => $manager->name, 'production' => $state->value],
            location: $project->location,
            reason: $note,
        );

        // Every ownership transfer tells the new owner (product-architecture.md, 20). Nobody is told about what they did themselves.
        if ($manager->id !== $actor->id) {
            $manager->notify(new ProjectNotice($project->id, ProjectNotice::ASSIGNED_TO_YOU));
        }

        if ($earlier !== null && $earlier->id !== $actor->id) {
            $earlier->notify(new ProjectNotice($project->id, ProjectNotice::GIVEN_TO_ANOTHER));
        }

        if ($project->salesperson_id !== null && $project->salesperson_id !== $actor->id && $project->salesperson_id !== $manager->id) {
            User::query()->find($project->salesperson_id)?->notify(new ProjectNotice($project->id, ProjectNotice::HAS_PRODUCTION_MANAGER, name: $manager->name));
        }
    }

    /**
     * The person chosen, if they can hold a Project there: active, with the
     * Production Manager role at the Project's own Location.
     *
     * @throws ValidationException
     */
    private function eligible(Project $project, ?int $managerId): User
    {
        $manager = $managerId === null ? null : User::query()->where('is_active', true)->with('accessGrants')->find($managerId);

        if ($manager === null || ! $manager->holdsRoleAt(Role::ProductionManager, $project->location)) {
            throw ValidationException::withMessages([
                'productionManagerId' => __('Choose one of the Production Managers at :location.', ['location' => $project->location->name]),
            ]);
        }

        return $manager;
    }
}
