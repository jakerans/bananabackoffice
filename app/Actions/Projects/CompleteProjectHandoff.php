<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordCommission;
use App\Enums\AuditAction;
use App\Enums\HandoffColors;
use App\Enums\Role;
use App\Models\Project;
use App\Models\ProjectHandoff;
use App\Models\User;
use App\Notifications\ProjectNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CompleteProjectHandoff
{
    public function __construct(private RecordAuditEvent $recordAuditEvent, private RecordCommission $recordCommission) {}

    /**
     * Save the salesperson's answers to the handoff questions for a Project
     * (docs/slice-8-projects-plan.md, step 8b; decisions 130 and 132). The
     * salesperson who sold it, or a manager where the Project is.
     *
     * Every question but the last needs an answer, and "None" is an answer.
     * The questions are fixed here for the first version: included scope,
     * exclusions, promises beyond the proposal, timing, access, colors,
     * condition and prep, the customer's priorities, and an optional note.
     *
     * The first time, the handoff is complete from this moment: Production can
     * take the Project, and the Production Managers at its Location are told.
     * After that the answers can be corrected; what they were is kept in the
     * audit record, and the Project's Production Manager is told. All of it is
     * saved together or not at all.
     *
     * @param  array<string, mixed>  $answers  includedScope, exclusions, promises, timing, access, colorsStatus, colorsDetails, conditionPrep, customerPriorities, otherNotes.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, array $answers, User $actor): ProjectHandoff
    {
        return DB::transaction(function () use ($project, $answers, $actor): ProjectHandoff {
            // Read as it is now, and held so two saves cannot cross.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('completeHandoff', $project);

            $values = $this->checked($answers);
            $handoff = $project->handoff()->first();
            $first = $handoff === null;
            $before = $first ? [] : $handoff->only(array_keys($values));

            $handoff ??= new ProjectHandoff;
            $handoff->forceFill([
                ...$values,
                'project_id' => $project->id,
                ...($first
                    ? ['completed_by' => $actor->id, 'completed_by_name' => $actor->name, 'completed_at' => now()]
                    : ['updated_by' => $actor->id, 'updated_by_name' => $actor->name]),
            ]);

            if (! $first && ! $handoff->isDirty(array_keys($values))) {
                return $handoff;
            }

            $handoff->save();

            $this->recordAuditEvent->handle(
                $first ? AuditAction::ProjectHandoffCompleted : AuditAction::ProjectHandoffChanged,
                $project,
                actor: $actor,
                before: $first ? [] : ['answers' => $this->forAudit($before)],
                after: ['answers' => $this->forAudit($values)],
                location: $project->location,
            );

            if ($first) {
                $this->tellProduction($project, $actor);
                // A completed handoff is one of the advance's conditions (slice 20, step 20a).
                $this->recordCommission->considerAdvance($project, $actor);
            } elseif ($project->production_manager_id !== null && $project->production_manager_id !== $actor->id) {
                User::query()->find($project->production_manager_id)?->notify(new ProjectNotice($project->id, ProjectNotice::HANDOFF_CHANGED));
            }

            return $handoff;
        });
    }

    /**
     * The answers as they are kept, or a refusal that names each question
     * still missing its answer.
     *
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(array $answers): array
    {
        $text = fn (string $key): string => is_string($answers[$key] ?? null) ? trim($answers[$key]) : '';
        $values = [];
        $refused = [];

        foreach (ProjectHandoff::TEXT_ANSWERS as $question => $column) {
            $values[$column] = $text($question);

            if ($values[$column] === '') {
                $refused['handoff.'.$question] = __('Answer this, or write "None".');
            } elseif (mb_strlen($values[$column]) > ProjectHandoff::ANSWER_LENGTH) {
                $refused['handoff.'.$question] = __('Keep this to :max characters or fewer.', ['max' => ProjectHandoff::ANSWER_LENGTH]);
            }
        }

        $colors = HandoffColors::tryFrom($text('colorsStatus'));
        $details = $text('colorsDetails');

        if ($colors === null) {
            $refused['handoff.colorsStatus'] = __('Say where the colors stand.');
        } elseif ($colors === HandoffColors::Chosen && $details === '') {
            $refused['handoff.colorsDetails'] = __('Say which colors were chosen.');
        }

        foreach (['colorsDetails' => $details, 'otherNotes' => $text('otherNotes')] as $question => $value) {
            if (mb_strlen($value) > ProjectHandoff::ANSWER_LENGTH) {
                $refused['handoff.'.$question] = __('Keep this to :max characters or fewer.', ['max' => ProjectHandoff::ANSWER_LENGTH]);
            }
        }

        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }

        return [
            ...$values,
            'colors_status' => $colors,
            'colors_details' => $details === '' ? null : $details,
            'other_notes' => $text('otherNotes') === '' ? null : $text('otherNotes'),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function forAudit(array $values): array
    {
        return array_map(fn (mixed $value): mixed => $value instanceof HandoffColors ? $value->value : $value, $values);
    }

    /**
     * Tell the Production Managers at the Project's Location that a sold job
     * is ready to be taken (product-architecture.md, 20). If it already has a
     * Production Manager, only they are told.
     */
    private function tellProduction(Project $project, User $actor): void
    {
        $location = $project->location;

        $people = $project->production_manager_id !== null
            ? User::query()->whereKey($project->production_manager_id)->get()
            : User::query()->where('is_active', true)->with('accessGrants')->get()->filter(fn (User $user): bool => $user->holdsRoleAt(Role::ProductionManager, $location));

        foreach ($people->where('id', '!=', $actor->id) as $person) {
            $person->notify(new ProjectNotice($project->id, ProjectNotice::READY_FOR_PRODUCTION, name: $location->name));
        }
    }
}
