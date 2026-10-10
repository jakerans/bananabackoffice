<?php

namespace App\Actions\Queue;

use App\Enums\CadenceEndReason;
use App\Enums\CadenceState;
use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\User;
use App\Support\Queue\QueueHistory;
use Illuminate\Support\Facades\DB;

class EndLeadCadence
{
    public function __construct(
        private QueueHistory $history,
        private EndQueueHold $endQueueHold,
    ) {}

    /**
     * Take a Lead out of the call queue: a real connection was logged, it was
     * disqualified, or a Deferred Follow-up was scheduled.
     *
     * Any hold on it ends first, with its own history entry. The Lead's
     * status, its status history and its lifecycle revision are not touched,
     * and a Lead that has left does not come back by itself. A cadence that is
     * not in the queue is left alone and nothing is recorded.
     *
     * The caller must be inside a transaction and hold the cadence row's lock,
     * having written or locked the Lead row first.
     *
     * @param  QueueHoldEndReason|null  $holdReason  What the holder is told. A connection must say who logged it.
     * @return bool Whether the cadence was ended.
     */
    public function handle(
        LeadCadence $cadence,
        CadenceEndReason $reason,
        ?Lead $lead = null,
        ?User $actor = null,
        ?QueueHoldEndReason $holdReason = null,
        ?ContactAttempt $attempt = null,
        bool $byTimer = false,
    ): bool {
        if (! $cadence->isInQueue()) {
            return false;
        }

        $holdReason ??= match ($reason) {
            CadenceEndReason::Disqualified => QueueHoldEndReason::Disqualified,
            CadenceEndReason::DeferredFollowUp => QueueHoldEndReason::DeferredFollowUp,
            CadenceEndReason::AppointmentBooked => QueueHoldEndReason::AppointmentBooked,
            CadenceEndReason::Connected => QueueHoldEndReason::AttemptLoggedByOther,
        };

        $this->endQueueHold->handle($cadence, $holdReason, $lead, $actor, $attempt, $byTimer);

        $ended = DB::table('lead_cadences')
            ->where('id', $cadence->id)
            ->where('state', CadenceState::InQueue->value)
            ->update(['state' => $reason->state()->value, 'due_at' => null, 'ended_at' => now(), 'updated_at' => now()]);

        if ($ended !== 1) {
            return false;
        }

        $cadence->forceFill(['state' => $reason->state(), 'due_at' => null, 'ended_at' => now()])->syncOriginal();

        if ($byTimer) {
            $this->history->recordByTimer($cadence, QueueEventType::Ended, $lead, reason: $reason->value, step: $cadence->attempts_made);
        } else {
            $this->history->record($cadence, QueueEventType::Ended, $lead, $actor, reason: $reason->value, step: $cadence->attempts_made, attempt: $attempt);
        }

        return true;
    }

    /**
     * End the cadence of a Lead that is being disqualified or scheduled for a
     * Deferred Follow-up. Those actions write the Lead row first, then call
     * this in the same transaction, so both are saved or neither is.
     */
    public function forLead(Lead $lead, CadenceEndReason $reason, ?User $actor = null): bool
    {
        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();

        return $cadence !== null && $this->handle($cadence, $reason, $lead, $actor);
    }
}
