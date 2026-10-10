<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Actions\Leads\RecordLeadStatusChange;
use App\Actions\Queue\EndLeadCadence;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CadenceEndReason;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Support\Appointments\BookingResult;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class BookAppointment
{
    /** What a Lead still needs before it can be booked, in the words a person reads (rulebook, rule 1). */
    public const array REQUIREMENTS = [
        'qualified' => 'to be qualified',
        'location' => 'to be at a location',
        'customer' => 'a customer',
        'property' => 'a property',
    ];

    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private AssignLead $assignLead,
        private RecordLeadStatusChange $recordLeadStatusChange,
        private RecordAuditEvent $recordAuditEvent,
        private EndLeadCadence $endLeadCadence,
    ) {}

    /**
     * Book an appointment for a Qualified Lead with a salesperson. Saving it
     * is the moment the Lead is issued to Sales (rulebook, rule 3).
     *
     * In one transaction, all of it or none: the appointment is saved; the
     * Lead is assigned to the salesperson (taking it from whoever had it,
     * decision 94); it becomes Appointment Booked; it leaves the call queue
     * and any hold on it ends; the issue is recorded for good; the audit event
     * that the customer's Activity shows is written; and the salesperson gets
     * a notice inside the app. Their email is sent once all that is saved, and
     * a failed email does not undo the booking.
     *
     * The date and time are in the Lead's Location's own time (decision 92).
     * A salesperson cannot be given a time that overlaps another standing
     * appointment of theirs (rule 5): their row is locked while that is
     * checked, so two bookings made at the same moment cannot both be saved.
     *
     * Who may book is checked here again, on the Lead as it is now. If the
     * database reports a deadlock or says it is busy, the whole booking is
     * tried again (decision 87).
     *
     * @param  string  $date  The day, as Y-m-d, in the Location's time.
     * @param  string  $time  The time, as H:i (24-hour), in the Location's time.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, User $salesperson, string $date, string $time, int $minutes, User $actor, ?int $expectedVersion = null): BookingResult
    {
        $booked = null;

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, $salesperson, $date, $time, $minutes, $actor, &$booked): bool {
            $booked = $this->book($lead, $salesperson, $date, $time, $minutes, $actor);

            return true;
        }, retryWhenBusy: true);

        /** @var array{appointment: Appointment, salesperson: User} $booked */
        return new BookingResult($booked['appointment'], $this->email($booked['salesperson'], $booked['appointment']));
    }

    /**
     * @return array{appointment: Appointment, salesperson: User}
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    private function book(Lead $lead, User $salesperson, string $date, string $time, int $minutes, User $actor): array
    {
        $gaps = $lead->bookingGaps();

        if ($gaps !== []) {
            throw ValidationException::withMessages([
                'booking' => __('An appointment cannot be booked for this lead yet. It still needs: :missing.', [
                    'missing' => implode('; ', array_map(fn (string $gap): string => self::REQUIREMENTS[$gap], $gaps)),
                ]),
            ]);
        }

        $location = $lead->location()->firstOrFail();
        $lead->setRelation('location', $location);

        Gate::forUser($actor)->authorize('bookAppointment', $lead);

        // The salesperson's row is locked from here to the end, so their other
        // appointments cannot change while the times are compared.
        $salesperson = User::query()->lockForUpdate()->findOrFail($salesperson->id);

        if (! $actor->hasPermissionAt(Permission::BookAppointments, $location) && ! $salesperson->is($actor)) {
            throw ValidationException::withMessages([
                'salespersonId' => __('You can book your own leads for yourself only.'),
            ]);
        }

        if (! $salesperson->holdsRoleAt(Role::SalesConsultant, $location) || ! $salesperson->canBeAssigned($lead)) {
            throw ValidationException::withMessages([
                'salespersonId' => __(':name does not have Sales Consultant access at :location.', ['name' => $salesperson->name, 'location' => $location->name]),
            ]);
        }

        $timezone = CallingHours::timezoneFor($location);
        [$startsAt, $endsAt] = $this->span($date, $time, $minutes, $timezone);

        $clash = Appointment::query()->overlapping($salesperson->id, $startsAt, $endsAt)->orderBy('starts_at')->first();

        if ($clash !== null) {
            throw ValidationException::withMessages([
                'appointmentTime' => __(':name already has an appointment from :from to :to then. Choose another time.', [
                    'name' => $salesperson->name,
                    'from' => $clash->starts_at->copy()->timezone($timezone)->format('g:i A'),
                    'to' => $clash->ends_at->copy()->timezone($timezone)->format('g:i A T'),
                ]),
            ]);
        }

        $cadence = LeadCadence::query()->where('lead_id', $lead->id)->lockForUpdate()->first();
        $previousAssignee = $lead->assignee;

        $appointment = new Appointment;

        $appointment->forceFill([
            'lead_id' => $lead->id,
            'location_id' => $location->id,
            'account_id' => $lead->account_id,
            'property_id' => $lead->property_id,
            'salesperson_id' => $salesperson->id,
            'salesperson_name' => $salesperson->name,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'duration_minutes' => $minutes,
            'timezone' => $timezone,
            'state' => AppointmentState::Booked,
            'booked_by' => $actor->id,
            'booked_by_name' => $actor->name,
            'previous_status' => $lead->status,
            'previous_assignee_id' => $lead->assigned_user_id,
            'previous_in_queue' => $cadence !== null && $cadence->isInQueue(),
        ])->save();

        // Rule 3, in order: assigned to the salesperson, Appointment Booked, out of the queue, issued.
        $this->assignLead->handle($lead, $salesperson, $actor);

        $from = $lead->status;
        $moment = now();

        $lead->forceFill([
            'status' => LeadStatus::AppointmentBooked,
            'status_changed_at' => $moment,
            'first_issued_at' => $lead->first_issued_at ?? $moment,
            'last_issued_at' => $moment,
        ])->save();

        $this->recordLeadStatusChange->handle($lead, LeadStatusEvent::AppointmentBooked, $from, $actor, $salesperson->id);

        if ($cadence !== null) {
            $this->endLeadCadence->handle($cadence, CadenceEndReason::AppointmentBooked, $lead, $actor);
        }

        $issue = new LeadIssue;

        $issue->forceFill([
            'lead_id' => $lead->id,
            'appointment_id' => $appointment->id,
            'location_id' => $location->id,
            'salesperson_id' => $salesperson->id,
            'salesperson_name' => $salesperson->name,
            'issued_by' => $actor->id,
            'issued_by_name' => $actor->name,
            'issued_at' => $moment,
            'created_at' => $moment,
        ])->save();

        $this->recordAuditEvent->handle(
            AuditAction::LeadAppointmentBooked,
            $lead,
            actor: $actor,
            before: ['status' => $from->value, 'assignee' => $previousAssignee?->name],
            after: [
                'status' => LeadStatus::AppointmentBooked->value,
                'salesperson' => $salesperson->name,
                'starts_at' => $startsAt->toIso8601String(),
                'duration_minutes' => $minutes,
                'timezone' => $timezone,
            ],
            location: $location,
        );

        $salesperson->notify(new AppointmentBooked($appointment, AppointmentBooked::IN_APP));

        return ['appointment' => $appointment, 'salesperson' => $salesperson];
    }

    /**
     * The start and end of the appointment, from what was typed (decision 93):
     * a real day and time in the Location's own time, on a quarter hour, in
     * the future, for a whole number of quarter hours between the limits. Both
     * are returned in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     *
     * @throws ValidationException
     */
    private function span(string $date, string $time, int $minutes, string $timezone): array
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

        // Stored and compared as instants in UTC, like every other time here.
        return [$startsAt->utc(), $startsAt->addMinutes($minutes)->utc()];
    }

    /**
     * Send the salesperson's email. The booking is already saved and stays
     * saved if the email cannot be sent; the failure is reported.
     */
    private function email(User $salesperson, Appointment $appointment): bool
    {
        try {
            $salesperson->notify(new AppointmentBooked($appointment, AppointmentBooked::EMAIL));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
