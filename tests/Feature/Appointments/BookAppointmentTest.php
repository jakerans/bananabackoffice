<?php

use App\Actions\Appointments\BookAppointment;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;

/*
 * Booking an appointment issues the Lead to Sales
 * (docs/slice-6-appointments-plan.md, step 6a; rulebook rules 1 to 5;
 * decisions 90 to 95).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern. Unless a test says
 * otherwise an appointment is for the next day at 3:00 pm Eastern, 90 minutes.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
});

/**
 * The Lead after something about it was changed directly, read again.
 *
 * @param  array<string, mixed>  $changes
 */
function changedLead(Lead $lead, array $changes): Lead
{
    $lead->forceFill($changes)->save();

    return $lead->fresh();
}

/*
|--------------------------------------------------------------------------
| What a booking does (rule 3)
|--------------------------------------------------------------------------
*/

test('saving an appointment issues the lead: assigned, appointment booked, issue kept, all recorded', function () {
    $lead = bookableLead($this->lakeview);

    $result = bookAppointment($lead, $this->sam, $this->manager);
    $appointment = $result->appointment;

    expect($result->emailSent)->toBeTrue();

    // The appointment: tomorrow 3:00 pm Eastern for 90 minutes, stored in UTC with its timezone.
    expect($appointment->state)->toBe(AppointmentState::Booked);
    expect($appointment->starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect($appointment->ends_at->toDateTimeString())->toBe('2026-10-08 20:30:00');
    expect($appointment->duration_minutes)->toBe(90);
    expect($appointment->timezone)->toBe('America/New_York');
    expect($appointment->salesperson_id)->toBe($this->sam->id);
    expect($appointment->salesperson_name)->toBe('Sam Sales');
    expect($appointment->booked_by)->toBe($this->manager->id);
    expect([$appointment->location_id, $appointment->account_id, $appointment->property_id])
        ->toBe([$this->lakeview->id, $lead->account_id, $lead->property_id]);

    // The Lead: with the salesperson, Appointment Booked, and the issue time kept.
    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->first_issued_at->toDateTimeString())->toBe('2026-10-07 14:00:00');
    expect($lead->last_issued_at->toDateTimeString())->toBe('2026-10-07 14:00:00');

    $issue = LeadIssue::query()->sole();

    expect($issue->lead_id)->toBe($lead->id);
    expect($issue->appointment_id)->toBe($appointment->id);
    expect($issue->salesperson_id)->toBe($this->sam->id);
    expect($issue->issued_by)->toBe($this->manager->id);
    expect($issue->counts())->toBeTrue();

    // Its history: the status change and the audit event, which is also the customer's Activity.
    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->sole();

    expect($change->event)->toBe(LeadStatusEvent::AppointmentBooked);
    expect($change->from_status)->toBe(LeadStatus::Qualified);
    expect($change->to_status)->toBe(LeadStatus::AppointmentBooked);
    expect($change->changed_by)->toBe($this->manager->id);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentBooked)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->subject_id)->toBe($lead->id);
    expect($event->location_id)->toBe($this->lakeview->id);
    expect($event->values_after)->toMatchArray(['status' => 'appointment_booked', 'salesperson' => 'Sam Sales', 'duration_minutes' => 90, 'timezone' => 'America/New_York']);
    expect(CustomerActivity::actions())->toContain(AuditAction::LeadAppointmentBooked);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentBooked))->toBe('Appointment booked');

    // The assignment is in the assignment history like any other.
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->whereNull('ended_at')->sole()->user_id)->toBe($this->sam->id);
});

test('booking takes the lead from whoever had it and keeps that in the history', function () {
    $other = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Other Sales']);
    $lead = bookableLead($this->lakeview, $other);

    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;

    expect($lead->fresh()->assigned_user_id)->toBe($this->sam->id);
    expect($appointment->previous_assignee_id)->toBe($other->id);

    $ended = LeadAssignment::query()->where('lead_id', $lead->id)->where('user_id', $other->id)->sole();

    expect($ended->ended_at)->not->toBeNull();
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentBooked)->sole()->values_before)->toMatchArray(['assignee' => 'Other Sales']);
});

