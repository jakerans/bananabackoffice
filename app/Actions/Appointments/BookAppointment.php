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
use App\Models\AccessGrant;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Support\Appointments\AppointmentSlot;
use App\Support\Appointments\BookingResult;
use App\Support\Queue\CallingHours;
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
     * A Lead that is still with Sales can be booked again: after a
     * cancellation, or after an appointment whose time has passed (decisions
     * 99 and 113). That is not a new issue. The Lead keeps the one it has,
     * nothing is added to Leads Issued, and the new appointment points at the
     * one it follows. With the same salesperson anyone who may book the Lead
     * can do it; with a different one it takes a manager (decision 109).
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

        // Still with Sales: this booking follows an earlier appointment and is not a new issue.
        $follows = $lead->isWithSales() ? $lead->appointments()->first() : null;

        if ($lead->isWithSales() && $lead->hasUpcomingAppointment()) {
            throw ValidationException::withMessages([
                'booking' => __('This lead already has an upcoming appointment. Reschedule that one instead.'),
            ]);
        }

        // The salesperson's row is locked from here to the end, so their other
        // appointments cannot change while the times are compared.
        $salesperson = User::query()->lockForUpdate()->findOrFail($salesperson->id);
        $salesperson->setRelation('accessGrants', AccessGrant::query()->where('user_id', $salesperson->id)->sharedLock()->get());

        if (! $actor->hasPermissionAt(Permission::BookAppointments, $location) && ! $salesperson->is($actor)) {
            throw ValidationException::withMessages([
                'salespersonId' => __('You can book your own leads for yourself only.'),
            ]);
        }

        if ($lead->isWithSales() && $lead->assigned_user_id !== $salesperson->id && ! Gate::forUser($actor)->allows('manageAppointment', $lead)) {
            throw ValidationException::withMessages([
                'salespersonId' => $lead->assignee === null
                    ? __('This lead is with Sales and has no salesperson now. A manager chooses the new one.')
                    : __('This lead is with :name. Book it with them, or ask a manager to give it to someone else.', ['name' => $lead->assignee->name]),
            ]);
        }

        if (! $salesperson->holdsRoleAt(Role::SalesConsultant, $location) || ! $salesperson->canBeAssigned($lead)) {
            throw ValidationException::withMessages([
                'salespersonId' => __(':name does not have Sales Consultant access at :location.', ['name' => $salesperson->name, 'location' => $location->name]),
            ]);
        }

        $timezone = CallingHours::timezoneFor($location);
        $slot = AppointmentSlot::fromInput($date, $time, $minutes, $timezone);
        $slot->ensureFreeFor($salesperson);

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
            'starts_at' => $slot->startsAt,
            'ends_at' => $slot->endsAt,
            'duration_minutes' => $minutes,
            'timezone' => $timezone,
            'state' => AppointmentState::Booked,
            'booked_by' => $actor->id,
            'booked_by_name' => $actor->name,
            'previous_status' => $lead->status,
            'previous_assignee_id' => $lead->assigned_user_id,
            'previous_in_queue' => $cadence !== null && $cadence->isInQueue(),
            'follows_appointment_id' => $follows?->id,
        ])->save();

        // Rule 3, in order: assigned to the salesperson, Appointment Booked, out of the queue, issued.
        $this->assignLead->handle($lead, $salesperson, $actor);

        $from = $lead->status;
        $moment = now();
        $issues = $follows === null;

        $lead->forceFill([
            'status' => LeadStatus::AppointmentBooked,
            'status_changed_at' => $moment,
            'returned_from_sales_at' => null,
            'released_from_user_id' => null,
            'released_from_sales_at' => null,
            ...($issues ? ['first_issued_at' => $lead->first_issued_at ?? $moment, 'last_issued_at' => $moment] : []),
        ])->save();

        $this->recordLeadStatusChange->handle($lead, $issues ? LeadStatusEvent::AppointmentBooked : LeadStatusEvent::AppointmentRebooked, $from, $actor, $salesperson->id);

        if ($cadence !== null) {
            $this->endLeadCadence->handle($cadence, CadenceEndReason::AppointmentBooked, $lead, $actor);
        }

        if ($issues) {
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
        }

        $this->recordAuditEvent->handle(
            $issues ? AuditAction::LeadAppointmentBooked : AuditAction::LeadAppointmentRebooked,
            $lead,
            actor: $actor,
            before: ['status' => $from->value, $issues ? 'assignee' : 'salesperson' => $previousAssignee?->name],
            after: [
                'status' => LeadStatus::AppointmentBooked->value,
                'salesperson' => $salesperson->name,
                'starts_at' => $slot->startsAt->toIso8601String(),
                'duration_minutes' => $minutes,
                'timezone' => $timezone,
            ],
            location: $location,
        );

        $salesperson->notify(new AppointmentBooked($appointment, AppointmentBooked::IN_APP));

        return ['appointment' => $appointment, 'salesperson' => $salesperson];
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
