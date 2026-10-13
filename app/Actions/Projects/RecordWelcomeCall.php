<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\User;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class RecordWelcomeCall
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Tick "Welcome call made" on a Project in pre-production
     * (docs/slice-10-pre-production-plan.md, step 10a; decision 141). Its
     * Production Manager, or a manager where it is.
     *
     * It is a checklist item: the day the call was made and an optional
     * note. How the call is made is a procedure the business keeps outside
     * Backoffice, and nothing is sent to the customer. Ticked again, the day
     * and note are replaced and what they were is kept in the audit record.
     *
     * @param  string|null  $day  The day the call was made, as "2026-10-14".
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, ?string $day, ?string $note, User $actor): void
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        DB::transaction(function () use ($project, $day, $note, $actor): void {
            // Read as it is now, and held so two people cannot tick it at once.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $madeOn = RoughTimeframe::typed($day, 'welcomeCallOn', __('Choose the day the call was made.'));

            if ($madeOn->greaterThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['welcomeCallOn' => __('The call cannot be on a day that has not come yet.')]);
            }

            if ($note !== null && mb_strlen($note) > RoughTimeframe::NOTE_LENGTH) {
                throw ValidationException::withMessages(['welcomeCallNote' => __('Keep the note to :max characters or fewer.', ['max' => RoughTimeframe::NOTE_LENGTH])]);
            }

            if ($project->welcome_call_on?->toDateString() === $madeOn->toDateString() && $project->welcome_call_note === $note) {
                return;
            }

            $before = $project->welcome_call_on === null ? [] : ['made_on' => $project->welcome_call_on->toDateString(), 'note' => $project->welcome_call_note];

            $project->forceFill([
                'welcome_call_on' => $madeOn,
                'welcome_call_note' => $note,
                'welcome_call_by' => $actor->id,
                'welcome_call_by_name' => $actor->name,
                'welcome_call_recorded_at' => now(),
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectWelcomeCallRecorded,
                $project,
                actor: $actor,
                before: $before,
                after: ['made_on' => $madeOn->toDateString(), 'note' => $note],
                location: $project->location,
            );
        });
    }

    /**
     * Untick "Welcome call made", for one ticked by mistake. The same people,
     * with a note saying why. The Project waits for its call again.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function undo(Project $project, ?string $note, User $actor): void
    {
        $note = trim((string) $note);

        DB::transaction(function () use ($project, $note, $actor): void {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            if ($project->welcome_call_on === null) {
                throw ValidationException::withMessages(['welcomeCallUndoNote' => __('The welcome call is not ticked.')]);
            }

            if ($note === '') {
                throw ValidationException::withMessages(['welcomeCallUndoNote' => __('Say why it is being unticked.')]);
            }

            $before = ['made_on' => $project->welcome_call_on->toDateString(), 'note' => $project->welcome_call_note];

            $project->forceFill([
                'welcome_call_on' => null,
                'welcome_call_note' => null,
                'welcome_call_by' => null,
                'welcome_call_by_name' => null,
                'welcome_call_recorded_at' => null,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::ProjectWelcomeCallUndone,
                $project,
                actor: $actor,
                before: $before,
                after: ['made_on' => null],
                location: $project->location,
                reason: $note,
            );
        });
    }
}