test('booking takes the lead out of the call queue and ends a hold on it', function () {
    $setter = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create(['name' => 'Avery Agent']);
    $lead = bookableLead($this->lakeview);
    $cadence = enterQueue($lead);

    expect(nextLead($setter)->lead->id)->toBe($lead->id);

    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;
    $cadence = $cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::AppointmentBooked);
    expect($cadence->held_by_user_id)->toBeNull();
    expect($cadence->due_at)->toBeNull();
    expect($appointment->previous_in_queue)->toBeTrue();
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_ended:appointment_booked', 'ended:appointment_booked']);

    // It is not handed out again.
    expect(nextLead($setter)->lead)->toBeNull();
});

test('a lead that was not in the queue is booked without touching the queue', function () {
    $lead = bookableLead($this->lakeview);

    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;

    expect($appointment->previous_in_queue)->toBeFalse();
    expect(LeadCadence::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| When a lead can be booked (rule 1)
|--------------------------------------------------------------------------
*/

test('a lead that is not ready cannot be booked, and the person is told what is missing', function (Closure $lead, string $missing) {
    $lead = $lead($this);

    expect(fn () => bookAppointment($lead, $this->sam, User::factory()->withRoleEverywhere(Role::Owner)->create()))
        ->toThrow(ValidationException::class, "An appointment cannot be booked for this lead yet. It still needs: {$missing}.");

    expect(Appointment::query()->count())->toBe(0);
    expect(LeadIssue::query()->count())->toBe(0);
})->with([
    'working, not qualified' => [fn ($test) => changedLead(bookableLead($test->lakeview), ['status' => LeadStatus::Working]), 'to be qualified'],
    'no customer' => [fn ($test) => Lead::factory()->at($test->lakeview)->qualified()->create(), 'a customer; a property'],
    'a customer and no property' => [fn ($test) => changedLead(bookableLead($test->lakeview), ['property_id' => null]), 'a property'],
]);

test('a lead that already has an appointment cannot be booked again', function () {
    $lead = bookableLead($this->lakeview);

    bookAppointment($lead, $this->sam, $this->manager);

    expect(fn () => bookAppointment($lead->fresh(), $this->sam, $this->manager, time: '18:00'))
        ->toThrow(ValidationException::class, 'This lead already has an upcoming appointment. Reschedule that one instead.');

    expect(Appointment::query()->count())->toBe(1);
    expect(LeadIssue::query()->count())->toBe(1);
});

test('a lead with no location cannot be booked by anyone', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = changedLead(bookableLead($this->lakeview), ['location_id' => null, 'routed_at' => null]);

    expect($owner->can('bookAppointment', $lead))->toBeFalse();
    expect(fn () => bookAppointment($lead, $this->sam, $owner))->toThrow(ValidationException::class, 'to be at a location');
});

/*
|--------------------------------------------------------------------------
| Who can book, and with whom (rule 2)
|--------------------------------------------------------------------------
*/

test('who may book an appointment for a lead', function (Closure $person, bool $allowed) {
    $lead = bookableLead($this->lakeview, $this->sam);
    $user = $person($this);

    expect($user->can('bookAppointment', $lead))->toBe($allowed);

    if ($allowed) {
        bookAppointment($lead, $this->sam, $user);

        expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    } else {
        expect(fn () => bookAppointment($lead, $this->sam, $user))->toThrow(AuthorizationException::class);

        expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
        expect(Appointment::query()->count())->toBe(0);
    }
})->with([
    'an owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'the location manager there' => [fn ($test) => $test->manager, true],
    'a call center agent there' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->lakeview)->create(), true],
    'the sales consultant it is assigned to' => [fn ($test) => $test->sam, true],
    'another sales consultant there' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(), false],
    'a location manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, $test->harbor)->create(), false],
    'a call center agent at another location' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->harbor)->create(), false],
    'finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), false],
    'a production manager' => [fn ($test) => User::factory()->withRoleEverywhere(Role::ProductionManager)->create(), false],
    'a deactivated manager' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, $test->lakeview)->inactive()->create(), false],
]);

test('a sales consultant books their own lead for themselves only', function () {
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Casey Colleague']);
    $lead = bookableLead($this->lakeview, $this->sam);

    expect(fn () => bookAppointment($lead, $colleague, $this->sam))
        ->toThrow(ValidationException::class, 'You can book your own leads for yourself only.');

    expect($lead->fresh()->assigned_user_id)->toBe($this->sam->id);
    expect(Appointment::query()->count())->toBe(0);
});

