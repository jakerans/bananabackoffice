<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class EditProject
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Change a Project's title and description
     * (docs/slice-8-projects-plan.md, step 8a). A manager where the Project
     * is. The title is ordinary editable text and is never made again from
     * the service or the address (decision 54).
     *
     * What they were before is kept in the audit record. Nothing else about a
     * Project is typed: what was sold and for how much come from its
     * proposal. Saving it unchanged records nothing.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, ?string $title, ?string $description, User $actor): void
    {
        $title = trim((string) $title);
        $description = trim((string) $description) === '' ? null : trim((string) $description);

        DB::transaction(function () use ($project, $title, $description, $actor): void {
            // Read as it is now, and held so two edits cannot cross.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('update', $project);

            if ($title === '') {
                throw ValidationException::withMessages(['projectTitle' => __('Give the project a title.')]);
            }

            if (mb_strlen($title) > Project::TITLE_LENGTH) {
                throw ValidationException::withMessages([
                    'projectTitle' => __('Keep the title to :max characters or fewer.', ['max' => Project::TITLE_LENGTH]),
                ]);
            }

            if ($title === $project->title && $description === $project->description) {
                return;
            }

            $before = ['title' => $project->title, 'description' => $project->description];

            $project->forceFill(['title' => $title, 'description' => $description])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectEdited,
                $project,
                actor: $actor,
                before: $before,
                after: ['title' => $title, 'description' => $description],
                location: $project->location,
            );
        });
    }
}
