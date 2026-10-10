<?php

namespace App\Actions\Queue;

use App\Enums\QueueEventType;
use App\Enums\QueueHoldEndReason;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\QueueEligibility;
use App\Support\Queue\QueueHistory;
use Illuminate\Support\Facades\DB;

class EndQueueHold
{
    public function __construct(
        private QueueHistory $history,
        private QueueEligibility $eligibility,
    ) {}

    /**
     * Take a held Lead back from the person holding it, and say why.
     *
     * Exactly one history entry is written, naming the holder: "hold ended"
     * with the reason, or "hold expired" when its time had already run out and
     * nothing had tidied it up yet. A logged attempt is the exception: it
     * always writes "hold ended" with its own reason, also for a hold whose
     * time had run out (docs/slice-4-plan.md, contract 4). A cadence with no
     * hold is left alone and nothing is recorded. The due time is not touched.
     *
     * The caller must be inside a transaction and hold the cadence row's lock,
     * having dealt with its own row first (docs/slice-4-plan.md, "Locks").
     *
     * @return bool Whether there was a hold to end.
     */
    public function handle(
        LeadCadence $cadence,
        QueueHoldEndReason $reason,
        ?Lead $lead = null,
        ?User $actor = null,
        ?ContactAttempt $attempt = null,
        bool $byTimer = false,
    ): bool {
        if (! $cadence->hasHoldRecorded()) {
            return false;
        }

        $holderId = $cadence->held_by_user_id;
        $token = $cadence->hold_token;
        $byAttempt = in_array($reason, [QueueHoldEndReason::AttemptLogged, QueueHoldEndReason::AttemptLoggedByOther], true);
        $expired = ! $byAttempt && ! $cadence->isHeldAt(now());

        $released = DB::table('lead_cadences')
            ->where('id', $cadence->id)
            ->where('held_by_user_id', $holderId)
            ->where('hold_token', $token)
            ->update(['held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null, 'updated_at' => now()]);

        if ($released !== 1) {
            return false;
        }

        $cadence->forceFill(['held_by_user_id' => null, 'hold_token' => null, 'held_at' => null, 'held_until' => null])->syncOriginal();

        $type = $expired ? QueueEventType::HoldExpired : QueueEventType::HoldEnded;
        $why = $expired ? null : $reason->value;

        if ($byTimer) {
            $this->history->recordByTimer($cadence, $type, $lead, reason: $why, holdToken: $token, affectedUserId: $holderId);
        } else {
            $this->history->record($cadence, $type, $lead, $actor, reason: $why, holdToken: $token, affectedUserId: $holderId, attempt: $attempt);
        }

        return true;
    }

    /**
     * End whatever hold is on a Lead. For the actions that change the Lead:
     * they write or lock the Lead row first, then call this in the same
     * transaction.
     */
    public function forLead(Lead $lead, QueueHoldEndReason $reason, ?User $actor = null): bool
    {
        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();

        return $cadence !== null && $this->handle($cadence, $reason, $lead, $actor);
    }

    /**
     * End the hold a staff member has, because their access changed. For the
     * actions that change the person: they write or lock that person's row
     * first, then call this in the same transaction.
     *
     * @param  bool  $onlyIfTheyMayNoLongerWorkIt  Leave the hold when the person, as they are now, may still work that Lead.
     */
    public function forStaffMember(User $staff, bool $onlyIfTheyMayNoLongerWorkIt = false, ?User $actor = null): bool
    {
        // The Lead row is locked before its cadence row, as the claim does it.
        // Writing the history entry needs the Lead row; taking it only then,
        // with the cadence row already locked, can deadlock with a claim that
        // holds the Lead and waits for the cadence. This first read is only a
        // hint about which Lead: the locking read below finds the hold.
        $heldLeadId = LeadCadence::query()->where('held_by_user_id', $staff->id)->value('lead_id');

        if ($heldLeadId !== null) {
            Lead::query()->whereKey($heldLeadId)->lockForUpdate()->first();
        }

        $cadence = LeadCadence::query()->where('held_by_user_id', $staff->id)->lockForUpdate()->first();

        if ($cadence === null) {
            return false;
        }

        $lead = Lead::query()->with('location')->findOrFail($cadence->lead_id);

        if ($onlyIfTheyMayNoLongerWorkIt) {
            $current = User::query()->with('accessGrants')->find($staff->id);

            if ($current !== null && $this->eligibility->mayWork($current, $lead)) {
                return false;
            }
        }

        return $this->handle($cadence, QueueHoldEndReason::AccessLost, $lead, $actor);
    }

    /**
     * End every hold on a Lead at a Location that is being deactivated: no
     * grant covers a deactivated Location, so nobody may work those Leads. The
     * caller writes or locks the Location row first.
     *
     * @return int How many holds were ended.
     */
    public function atLocation(Location $location, ?User $actor = null): int
    {
        $heldThere = fn () => LeadCadence::query()
            ->whereNotNull('held_by_user_id')
            ->whereIn('lead_id', Lead::query()->select('id')->where('location_id', $location->id));

        // The Lead rows are locked before their cadence rows, as the claim
        // does it, for the reason given in forStaffMember(). This first read
        // is only a hint about which Leads: the locking read below finds the
        // holds.
        $heldLeadIds = $heldThere()->orderBy('lead_id')->pluck('lead_id');

        if ($heldLeadIds->isNotEmpty()) {
            Lead::query()->whereKey($heldLeadIds)->orderBy('id')->lockForUpdate()->get();
        }

        $held = $heldThere()->lockForUpdate()->get();

        $ended = 0;

        foreach ($held as $cadence) {
            $lead = Lead::query()->findOrFail($cadence->lead_id)->setRelation('location', $location);

            if ($this->handle($cadence, QueueHoldEndReason::AccessLost, $lead, $actor)) {
                $ended++;
            }
        }

        return $ended;
    }
}
