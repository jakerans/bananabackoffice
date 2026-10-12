<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CorrectAppointmentCloseOut
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Correct an appointment that was marked run when it was a no-show, or the
     * other way round: a manager, with a note, because the answer feeds the
     * Sales figures (docs/slice-7-appointment-results-plan.md, step 7a).
     *
     * The appointment takes the other answer and keeps who first gave one in
     * the Lead's history. The Lead's status follows only if nothing has
     * happened to it since: it still shows what the first answer gave it and
     * this is still its latest appointment. Otherwise only the appointment is
     * corrected.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            $appointment = Appointment::query()->findOrFail($appointment->id);

            if ($appointment->lead_id !== $lead->id || ! $appointment->state->isClosedOut()) {
                throw ValidationException::withMessages([
                    'correctionNote' => __('This appointment has not been marked run or no-show, so there is nothing to correct.'),
                ]);
            }

            if ($note === '') {
                throw ValidationException::withMessages([
                    'correctionNote' => __('Say why this is being corrected.'),
                ]);
            }

            $was = $appointment->state;
            $now = $was === AppointmentState::Run ? AppointmentState::NoShow : AppointmentState::Run;
            $moment = now();
            $from = $lead->status;

            $appointment->forceFill([
                'state' => $now,
                'state_changed_at' => $moment,
                'state_changed_by' => $actor->id,
                'state_changed_by_name' => $actor->name,
                'state_note' => $note,
            ])->save();

            $untouchedSince = $lead->status === CloseOutAppointment::leadStatusFor($was)
                && $lead->appointments()->value('id') === $appointment->id;

            if ($untouchedSince) {
                $lead->forceFill([
                    'status' => CloseOutAppointment::leadStatusFor($now),
                    'status_changed_at' => $moment,
                ])->save();
            }

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::AppointmentCloseOutCorrected, $from, $actor, $lead->assigned_user_id, note: $note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadAppointmentCloseOutCorrected,
                $lead,
                actor: $actor,
                before: ['status' => $from->value, 'appointment' => $was->value],
                after: ['status' => $lead->status->value, 'appointment' => $now->value],
                location: $lead->location,
                reason: $note,
            );

            // The salesperson is told when a manager changes their appointment (decision 126).
            $salesperson = $appointment->salesperson_id === null || $appointment->salesperson_id === $actor->id ? null : User::query()->find($appointment->salesperson_id);

            $salesperson?->notify(new AppointmentChanged($lead->id, AppointmentChanged::CLOSE_OUT_CORRECTED, AppointmentChanged::IN_APP, $appointment));

            return true;
        }, retryWhenBusy: true);
    }
}
