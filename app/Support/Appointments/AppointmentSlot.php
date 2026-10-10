<?php

namespace App\Support\Appointments;

use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The time an appointment is asked to take, checked the same way whether it is
 * being booked, rescheduled or moved to another salesperson.
 */
final readonly class AppointmentSlot
{
    private function __construct(
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public int $minutes,
        public string $timezone,
    ) {}

    /**
     * The start and end of the appointment, from what was typed (decision 93):
     * a real day and time in the Location's own time, on a quarter hour, in
     * the future, for a whole number of quarter hours between the limits. Both
     * are kept in UTC, like every other time here.
     *
     * @param  string  $date  The day, as Y-m-d, in the Location's time.
     * @param  string  $time  The time, as H:i (24-hour), in the Location's time.
     *
     * @throws ValidationException
     */
    public static function fromInput(string $date, string $time, int $minutes, string $timezone): self
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', trim($date), $timezone) ?: null;

        if ($day === null || $day->format('Y-m-d') !== trim($date)) {
            throw ValidationException::withMessages([
                'appointmentDate' => __('Choose the day of the appointment.'),
            ]);
        }

        $startsAt = CarbonImmutable::createFromFormat('!Y-m-d H:i', trim($date).' '.trim($time), $timezone) ?: null;

        if ($startsAt === null || $startsAt->format('H:i') !== trim($time)) {
            throw ValidationException::withMessages([
                'appointmentTime' => __('Choose the time of the appointment.'),
            ]);
        }

        if ($startsAt->minute % Appointment::STEP_MINUTES !== 0) {
            throw ValidationException::withMessages([
                'appointmentTime' => __('Choose a time on the quarter hour.'),
            ]);
        }

        if ($minutes < Appointment::MINIMUM_MINUTES || $minutes > Appointment::MAXIMUM_MINUTES || $minutes % Appointment::STEP_MINUTES !== 0) {
            throw ValidationException::withMessages([
                'appointmentMinutes' => __('Choose a length between 30 minutes and 4 hours, in steps of 15 minutes.'),
            ]);
        }

        if ($startsAt->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages([
                'appointmentTime' => __('The appointment must be in the future.'),
            ]);
        }

        return new self($startsAt->utc(), $startsAt->addMinutes($minutes)->utc(), $minutes, $timezone);
    }

    /**
     * The same time as an appointment already has, for giving it to another
     * salesperson without changing when it is.
     */
    public static function of(Appointment $appointment): self
    {
        return new self(
            CarbonImmutable::instance($appointment->starts_at)->utc(),
            CarbonImmutable::instance($appointment->ends_at)->utc(),
            $appointment->duration_minutes,
            $appointment->timezone,
        );
    }

    /**
     * Refuse the time if the salesperson already has a standing appointment
     * that shares any of it (rulebook, rule 5). The caller must hold a lock on
     * the salesperson's row, so their appointments cannot change meanwhile.
     *
     * This is a locking read: on MySQL a plain read could come from before
     * another change that was saved while this one waited for the lock.
     *
     * @param  Appointment|null  $except  The appointment being changed, which never clashes with itself.
     *
     * @throws ValidationException
     */
    public function ensureFreeFor(User $salesperson, ?Appointment $except = null, string $field = 'appointmentTime'): void
    {
        $clash = Appointment::query()
            ->overlapping($salesperson->id, $this->startsAt, $this->endsAt)
            ->when($except !== null, fn ($appointments) => $appointments->whereKeyNot($except->id))
            ->orderBy('starts_at')
            ->sharedLock()
            ->first();

        if ($clash === null) {
            return;
        }

        $taken = [
            'name' => $salesperson->name,
            'from' => $clash->starts_at->copy()->timezone($this->timezone)->format('g:i A'),
            'to' => $clash->ends_at->copy()->timezone($this->timezone)->format('g:i A T'),
        ];

        throw ValidationException::withMessages([
            $field => $field === 'appointmentTime'
                ? __(':name already has an appointment from :from to :to then. Choose another time.', $taken)
                : __(':name already has an appointment from :from to :to then. Choose someone else, or reschedule this appointment first.', $taken),
        ]);
    }
}
