<?php

namespace App\Actions\Leads;

use App\Actions\Appointments\ReturnLeadFromSales;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Queue\EndQueueHold;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\QueueHoldEndReason;
use App\Models\Lead;
use App\Models\User;
use App\Support\Queue\QueueTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReturnLeadToCentral
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private UnassignLead $unassignLead,
        private EndQueueHold $endQueueHold,
        private QueueTransaction $queueTransaction,
        private ReturnLeadFromSales $returnLeadFromSales,
    ) {}

    /**
     * Send a routed Lead back to the central queue, unrouted, with the reason.
     *
     * Its assignee is cleared: the assignment belonged to the location it is
     * leaving. Its status does not change, with one exception: a Lead that is
     * with Sales (its appointment has passed or was cancelled) goes back to
     * Contact Center, exactly as one a manager sends back (decisions 101 and
     * 112), and still counts as issued unless a manager says it should never
     * have been booked (decision 110). After this, only people who may see
     * unrouted Leads can see it.
     *
     * Anyone holding the Lead from the call queue has that hold ended in the
     * same transaction. Its place in the cadence and its due time stay as
     * they are.
     *
     * If the database reports a deadlock or says it is busy, the Lead is read
     * again and the whole return is tried again, checks included (decision 87).
     *
     * The caller is responsible for checking that the actor may return Leads from
     * this location.
     *
     * @param  bool  $neverShouldHaveBeenBooked  For a Lead that is with Sales: it should never have been booked, so it stops counting as issued.
     *
     * @throws ValidationException
     */
    public function handle(Lead $lead, string $reason, ?User $actor = null, bool $neverShouldHaveBeenBooked = false): void
    {
        $this->queueTransaction->attempt(
            function () use ($lead, $reason, $actor, $neverShouldHaveBeenBooked): void {
                $this->returnToCentral($lead, $reason, $actor, $neverShouldHaveBeenBooked);
            },
            function () use ($lead): void {
                $lead->refresh();
            },
        );
    }

    /**
     * @throws ValidationException
     */
    private function returnToCentral(Lead $lead, string $reason, ?User $actor, bool $neverShouldHaveBeenBooked): void
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

        if ($lead->hasUpcomingAppointment()) {
            throw ValidationException::withMessages([
                'reason' => __('This lead has an upcoming appointment. Cancel or undo its appointment first.'),
            ]);
        }

        if ($lead->isWithSales() && $lead->hasAppointmentToCloseOut()) {
            throw ValidationException::withMessages([
                'reason' => __('Say what happened at this lead\'s last appointment first: run or no-show.'),
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => __('Say why this lead is being returned to central.'),
            ]);
        }

        DB::transaction(function () use ($lead, $origin, $reason, $actor, $neverShouldHaveBeenBooked): void {
            // The Lead row is locked before anything else, with a write that
            // changes nothing: the order a queue claim relies on.
            DB::table('leads')->where('id', $lead->id)->update(['id' => DB::raw('id')]);

            // A Lead that is with Sales goes back to Contact Center when it
            // leaves its Location. Whether it still counts as issued is
            // settled where it is now, before it leaves.
            $leavesSales = $lead->isWithSales();
            $salesperson = $leavesSales ? $lead->assignee : null;
            $stoppedCounting = $this->returnLeadFromSales->settleIssue($lead, $actor, $neverShouldHaveBeenBooked);

            $this->unassignLead->handle($lead, $leavesSales ? AssignmentEndReason::ReturnedFromSales : AssignmentEndReason::LocationChanged, $actor);

            $lead->lifecycle_version += $leavesSales ? 1 : 0;
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

            if ($leavesSales) {
                $this->returnLeadFromSales->handle($lead, $actor, ReturnLeadFromSales::RETURNED_TO_CENTRAL, trim($reason), ! $stoppedCounting, $origin, $salesperson);
            }
        });
    }
}
