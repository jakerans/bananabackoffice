<?php

namespace App\Actions\Appointments;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\AssignLead;
use App\Actions\Leads\ChangeLeadLifecycle;
use App\Enums\AppointmentChangeType;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Appointment;
use App\Models\AppointmentChange;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\AppointmentSlot;
use App\Support\Appointments\BookingResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class MoveAppointment
{
    public function __construct(
        private ChangeLeadLifecycle $changeLeadLifecycle,
        private AssignLead $assignLead,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Give an upcoming appointment to a different salesperson (rulebook, rule
     * 7): a manager only.
     *
     * It is a handoff. The appointment keeps its time; the Lead is assigned to
     * the new salesperson, who must have Sales Consultant access at the Lead's
     * Location and no other appointment then. Both people are kept in the
     * appointment's history. The new salesperson is told inside the app and by
     * an email that carries no customer details; the earlier one is told
     * inside the app.
     *
     * The Lead's issue is not touched: which salesperson an issue counts for
     * after a move is still open, and the history kept here lets either
     * answer be counted later.
     *
     * This is also how a manager gives an appointment a salesperson again
     * after its own lost access to the Lead (decision 95). All of it is saved
     * together or not at all, and the whole change is tried again if the
     * database reports a deadlock or says it is busy.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(Lead $lead, Appointment $appointment, User $salesperson, ?string $note, User $actor, ?int $expectedVersion = null): BookingResult
    {
        $note = trim((string) $note) === '' ? null : trim((string) $note);

        $this->changeLeadLifecycle->handle($lead, $expectedVersion, function () use ($lead, &$appointment, &$salesperson, $note, $actor): bool {
            Gate::forUser($actor)->authorize('manageAppointment', $lead);

            // Read as it is now; see RescheduleAppointment for why no lock is taken yet.
            $appointment = Appointment::query()->findOrFail($appointment->id);

            if ($appointment->lead_id !== $lead->id || ! $appointment->isUpcomingAt(now())) {
                throw ValidationException::withMessages([
                    'newSalespersonId' => __('This appointment is no longer upcoming, so it cannot be moved.'),
                ]);
            }

            if ($appointment->salesperson_id === $salesperson->id && $lead->isAssignedTo($salesperson)) {
                throw ValidationException::withMessages([
                    'newSalespersonId' => __(':name already has this appointment.', ['name' => $salesperson->name]),
                ]);
            }

            // Both people's rows are locked, lowest ID first, so two moves can never wait on each other.
            $ids = array_values(array_unique(array_filter([$appointment->salesperson_id, $salesperson->id])));
            sort($ids);
            $people = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $salesperson = $people->get($salesperson->id) ?? throw new AuthorizationException;
            $salesperson->setRelation('accessGrants', AccessGrant::query()->where('user_id', $salesperson->id)->sharedLock()->get());
            $earlier = $appointment->salesperson_id === null || $appointment->salesperson_id === $salesperson->id ? null : $people->get($appointment->salesperson_id);

            $location = $lead->location()->firstOrFail();
            $lead->setRelation('location', $location);

            if (! $salesperson->holdsRoleAt(Role::SalesConsultant, $location) || ! $salesperson->canBeAssigned($lead)) {
                throw ValidationException::withMessages([
                    'newSalespersonId' => __(':name does not have Sales Consultant access at :location.', ['name' => $salesperson->name, 'location' => $location->name]),
                ]);
            }

            AppointmentSlot::of($appointment)->ensureFreeFor($salesperson, $appointment, 'newSalespersonId');

            $earlierName = $appointment->salesperson_name;

            (new AppointmentChange)->forceFill([
                'appointment_id' => $appointment->id,
                'lead_id' => $lead->id,
                'type' => AppointmentChangeType::Moved,
                'from_starts_at' => $appointment->starts_at,
                'from_ends_at' => $appointment->ends_at,
                'to_starts_at' => $appointment->starts_at,
                'to_ends_at' => $appointment->ends_at,
                'from_salesperson_id' => $appointment->salesperson_id,
                'from_salesperson_name' => $earlierName,
                'to_salesperson_id' => $salesperson->id,
                'to_salesperson_name' => $salesperson->name,
                'changed_by' => $actor->id,
                'changed_by_name' => $actor->name,
                'note' => $note,
                'created_at' => now(),
            ])->save();

            $appointment->forceFill([
                'salesperson_id' => $salesperson->id,
                'salesperson_name' => $salesperson->name,
            ])->save();

            $this->assignLead->handle($lead, $salesperson, $actor);

            $this->recordAuditEvent->handle(
                AuditAction::LeadAppointmentMoved,
                $lead,
                actor: $actor,
                before: ['salesperson' => $earlierName],
                after: ['salesperson' => $salesperson->name, 'starts_at' => $appointment->starts_at->toIso8601String()],
                location: $location,
                reason: $note,
            );

            if (! $salesperson->is($actor)) {
                $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::MOVED_TO_YOU, AppointmentChanged::IN_APP, $appointment));
            }

            if ($earlier !== null && ! $earlier->is($actor)) {
                $earlier->notify(new AppointmentChanged($lead->id, AppointmentChanged::MOVED_AWAY, AppointmentChanged::IN_APP, $appointment));
            }

            return true;
        }, retryWhenBusy: true);

        return new BookingResult($appointment, $salesperson->is($actor) || $this->email($salesperson, $lead, $appointment));
    }

    /**
     * Send the new salesperson's email. The move is already saved and stays
     * saved if the email cannot be sent; the failure is reported.
     */
    private function email(User $salesperson, Lead $lead, Appointment $appointment): bool
    {
        try {
            $salesperson->notify(new AppointmentChanged($lead->id, AppointmentChanged::MOVED_TO_YOU, AppointmentChanged::EMAIL, $appointment));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
