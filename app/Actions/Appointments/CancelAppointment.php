<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CancelAppointment
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private DisqualifyLead $disqualifyLead,
    ) {}

    /**
     * Record that an upcoming appointment was cancelled (rulebook, rule 8).
     * Anyone who may book the Lead may record it (decision 107).
     *
     * The appointment is kept as a cancelled record with who cancelled (the
     * customer, the salesperson or the company: the same record and rules,
     * decision 104), the reason from the managed list, and a note where the
     * reason needs one. The Lead stays with the salesperson, shows Appointment
     * cancelled (decision 106), still counts as issued, and can be booked
     * again. When someone else recorded it, the salesperson is told inside the
     * app.
     *
     * A reason that asks whether the Lead is disqualified (the list's "Other",
     * decision 108) must be answered. When the answer is yes the Lead is
     * disqualified in the same step, with a disqualification reason and under
     * the same rules as disqualifying it by hand, including the question of
     * whether it still counts as issued (decision 110).
     *
     * An appointment whose time has passed cannot be cancelled (decision
     * 102). All of it is saved together or not at all, and the whole change is
     * tried again if the database reports a deadlock or says it is busy.
     *
     * @param  string|null  $cancelledBy  A CancellationParty value.
     * @param  bool|null  $disqualifies  The answer to "is this lead disqualified?", for a reason that asks.
     * @param  LeadDisqualificationReason|null  $disqualifyFor  Why it is disqualified, when it is.
     * @param  bool  $neverShouldHaveBeenBooked  When disqualifying: it should never have been booked, so it stops counting as issued.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(
        Lead $lead,
        Appointment $appointment,
        ?string $cancelledBy,
        ?AppointmentCancellationReason $reason,
        ?string $note,
        User $actor,
        ?int $expectedVersion = null,
        ?bool $disqualifies = null,
        ?LeadDisqualificationReason $disqualifyFor = null,
        bool $neverShouldHaveBeenBooked = false,
    ): void {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $appointment, $cancelledBy, $reason, $note, $actor, $disqualifies, $disqualifyFor, $neverShouldHaveBeenBooked): bool {
            Gate::forUser($actor)->authorize('cancelAppointment', $lead);

            // Read as it is now; the Lead's row is already claimed, so it cannot change underneath.
            $appointment = Appointment::query()->findOrFail($appointment->id);

            if ($appointment->lead_id !== $lead->id || ! $appointment->isUpcomingAt(now())) {
                throw ValidationException::withMessages([
                    'cancellationReasonId' => __('This appointment is no longer upcoming, so it cannot be cancelled.'),
                ]);
            }

            $party = CancellationParty::tryFrom((string) $cancelledBy);

            if ($party === null) {
                throw ValidationException::withMessages([
                    'cancelledBy' => __('Say who cancelled the appointment.'),
                ]);
            }

            $reason?->refresh();

            if ($reason === null) {
                throw ValidationException::withMessages([
                    'cancellationReasonId' => __('Choose why the appointment was cancelled.'),
                ]);
            }

            if (! $reason->is_active) {
                throw ValidationException::withMessages([
                    'cancellationReasonId' => __(':reason is no longer offered. Choose another.', ['reason' => $reason->name]),
                ]);
            }

            if ($reason->requires_note && $note === null) {
                throw ValidationException::withMessages([
                    'cancellationNote' => __('Explain why, since ":reason" says little by itself.', ['reason' => $reason->name]),
                ]);
            }

            $disqualifies = $reason->asks_if_disqualified ? $disqualifies : false;

            if ($disqualifies === null) {
                throw ValidationException::withMessages([
                    'cancellationDisqualifies' => __('Say whether this lead is disqualified.'),
                ]);
            }

            if ($disqualifies && $disqualifyFor === null) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __('Choose why this lead is disqualified.'),
                ]);
            }

            if ($disqualifies && $disqualifyFor->isDuplicate()) {
                throw ValidationException::withMessages([
                    'disqualificationReasonId' => __('A duplicate needs its original named. Cancel without disqualifying, then disqualify the lead from its own button.'),
                ]);
            }

            if ($disqualifies && $disqualifyFor->needsNote() && $note === null) {
                throw ValidationException::withMessages([
                    'cancellationNote' => __('Explain why, since ":reason" says little by itself.', ['reason' => $disqualifyFor->name]),
                ]);
            }

            if ($disqualifies && ! Gate::forUser($actor)->allows('changeStatus', $lead)) {
                throw ValidationException::withMessages([
                    'cancellationDisqualifies' => __('You cannot disqualify this lead. Cancel the appointment without disqualifying it, and ask a manager.'),
                ]);
            }

            $moment = now();
            $from = $lead->status;
            $salesperson = $appointment->salesperson_id === null ? null : User::query()->find($appointment->salesperson_id);

            $appointment->forceFill([
                'state' => AppointmentState::Cancelled,
                'cancelled_by_party' => $party,
                'cancellation_reason_id' => $reason->id,
                'cancellation_reason_name' => $reason->name,
                'state_changed_at' => $moment,
                'state_changed_by' => $actor->id,
                'state_changed_by_name' => $actor->name,
                'state_note' => $note,
            ])->save();

            $lead->forceFill([
                'status' => LeadStatus::AppointmentCancelled,
                'status_changed_at' => $moment,
            ])->save();

            $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::AppointmentCancelled, $from, $actor, $lead->assigned_user_id, note: $note);

            $this->recordAuditEvent->handle(
                AuditAction::LeadAppointmentCancelled,
                $lead,
                actor: $actor,
                before: ['status' => $from->value, 'starts_at' => $appointment->starts_at->toIso8601String(), 'salesperson' => $appointment->salesperson_name],
                after: [
                    'status' => LeadStatus::AppointmentCancelled->value,
                    'cancelled_by' => $party->value,
                    'reason' => $reason->name,
                    'disqualified' => $disqualifies,
                ],
                location: $lead->location,
                reason: $note,
            );

            if ($salesperson !== null && ! $salesperson->is($actor)) {
                $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::CANCELLED, AppointmentChanged::IN_APP, $appointment));
            }

            if ($disqualifies) {
                $this->disqualifyLead->apply($lead, $disqualifyFor, $note, $actor, neverShouldHaveBeenBooked: $neverShouldHaveBeenBooked);
            }

            return true;
        }, retryWhenBusy: true);
    }
}
