<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Compensation\RecordCommission;
use App\Enums\AuditAction;
use App\Enums\HandoffColors;
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
    public function __construct(private RecordAuditEvent $recordAuditEvent, private RecordCommission $recordCommission, private ReleaseToProduction $releaseToProduction) {}

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

            $handoff = $project->handoff()->first();
            $first = $handoff === null;
            $values = $this->checked($answers, $first);
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

            if ($first) {
                // The draft kept while it was typed has done its work (slice 41).
                $project->forceFill(['handoff_draft' => null, 'handoff_draft_saved_at' => null])->save();
            }

            $this->recordAuditEvent->handle(
                $first ? AuditAction::ProjectHandoffCompleted : AuditAction::ProjectHandoffChanged,
                $project,
                actor: $actor,
                before: $first ? [] : ['answers' => $this->forAudit($before)],
                after: ['answers' => $this->forAudit($values)],
                location: $project->location,
            );

            if ($first) {
                // A job still waiting for its deposit is announced when the deposit comes in (slice 39).
                if (! $project->awaiting_deposit) {
                    $this->releaseToProduction->tellProduction($project, $actor);
                }

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
     * @param  bool  $first  Whether the handoff is being completed, not corrected.
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function checked(array $answers, bool $first): array
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

        // The job's Company Cam link (slice 41; decision 239): needed to complete a handoff.
        // One completed before it was asked for can still be corrected without it.
        $link = $text('companyCamUrl');

        if ($link === '' && $first) {
            $refused['handoff.companyCamUrl'] = __('Paste the Company Cam link for this job.');
        } elseif ($link !== '' && (mb_strlen($link) > ProjectHandoff::URL_LENGTH || filter_var($link, FILTER_VALIDATE_URL) === false || ! str_starts_with(mb_strtolower($link), 'https://'))) {
            $refused['handoff.companyCamUrl'] = __('Give the whole link, starting with https://, as Company Cam shares it.');
        }

        if ($refused !== []) {
            throw ValidationException::withMessages($refused);
        }

        return [
            ...$values,
            'company_cam_url' => $link === '' ? null : $link,
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
}
