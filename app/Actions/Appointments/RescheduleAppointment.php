<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentChangeType;
use App\Enums\AuditAction;
use App\Models\Appointment;
use App\Models\AppointmentChange;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\AppointmentSlot;
use App\Support\Appointments\BookingResult;
use App\Support\Queue\CallingHours;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class RescheduleAppointment
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Give an upcoming appointment a new time (rulebook, rule 6): the
     * salesperson whose appointment it is, or a manager.
     *
     * The salesperson stays the same, the Lead is still issued and still
     * counts once, and the time it had is kept in the appointment's history.
     * The new time follows the rules a booking does (decision 93) and cannot
     * overlap another standing appointment of the salesperson. When someone
     * else made the change, the salesperson is told inside the app and by an
     * email that carries no customer details.
     *
     * An appointment whose time has passed cannot be rescheduled (decision
     * 102). All of it is saved together or not at all, and the whole change is
     * tried again if the database reports a deadlock or says it is busy.
     *
     * @param  string  $date  The day, as Y-m-d, in the Location's time.
     * @param  string  $time  The time, as H:i (24-hour), in the Location's time.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, string $date, string $time, int $minutes, User $actor, ?int $expectedVersion = null): BookingResult
    {
        $toEmail = null;

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, &$appointment, $date, $time, $minutes, $actor, &$toEmail): bool {
            // Read as it is now. The Lead's row is already claimed, and every
            // change to its appointments claims that row first, so this cannot
            // change underneath. Its own row is written only after the
            // salesperson's is locked: the order a booking takes them in.
            $appointment = Appointment::query()->findOrFail($appointment->id);

            Gate::forUser($actor)->authorize('rescheduleAppointment', [$lead, $appointment]);

            if ($appointment->lead_id !== $lead->id || ! $appointment->isUpcomingAt(now())) {
                throw ValidationException::withMessages([
                    'appointmentTime' => __('This appointment is no longer upcoming, so its time cannot be changed.'),
                ]);
            }

            // The salesperson's row is locked while the times are compared, as a booking does.
            $salesperson = $appointment->salesperson_id === null ? null : User::query()->lockForUpdate()->find($appointment->salesperson_id);

            $slot = AppointmentSlot::fromInput($date, $time, $minutes, CallingHours::timezoneFor($lead->location));

            if ($slot->startsAt->equalTo($appointment->starts_at) && $slot->endsAt->equalTo($appointment->ends_at)) {
                throw ValidationException::withMessages([
                    'appointmentTime' => __('That is the time this appointment already has.'),
                ]);
            }

            if ($salesperson !== null) {
                $slot->ensureFreeFor($salesperson, $appointment);
            }

            $before = ['starts_at' => $appointment->starts_at->toIso8601String(), 'duration_minutes' => $appointment->duration_minutes];

            (new AppointmentChange)->forceFill([
                'appointment_id' => $appointment->id,
                'lead_id' => $lead->id,
                'type' => AppointmentChangeType::Rescheduled,
                'from_starts_at' => $appointment->starts_at,
                'from_ends_at' => $appointment->ends_at,
                'to_starts_at' => $slot->startsAt,
                'to_ends_at' => $slot->endsAt,
                'from_salesperson_id' => $appointment->salesperson_id,
                'from_salesperson_name' => $appointment->salesperson_name,
                'to_salesperson_id' => $appointment->salesperson_id,
                'to_salesperson_name' => $appointment->salesperson_name,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'created_at' => now(),
            ])->save();

            $appointment->forceFill([
                'starts_at' => $slot->startsAt,
                'ends_at' => $slot->endsAt,
                'duration_minutes' => $slot->minutes,
                'timezone' => $slot->timezone,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadAppointmentRescheduled,
                $lead,
                actor: $actor,
                before: $before,
                after: [
                    'starts_at' => $slot->startsAt->toIso8601String(),
                    'duration_minutes' => $slot->minutes,
                    'timezone' => $slot->timezone,
                    'salesperson' => $appointment->salesperson_name,
                ],
                location: $lead->location,
            );

            $toEmail = null;

            if ($salesperson !== null && ! $salesperson->is($actor)) {
                $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::RESCHEDULED, AppointmentChanged::IN_APP, $appointment));
                $toEmail = $salesperson;
            }

            return true;
        }, retryWhenBusy: true);

        return new BookingResult($appointment, $toEmail === null || $this->email($toEmail, $lead, $appointment));
    }

    /**
     * Send the salesperson's email. The change is already saved and stays
     * saved if the email cannot be sent; the failure is reported.
     */
    private function email(User $salesperson, Lead $lead, Appointment $appointment): bool
    {
        try {
            $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::RESCHEDULED, AppointmentChanged::EMAIL, $appointment));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