test('the salesperson must have sales consultant access where the lead is', function (Closure $person) {
    $lead = bookableLead($this->lakeview);
    $person = $person($this);

    expect(fn () => bookAppointment($lead, $person, $this->manager))
        ->toThrow(ValidationException::class, "{$person->name} does not have Sales Consultant access at Lakeview.");

    expect(Appointment::query()->count())->toBe(0);
})->with([
    'a sales consultant at another location' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->harbor)->create(['name' => 'Harbor Sales'])],
    'a manager there, who is not a salesperson' => [fn ($test) => $test->manager],
    'a deactivated sales consultant' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->inactive()->create(['name' => 'Gone Sales'])],
]);

/*
|--------------------------------------------------------------------------
| The time (decisions 92 and 93)
|--------------------------------------------------------------------------
*/

test('the time is the location\'s own time', function () {
    $pacific = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = bookableLead($this->harbor);

    // 3:00 pm in Los Angeles is 10:00 pm UTC.
    $appointment = bookAppointment($lead, $pacific, $owner, '2026-10-08', '15:00')->appointment;

    expect($appointment->timezone)->toBe('America/Los_Angeles');
    expect($appointment->starts_at->toDateTimeString())->toBe('2026-10-08 22:00:00');
});

test('what may be booked', function (string $date, string $time, int $minutes, ?string $refusal) {
    $lead = bookableLead($this->lakeview);

    if ($refusal === null) {
        bookAppointment($lead, $this->sam, $this->manager, $date, $time, $minutes);

        expect(Appointment::query()->sole()->duration_minutes)->toBe($minutes);

        return;
    }

    expect(fn () => bookAppointment($lead, $this->sam, $this->manager, $date, $time, $minutes))->toThrow(ValidationException::class, $refusal);
    expect(Appointment::query()->count())->toBe(0);
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
})->with([
    'later today' => ['2026-10-07', '10:15', 90, null],
    'a Sunday evening' => ['2026-10-11', '20:45', 30, null],
    'the longest' => ['2026-10-08', '09:00', 240, null],
    'this very minute' => ['2026-10-07', '10:00', 90, 'The appointment must be in the future.'],
    'yesterday' => ['2026-10-06', '15:00', 90, 'The appointment must be in the future.'],
    'not on the quarter hour' => ['2026-10-08', '15:10', 90, 'Choose a time on the quarter hour.'],
    'too short' => ['2026-10-08', '15:00', 15, 'Choose a length between 30 minutes and 4 hours, in steps of 15 minutes.'],
    'too long' => ['2026-10-08', '15:00', 255, 'Choose a length between 30 minutes and 4 hours, in steps of 15 minutes.'],
    'an odd length' => ['2026-10-08', '15:00', 100, 'Choose a length between 30 minutes and 4 hours, in steps of 15 minutes.'],
    'not a day' => ['2026-13-40', '15:00', 90, 'Choose the day of the appointment.'],
    'not a time' => ['2026-10-08', '25:00', 90, 'Choose the time of the appointment.'],
]);

/*
|--------------------------------------------------------------------------
| No double booking (rule 5)
|--------------------------------------------------------------------------
*/

test('a salesperson cannot be booked into a time that overlaps another appointment of theirs', function (string $time, int $minutes, bool $refused) {
    // Sam is already booked tomorrow from 3:00 pm to 4:30 pm.
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    $lead = bookableLead($this->lakeview);

    if (! $refused) {
        bookAppointment($lead, $this->sam, $this->manager, time: $time, minutes: $minutes);

        expect(Appointment::query()->count())->toBe(2);

        return;
    }

    expect(fn () => bookAppointment($lead, $this->sam, $this->manager, time: $time, minutes: $minutes))
        ->toThrow(ValidationException::class, 'Sam Sales already has an appointment from 3:00 PM to 4:30 PM EDT then. Choose another time.');

    expect(Appointment::query()->count())->toBe(1);
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
})->with([
    'the same time' => ['15:00', 90, true],
    'starting inside it' => ['16:15', 60, true],
    'ending inside it' => ['14:00', 75, true],
    'wrapping around it' => ['14:00', 240, true],
    'ending as it starts' => ['13:30', 90, false],
    'starting as it ends' => ['16:30', 90, false],
]);

