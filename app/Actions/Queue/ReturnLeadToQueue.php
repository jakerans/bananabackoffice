<?php

namespace App\Actions\Queue;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\QueueEventType;
use App\Models\AccessGrant;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\CadenceSchedule;
use App\Support\Queue\CallingHours;
use App\Support\Queue\QueueHistory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ReturnLeadToQueue
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private QueueHistory $history,
    ) {}

    /**
     * Put a Lead that has left the call queue back in it, with a note saying why.
     *
     * A Lead whose cadence finished with no contact, or that has no attempts
     * left, restarts: a new cycle, attempt 1 due at the next opening. Any other
     * Lead resumes where it stopped: its next unused attempt is due at the
     * next opening. The Lead's status, assignee and lifecycle revision are not
     * touched.
     *
     * The person, their access grants and the Lead are read again here, and
     * the person as they are now is authorized against the Lead as it is now:
     * a copy loaded before they were deactivated or lost a grant decides
     * nothing. The change, its history entry and its audit
     * event are saved together or not at all. The change is made only if the
     * Lead is still out of the queue, so a second submit changes nothing.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, ?string $note, User $actor): void
    {
        $note = trim((string) $note);

        DB::transaction(function () use ($lead, $note, $actor): void {
            // The same order as the claim: the person, their grants, then the Lead.
            $actor = User::query()->sharedLock()->find($actor->id);

            if ($actor === null) {
                throw new AuthorizationException;
            }

            $actor->setRelation('accessGrants', AccessGrant::query()->where('user_id', $actor->id)->sharedLock()->get());

            $current = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            Gate::forUser($actor)->authorize('returnToQueue', $current);

            if ($note === '') {
                throw ValidationException::withMessages([
                    'note' => __('Say why this lead is going back in the queue.'),
                ]);
            }

            $cadence = LeadCadence::query()->where('lead_id', $current->id)->lockForUpdate()->firstOrFail();
            $schedule = CadenceSchedule::fromSettings(ContactCenterSettings::current());
            $moment = now();

            $restarts = $cadence->state === CadenceState::FinishedNoContact || $cadence->attempts_made >= $schedule->totalAttempts();
            $before = ['state' => $cadence->state->value, 'cycle' => $cadence->cycle, 'step' => $cadence->attempts_made];

            $changes = [
                'state' => CadenceState::InQueue,
                'cycle' => $restarts ? $cadence->cycle + 1 : $cadence->cycle,
                'attempts_made' => $restarts ? 0 : $cadence->attempts_made,
                'due_at' => $schedule->hours->nextOpeningAtOrAfter($moment, CallingHours::timezoneFor($current->location)),
                'entered_at' => $restarts ? $moment : $cadence->entered_at,
                'ended_at' => null,
            ];

            $returned = DB::table('lead_cadences')
                ->where('id', $cadence->id)
                ->where('state', '<>', CadenceState::InQueue->value)
                ->update([...$changes, 'state' => CadenceState::InQueue->value, 'updated_at' => $moment]);

            if ($returned !== 1) {
                throw ValidationException::withMessages([
                    'note' => __('This lead is already in the queue.'),
                ]);
            }

            $cadence->forceFill($changes)->syncOriginal();

            $this->history->record($cadence, QueueEventType::ReturnedToQueue, $current, $actor, note: $note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadReturnedToQueue,
                $current,
                actor: $actor,
                before: $before,
                after: ['state' => CadenceState::InQueue->value, 'cycle' => $cadence->cycle, 'step' => $cadence->nextStep()],
                location: $current->location,
                reason: $note,
            );
        });
    }
}
