<?php

namespace App\Actions\Leads;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Queue\EndQueueHold;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\QueueHoldEndReason;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnLeadToCentral
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private UnassignLead $unassignLead,
        private EndQueueHold $endQueueHold,
    ) {}

    /**
     * Send a routed Lead back to the central queue, unrouted, with the reason.
     *
     * Its assignee is cleared: the assignment belonged to the location it is
     * leaving. Its status does not change. After this, only people who may see
     * unrouted Leads can see it.
     *
     * Anyone holding the Lead from the call queue has that hold ended in the
     * same transaction. Its place in the cadence and its due time stay as
     * they are.
     *
     * The caller is responsible for checking that the actor may return Leads from
     * this location.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, string $reason, ?User $actor = null): void
    {
        $origin = $lead->location;

        if ($origin === null) {
            throw ValidationException::withMessages([
                'reason' => __('This lead is already in the central queue.'),
            ]);
        }

        if (! $lead->isActive()) {
            throw ValidationException::withMessages([
                'reason' => __('This lead is disqualified. Reopen it before sending it anywhere.'),
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('Say why this lead is being returned to central.'),
            ]);
        }

        DB::transaction(function () use ($lead, $origin, $reason, $actor): void {
            // The Lead row is locked before anything else, with a write that
            // changes nothing: the order a queue claim relies on.
            DB::table('leads')->where('id', $lead->id)->update(['id' => DB::raw('id')]);

            $this->unassignLead->handle($lead, AssignmentEndReason::LocationChanged, $actor);

            $lead->location_id = null;
            $lead->routed_at = null;
            $lead->save();
            $lead->setRelation('location', null);

            $this->recordAuditEvent->handle(
                AuditAction::LeadReturnedToCentral,
                $lead,
                actor: $actor,
                before: ['location' => $origin->name],
                after: ['location' => null],
                // Recorded against the location it left, which is where it happened.
                location: $origin,
                reason: trim($reason),
            );

            $this->endQueueHold->forLead($lead, QueueHoldEndReason::Moved, $actor);
        });
    }
}
