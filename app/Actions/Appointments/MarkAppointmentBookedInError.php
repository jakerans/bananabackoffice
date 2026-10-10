<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Leads\UnassignLead;
use App\Actions\Queue\ResumeLeadCadence;
use App\Enums\AppointmentState;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class MarkAppointmentBookedInError
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private AssignLead $assignLead,
        private UnassignLead $unassignLead,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private ResumeLeadCadence $resumeLeadCadence,
    ) {}

    /**
     * Undo a booking that should not have been made (rulebook, rule 10): a
     * manager, with a note.
     *
     * The appointment is kept and marked "booked in error". The Lead is put
     * back exactly as it was a moment before the booking (decision 96): the
     * status it had, the person it was assigned to then if they may still
     * work it (nobody otherwise), and back in the call queue at the attempt it
     * had reached if the booking took it out. The issue is voided, so it does
     * not count as issued. All of it is saved together or not at all.
     *
     * Who may do this is checked here again, on the Lead as it is now. If the
     * database reports a deadlock or says it is busy, the whole undo is tried
     * again (decision 87).
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            if ($note === '') {
                throw ValidationException::withMessages([
                    'bookedInErrorNote' => __('Say why this appointment was booked in error.'),
                ]);
            }

            $appointment = Appointment::query()->lockForUpdate()->findOrFail($appointment->id);

            if ($appointment->lead_id !== $lead->id || ! $appointment->stands() || $lead->status !== LeadStatus::AppointmentBooked) {
                throw ValidationException::withMessages([
                    'bookedInErrorNote' => __('This appointment is no longer standing, so there is nothing to undo.'),
                ]);
            }

            $moment = now();
            $salespersonName = $appointment->salesperson_name;

            $appointment->forceFill([
                'state' => AppointmentState::BookedInError,
                'state_changed_at' => $moment,
                'state_changed_by' => $actor->id,
                'state_changed_by_name' => $actor->name,
                'state_note' => $note,
            ])->save();

            LeadIssue::query()->where('appointment_id', $appointment->id)->counted()->get()->each(
                fn (LeadIssue $issue) => $issue->forceFill(['voided_at' => $moment, 'voided_by' => $actor->id, 'voided_by_name' => $actor->name])->save(),
            );

            $this->restoreAssignee($lead, $appointment, $actor);

            $counted = LeadIssue::query()->where('lead_id', $lead->id)->counted();

            $lead->forceFill([
                'status' => $appointment->previous_status,
                'status_changed_at' => $moment,
                'first_issued_at' => (clone $counted)->min('issued_at'),
                'last_issued_at' => (clone $counted)->max('issued_at'),
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::AppointmentBookedInError, LeadStatus::AppointmentBooked, $actor, $lead->assigned_user_id, note: $note);

            if ($appointment->previous_in_queue) {
                $this->resumeLeadCadence->afterBookedInError($lead, $actor, $note);
            }

            $this->recordAuditEvent->handle(
                AuditAction::LeadAppointmentBookedInError,
                $lead,
                actor: $actor,
                before: ['status' => LeadStatus::AppointmentBooked->value, 'salesperson' => $salespersonName],
                after: ['status' => $appointment->previous_status->value, 'assignee' => $lead->assignee?->name],
                location: $lead->location,
                reason: $note,
            );

            return true;
        }, retryWhenBusy: true);
    }

    /**
     * Give the Lead back to whoever had it before the booking, if they may
     * still work it. Otherwise it is left with nobody, where a manager will
     * see it.
     */
    private function restoreAssignee(Lead $lead, Appointment $appointment, User $actor): void
    {
        $previous = $appointment->previous_assignee_id === null ? null : User::query()->find($appointment->previous_assignee_id);

        if ($previous !== null && $previous->canBeAssigned($lead)) {
            $this->assignLead->handle($lead, $previous, $actor);

            return;
        }

        $this->unassignLead->handle($lead, AssignmentEndReason::AppointmentUndone, $actor);
    }
}
