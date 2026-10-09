<?php

namespace App\Support\Queue;

use App\Enums\QueueEventType;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadQueueEvent;
use App\Models\User;
use App\Support\Access\Impersonation;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the history of the call queue, one entry at a time.
 *
 * Call it inside the same database transaction as the change it records. The
 * Lead's Location is copied as it stands. When an Owner is viewing Backoffice
 * as a staff member, the actor is that staff member and the entry also names
 * the Owner, as everywhere else.
 */
class QueueHistory
{
    public function __construct(private Impersonation $impersonation) {}

    /**
     * Record something a person did, or that happened because of what they did.
     * The actor is the signed-in person unless one is given.
     *
     * @param  int|null  $step  The cadence position; the attempt now due unless given.
     */
    public function record(
        LeadCadence $cadence,
        QueueEventType $type,
        ?Lead $lead = null,
        ?User $actor = null,
        ?string $reason = null,
        ?string $note = null,
        ?int $step = null,
        ?string $holdToken = null,
        ?int $affectedUserId = null,
        ?ContactAttempt $attempt = null,
        ?CarbonInterface $cooldownUntil = null,
    ): LeadQueueEvent {
        $signedIn = Auth::user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        return $this->write($cadence, $type, $lead, $actor, $this->impersonation->impersonator(), $reason, $note, $step, $holdToken, $affectedUserId, $attempt, $cooldownUntil);
    }

    /**
     * Record something the background timer did. It has no actor.
     */
    public function recordByTimer(
        LeadCadence $cadence,
        QueueEventType $type,
        ?Lead $lead = null,
        ?string $reason = null,
        ?int $step = null,
        ?string $holdToken = null,
        ?int $affectedUserId = null,
    ): LeadQueueEvent {
        return $this->write($cadence, $type, $lead, null, null, $reason, null, $step, $holdToken, $affectedUserId, null, null);
    }

    private function write(
        LeadCadence $cadence,
        QueueEventType $type,
        ?Lead $lead,
        ?User $actor,
        ?User $impersonator,
        ?string $reason,
        ?string $note,
        ?int $step,
        ?string $holdToken,
        ?int $affectedUserId,
        ?ContactAttempt $attempt,
        ?CarbonInterface $cooldownUntil,
    ): LeadQueueEvent {
        $lead ??= Lead::query()->with('location')->findOrFail($cadence->lead_id);

        $event = new LeadQueueEvent;

        $event->forceFill([
            'lead_id' => $cadence->lead_id,
            'lead_cadence_id' => $cadence->id,
            'type' => $type,
            'reason' => $reason,
            'note' => $note,
            'cycle' => $cadence->cycle,
            'step' => $step ?? $cadence->nextStep(),
            'hold_token' => $holdToken,
            'contact_attempt_id' => $attempt?->id,
            'location_id' => $lead->location_id,
            'location_name' => $lead->location?->name,
            'actor_id' => $actor?->getKey(),
            'actor_name' => $actor?->name,
            'impersonator_id' => $impersonator?->getKey(),
            'impersonator_name' => $impersonator?->name,
            'affected_user_id' => $affectedUserId,
            'cooldown_until' => $cooldownUntil,
            'created_at' => now(),
        ])->save();

        return $event;
    }
}