test('another salesperson, and an appointment that was booked in error, do not block a time', function () {
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    // Somebody else at the same time is fine.
    bookAppointment(bookableLead($this->lakeview), $colleague, $this->manager);

    // So is Sam's own time once the first booking no longer stands.
    Appointment::query()->where('salesperson_id', $this->sam->id)->update(['state' => AppointmentState::BookedInError]);
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager);

    expect(Appointment::query()->standing()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Telling the salesperson (rule 4, decision 91)
|--------------------------------------------------------------------------
*/

test('the salesperson gets a notice in the app and nobody else does', function () {
    $lead = bookableLead($this->lakeview);

    bookAppointment($lead, $this->sam, $this->manager);

    $notice = DatabaseNotification::query()->sole();

    expect($notice->notifiable_id)->toBe($this->sam->id);
    expect($notice->read_at)->toBeNull();
    expect($notice->data['kind'])->toBe('appointment_booked');
    expect($notice->data['message'])->toBe('A new appointment was booked for you on Thu, Oct 8, 2026 at 3:00 PM EDT.');
    expect($notice->data['url'])->toBe(route('leads.show', $lead));
    expect($this->manager->notifications()->count())->toBe(0);
});

test('the email and the notice carry a link and nothing about the customer', function () {
    $lead = bookableLead($this->lakeview, attributes: ['inquiry_name' => 'Private Person', 'inquiry_phone' => '555-201-9988', 'inquiry_email' => 'private@example.com']);
    $lead->account->forceFill(['display_name' => 'Private Household'])->save();
    $lead->property->forceFill(['address' => '77 Secret Lane, Springfield'])->save();

    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;

    $email = (new AppointmentBooked($appointment, AppointmentBooked::EMAIL))->toMail($this->sam);
    $text = (string) $email->render().' '.$email->subject.' '.json_encode(DatabaseNotification::query()->sole()->data);

    expect($email->actionUrl)->toBe(route('leads.show', $lead));
    expect($text)->toContain('Thu, Oct 8, 2026 at 3:00 PM EDT');

    foreach (['Private Person', 'Private Household', '555-201-9988', '5552019988', 'private@example.com', '77 Secret Lane', 'Springfield'] as $detail) {
        expect($text)->not->toContain($detail);
    }
});

test('an email that cannot be sent does not undo the booking', function () {
    useFailingMailer();

    $lead = bookableLead($this->lakeview);

    $result = bookAppointment($lead, $this->sam, $this->manager);

    expect($result->emailSent)->toBeFalse();
    expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(Appointment::query()->standing()->count())->toBe(1);
    expect(DatabaseNotification::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| All of it or none
|--------------------------------------------------------------------------
*/

test('a lead someone else changed since the dialog opened is not booked', function () {
    $lead = bookableLead($this->lakeview);
    $seen = (int) $lead->lifecycle_version;

    Lead::query()->whereKey($lead->id)->increment('lifecycle_version');

    expect(fn () => app(BookAppointment::class)->handle($lead, $this->sam, '2026-10-08', '15:00', 90, $this->manager, $seen))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expect(Appointment::query()->count())->toBe(0);
});

test('if any part of the booking fails nothing is saved', function () {
    $lead = bookableLead($this->lakeview);
    $version = (int) $lead->lifecycle_version;

    // The last thing a booking writes is the salesperson's notice.
    DatabaseNotification::creating(fn () => throw new RuntimeException('The notice could not be written.'));

    try {
        expect(fn () => bookAppointment($lead, $this->sam, $this->manager))->toThrow(RuntimeException::class);
    } finally {
        DatabaseNotification::flushEventListeners();
    }

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->first_issued_at)->toBeNull();
    expect((int) $lead->lifecycle_version)->toBe($version);
    expect(Appointment::query()->count())->toBe(0);
    expect(LeadIssue::query()->count())->toBe(0);
    expect(LeadStatusChange::query()->count())->toBe(0);
    expect(AuditEvent::query()->whereIn('action', [AuditAction::LeadAppointmentBooked, AuditAction::LeadAssigned])->count())->toBe(0);
});
