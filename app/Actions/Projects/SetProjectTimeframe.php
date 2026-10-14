<?php

namespace App\Actions\Projects;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Project;
use App\Models\ProjectTimeframe;
use App\Models\User;
use App\Support\Projects\RoughTimeframe;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SetProjectTimeframe
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Set or change a Project's rough timeframe: the earliest and the latest
     * day the work is expected to start, with a note
     * (docs/slice-10-pre-production-plan.md, step 10a; product-architecture.md,
     * 6.3 and 6.5; decision 141). Its Production Manager, or a manager where
     * it is.
     *
     * It is an expectation, not a booked date: it reserves no crew and no day.
     * Changing one that is set needs a reason, and every timeframe the
     * Project has had is kept. Saving it unchanged records nothing.
     *
     * @param  string|null  $earliest  A day, as "2026-11-24".
     * @param  string|null  $latest  A day, as "2026-12-12".
     * @param  string|null  $reason  Why it is being changed. Needed only when one is already set.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Project $project, ?string $earliest, ?string $latest, ?string $note, ?string $reason, User $actor): void
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);
        $reason = trim((string) $reason) === '' ? null : trim((string) $reason);

        DB::transaction(function () use ($project, $earliest, $latest, $note, $reason, $actor): void {
            // Read as it is now, and held so two changes cannot cross.
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);

            Gate::forUser($actor)->authorize('managePreProduction', $project);

            $from = RoughTimeframe::typed($earliest, 'timeframeEarliest', __('Choose the earliest day the work could start.'));
            $to = RoughTimeframe::typed($latest, 'timeframeLatest', __('Choose the latest day the work is expected to start.'));

            if ($to->lessThan($from)) {
                throw ValidationException::withMessages(['timeframeLatest' => __('The latest start cannot be before the earliest.')]);
            }

            if ($to->lessThan(RoughTimeframe::today($project->location))) {
                throw ValidationException::withMessages(['timeframeLatest' => __('The latest start has already passed. Choose today or a later day.')]);
            }

            foreach (['timeframeNote' => $note, 'timeframeReason' => $reason] as $field => $text) {
                if ($text !== null && mb_strlen($text) > RoughTimeframe::NOTE_LENGTH) {
                    throw ValidationException::withMessages([$field => __('Keep this to :max characters or fewer.', ['max' => RoughTimeframe::NOTE_LENGTH])]);
                }
            }

            $had = $project->timeframe_earliest !== null && $project->timeframe_latest !== null;

            if ($had
                && $project->timeframe_earliest->toDateString() === $from->toDateString()
                && $project->timeframe_latest->toDateString() === $to->toDateString()
                && $project->timeframe_note === $note) {
                return;
            }

            if ($had && $reason === null) {
                throw ValidationException::withMessages(['timeframeReason' => __('Say why the timeframe is being changed.')]);
            }

            $before = $had
                ? ['earliest' => $project->timeframe_earliest->toDateString(), 'latest' => $project->timeframe_latest->toDateString(), 'note' => $project->timeframe_note]
                : [];

            $project->forceFill(['timeframe_earliest' => $from, 'timeframe_latest' => $to, 'timeframe_note' => $note])->save();

            $kept = new ProjectTimeframe;
            $kept->forceFill([
                'project_id' => $project->id,
                'earliest' => $from,
                'latest' => $to,
                'note' => $note,
                'reason' => $had ? $reason : null,
                'set_by' => $actor->id,
                'set_by_name' => $actor->name,
            ])->save();

            $this->recordAuditEvent->handle(
                $had ? AuditAction::ProjectTimeframeChanged : AuditAction::ProjectTimeframeSet,
                $project,
                actor: $actor,
                before: $before,
                after: ['earliest' => $from->toDateString(), 'latest' => $to->toDateString(), 'note' => $note, 'in_words' => RoughTimeframe::words($from, $to, $project->location)],
                location: $project->location,
                reason: $had ? $reason : null,
            );
        });
    }
}
