<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CloseOutAppointment
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Say what happened to an appointment once its time has come: it was run,
     * or the customer was a no-show (docs/slice-7-appointment-results-plan.md,
     * step 7a; decisions 121, 123 and 125). The salesperson whose appointment
     * it is, or a manager, from the appointment's start time.
     *
     * The appointment keeps its row and takes that state, with who said so,
     * when, and their note. It has no result of its own: only a proposal is
     * Sold, Pending or Lost (decision 124).
     *
     * Run: the Lead shows Appointment run and stays with its salesperson.
     * No-show: the Lead is treated as after a cancellation by the customer. It
     * shows Appointment cancelled, stays with its salesperson to be booked
     * again, and is released to the Contact Center when the waiting period
     * runs out. Either way it still counts as issued.
     *
     * The Lead's status changes only if it is still Appointment booked and has
     * no other appointment still to come: an earlier appointment answered
     * after a later one was booked leaves the Lead as it is.
     *
     * A salesperson answers their appointments in order: an earlier one that
     * is over and unanswered comes first. When a manager answers for them, the
     * salesperson is told inside the app (decision 126).
     *
     * All of it is saved together or not at all, and the whole change is tried
     * again if the database reports a deadlock or says it is busy.
     *
     * @param  string|null  $outcome  AppointmentState::Run or AppointmentState::NoShow, as its value.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, ?string $outcome, ?string $note, User $actor, ?int $expectedVersion = null): void
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $outcome, $note, $actor): bool {
            // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
            $appointment = Appointment::query()->findOrFail($appointment->id);

            Gate::forUser($actor)->authorize('closeOutAppointment', [$lead, $appointment]);

            $state = AppointmentState::tryFrom((string) $outcome);

            if ($state === null || ! $state->isClosedOut()) {
                throw ValidationException::withMessages([
                    'closeOutOutcome' => __('Say whether the appointment was run or was a no-show.'),
                ]);
            }

            if ($appointment->stands() && $appointment->starts_at->gt(now())) {
                throw ValidationException::withMessages([
                    'closeOutOutcome' => __('This appointment has not started yet. To call it off, cancel it.'),
                ]);
            }

            if (! $appointment->stands() || ! $lead->isWithSales()) {
                throw ValidationException::withMessages([
                    'closeOutOutcome' => __('This appointment is no longer waiting for an answer.'),
                ]);
            }

            // A salesperson closes their appointments out in order: an earlier
            // one that is over and unanswered comes first (decision 126). A
            // manager answering for them is not held to it.
            if ($appointment->salesperson_id === $actor->id) {
                $earlier = Appointment::query()
                    ->overdueForCloseOutAt(now())
                    ->where('salesperson_id', $actor->id)
                    ->where('starts_at', '<', $appointment->starts_at)
                    ->whereKeyNot($appointment->id)
                    ->whereHas('lead', fn ($leads) => $leads->where('status', LeadStatus::AppointmentBooked))
                    ->orderBy('starts_at')
                    ->first();

                if ($earlier !== null) {
                    throw ValidationException::withMessages([
                        'closeOutOutcome' => __('Close out your earlier appointment first: the one on :when.', [
                            'when' => $earlier->starts_at->copy()->timezone($earlier->timezone)->format('D, M j \a\t g:i A T'),
                        ]),
                    ]);
                }
            }

            $moment = now();
            $from = $lead->status;

            $appointment->forceFill([
                'state' => $state,
                'state_changed_at' => $moment,
                'state_changed_by' => $actor->id,
                'state_changed_by_name' => $actor->name,
                'state_note' => $note,
            ])->save();

            if ($lead->status === LeadStatus::AppointmentBooked && ! $lead->hasUpcomingAppointment()) {
                $lead->forceFill([
                    'status' => self::leadStatusFor($state),
                    'status_changed_at' => $moment,
                ])->save();
            }

            $this->recordLeadStatusChange->handle(
                $lead,
                $state === AppointmentState::Run ? LeadStatusEvent::AppointmentRun : LeadStatusEvent::AppointmentNoShow,
                $from,
                $actor,
                $lead->assigned_user_id,
                note: $note,
            );

            $this->recordAuditEvent->handle(
                $state === AppointmentState::Run ? AuditAction::LeadAppointmentRun : AuditAction::LeadAppointmentNoShow,
                $lead,
                actor: $actor,
                before: ['status' => $from->value, 'starts_at' => $appointment->starts_at->toIso8601String()],
                after: ['status' => $lead->status->value, 'appointment' => $state->value, 'salesperson' => $appointment->salesperson_name],
                location: $lead->location,
                reason: $note,
            );

            // The salesperson is told when someone else answers for them (decision 126).
            $salesperson = $appointment->salesperson_id === null || $appointment->salesperson_id === $actor->id ? null : User::query()->find($appointment->salesperson_id);

            $salesperson?->notify(new AppointmentChanged(
                $lead->id,
                $state === AppointmentState::Run ? AppointmentChanged::MARKED_RUN : AppointmentChanged::MARKED_NO_SHOW,
                AppointmentChanged::IN_APP,
                $appointment,
            ));

            return true;
        }, retryWhenBusy: true);
    }

    /**
     * What a Lead shows once its appointment has that answer.
     */
    public static function leadStatusFor(AppointmentState $state): LeadStatus
    {
        return $state === AppointmentState::Run ? LeadStatus::AppointmentRun : LeadStatus::AppointmentCancelled;
    }
}
