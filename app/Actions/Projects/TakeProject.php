<?php

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TakeProject
{
    public function __construct(private AssignProductionManager $assignProductionManager) {}

    /**
     * Take a sold job that is waiting as its Production Manager
     * (docs/slice-8-projects-plan.md, step 8b; decision 130, answer 7). A
     * Production Manager at the Project's own Location, for themselves.
     *
     * Only a Project whose handoff is complete and that nobody has yet
     * (decision 132). Two people taking the same one at the same moment
     * cannot both have it: the second is told who does.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, User $actor): void
    {
        DB::transaction(function () use ($project, $actor): void {
            // Read as it is now, and held so two people cannot take it at once.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            if ($project->production_manager_id !== null && $project->production_manager_id !== $actor->id && Gate::forUser($actor)->allows('view', $project)) {
                throw ValidationException::withMessages([
                    'productionManagerId' => __(':name already has this project.', ['name' => $project->production_manager_name]),
                ]);
            }

            Gate::forUser($actor)->authorize('take', $project);

            $this->assignProductionManager->place($project, $actor, AssignProductionManager::TAKEN, null, $actor);
        });
    }
}
