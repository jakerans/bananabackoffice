<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Leads\UnassignLead;
use App\Actions\Queue\ResumeLeadCadence;
use App\Enums\AppointmentState;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Validation\ValidationException;

class ReturnLeadFromSales
{
    /** A manager sent it back by hand (rulebook, rule 9). */
    public const string SENT_BACK = 'sent_back';

    /** It was moved to another Location while it was with Sales (decision 112). */
    public const string MOVED = 'moved';

    /** It was returned to central while it was with Sales (decision 112). */
    public const string RETURNED_TO_CENTRAL = 'returned_to_central';

    public function __construct(
        private UnassignLead $unassignLead,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private ResumeLeadCadence $resumeLeadCadence,
        private StopCountingLeadAsIssued $stopCountingLeadAsIssued,
    ) {}

    /**
     * Take the "should never have been booked" answer of a Lead that is about
     * to leave Sales, before anything about it changes (decision 110). Does
     * nothing for a Lead that is not with Sales, or when the answer is that it
     * still counts.
     *
     * The caller must be inside a transaction, having written or locked the
     * Lead row first.
     *
     * @return bool Whether an issue was voided.
     *
     * @throws ValidationException
     */
    public function settleIssue(Lead $lead, ?User $actor, bool $neverShouldHaveBeenBooked): bool
    {
        if (! $neverShouldHaveBeenBooked || ! $lead->isWithSales() || $actor === null) {
            return false;
        }

        return $this->stopCountingLeadAsIssued->handle($lead, $actor);
    }

    /**
     * Hand a Lead that is with Sales back to Contact Center (decisions 97,
     * 103, 105 and 112): it shows Qualified, is assigned to nobody, and starts
     * the call cadence over where it is now. It is marked as having come back
     * from Sales, so the call center knows what they are calling (decision
     * 114). The salesperson it was with is told inside the app.
     *
     * An appointment that is still upcoming is cancelled first, as the
     * company's cancellation with the same note (decision 111). Appointments
     * that passed or were already cancelled stay as they are.
     *
     * Its issue is left alone here: whether it still counts is settled by
     * settleIssue(), before the Lead moves.
     *
     * The caller must be inside a transaction, having written or locked the
     * Lead row first, and must have checked that the person may do this.
     *
     * @param  self::SENT_BACK|self::MOVED|self::RETURNED_TO_CENTRAL  $how
     * @param  Location|null  $auditLocation  Where it is recorded: the Location the Lead was at.
     */
    public function handle(Lead $lead, ?User $actor, string $how, ?string $note, bool $countsAsIssued, ?Location $auditLocation, ?User $salesperson = null): void
    {
        $from = $lead->status;
        $moment = now();
        $salesperson ??= $lead->assignee;

        Appointment::query()->where('lead_id', $lead->id)->upcomingAt($moment)->lockForUpdate()->get()->each(
            fn (Appointment $appointment) => $appointment->forceFill([
                'state' => AppointmentState::Cancelled,
                'cancelled_by_party' => CancellationParty::Company,
                'state_changed_at' => $moment,
                'state_changed_by' => $actor?->id,
                'state_changed_by_name' => $actor?->name,
                'state_note' => $note,
            ])->save(),
        );

        $this->unassignLead->handle($lead, AssignmentEndReason::ReturnedFromSales, $actor);

        $lead->forceFill([
            'status' => LeadStatus::Qualified,
            'status_changed_at' => $moment,
            'returned_from_sales_at' => $moment,
        ])->save();

        $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::ReturnedFromSales, $from, $actor, $salesperson?->id, note: $note);

        $this->resumeLeadCadence->afterReturnFromSales($lead, $actor, $note);

        $this->recordAuditEvent->handle(
            AuditAction::LeadReturnedFromSales,
            $lead,
            actor: $actor,
            before: ['status' => $from->value, 'salesperson' => $salesperson?->name],
            after: ['status' => LeadStatus::Qualified->value, 'how' => $how, 'counts_as_issued' => $countsAsIssued],
            location: $auditLocation,
            reason: $note,
        );

        if ($salesperson !== null && ! $salesperson->is($actor)) {
            $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::LEAD_RETURNED, AppointmentChanged::IN_APP));
        }
    }
}
