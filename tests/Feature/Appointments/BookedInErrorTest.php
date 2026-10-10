<?php

use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Queue\RunQueueTick;
use App\Actions\Staff\DeactivateStaffMember;
use App\Enums\AppointmentState;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\ContactAttemptDisposition;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
 * "Booked in error" and what a standing appointment blocks
 * (docs/slice-6-appointments-plan.md, step 6a; rulebook rule 10; decisions 95
 * and 96).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
});

function undo(Lead $lead, User $by, ?string $note = 'Booked for the wrong customer.'): void
{
    app(MarkAppointmentBookedInError::class)->handle($lead, $lead->standingAppointment() ?? Appointment::query()->where('lead_id', $lead->id)->firstOrFail(), $note, $by);
}

/*
|--------------------------------------------------------------------------
| Booked in error (rule 10, decision 96)
|--------------------------------------------------------------------------
*/

test('booked in error puts the lead back exactly as it was and the issue no longer counts', function () {
    $before = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Before Sales']);
    $lead = bookableLead($this->lakeview, $before);
    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-07 15:00:00');

    undo($lead->fresh(), $this->manager);

    $lead = $lead->fresh();
    $appointment = $appointment->fresh();

    // The Lead: Qualified again, with the person it was with before, and never issued.
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBe($before->id);
    expect($lead->first_issued_at)->toBeNull();
    expect($lead->last_issued_at)->toBeNull();

    // The appointment is kept, and says who undid it and why.
    expect($appointment->state)->toBe(AppointmentState::BookedInError);
    expect($appointment->state_changed_by)->toBe($this->manager->id);
    expect($appointment->state_changed_by_name)->toBe('Morgan Manager');
    expect($appointment->state_changed_at->toDateTimeString())->toBe('2026-10-07 15:00:00');
    expect($appointment->state_note)->toBe('Booked for the wrong customer.');

    // The issue is kept and does not count.
    $issue = LeadIssue::query()->sole();

    expect($issue->counts())->toBeFalse();
    expect($issue->voided_by)->toBe($this->manager->id);
    expect(LeadIssue::query()->counted()->count())->toBe(0);

    // It is recorded: the status history, the audit event and the customer's Activity.
    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::AppointmentBookedInError);
    expect($change->from_status)->toBe(LeadStatus::AppointmentBooked);
    expect($change->to_status)->toBe(LeadStatus::Qualified);
    expect($change->note)->toBe('Booked for the wrong customer.');

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentBookedInError)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->reason)->toBe('Booked for the wrong customer.');
    expect($event->values_before)->toMatchArray(['status' => 'appointment_booked', 'salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['status' => 'qualified', 'assignee' => 'Before Sales']);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentBookedInError))->toBe('Appointment booked in error');

    // The salesperson's assignment ended, and the earlier person has an open one again.
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->whereNull('ended_at')->sole()->user_id)->toBe($before->id);
});

test('a lead that nobody had before the booking goes back to nobody', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    undo($lead->fresh(), $this->manager);

    expect($lead->fresh()->assigned_user_id)->toBeNull();

    $ended = LeadAssignment::query()->where('lead_id', $lead->id)->sole();

    expect($ended->ended_at)->not->toBeNull();
    expect($ended->end_reason)->toBe(AssignmentEndReason::AppointmentUndone);
});

test('if the person who had it before can no longer work it, it goes back to nobody', function () {
    $before = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
    $lead = bookableLead($this->lakeview, $before);
    bookAppointment($lead, $this->sam, $this->manager);

    $before->forceFill(['is_active' => false])->save();

    undo($lead->fresh(), $this->manager);

    expect($lead->fresh()->assigned_user_id)->toBeNull();
    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('a lead the booking took out of the call queue goes back in at the attempt it had reached', function () {
    $setter = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create();
    $lead = bookableLead($this->lakeview);
    $cadence = enterQueue($lead);

    logAttempt($lead, $setter);

    bookAppointment($lead, $this->sam, $this->manager);

    expect($cadence->fresh()->state)->toBe(CadenceState::AppointmentBooked);

    $this->travelTo('2026-10-07 15:00:00');

    undo($lead->fresh(), $this->manager, 'Wrong lead.');

    $cadence = $cadence->fresh();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->attempts_made)->toBe(1);
    expect($cadence->cycle)->toBe(1);
    expect($cadence->due_at->toDateTimeString())->toBe('2026-10-07 15:00:00');
    expect($cadence->ended_at)->toBeNull();
    expect(queueHistory($lead))->toBe(['entered', 'attempt_counted', 'ended:appointment_booked', 'returned_to_queue']);

    // It is offered again.
    expect(nextLead($setter)->lead->id)->toBe($lead->id);
});

test('a lead that was not in the queue when it was booked is not put in it', function () {
    $setter = User::factory()->withRoleAt(Role::CallCenterAgent, $this->lakeview)->create();
    $lead = bookableLead($this->lakeview);
    $cadence = enterQueue($lead);

    // A real conversation ended its cadence before it was qualified and booked.
    logAttempt($lead, $setter, ['wasAnswered' => true, 'wasConnected' => true, 'disposition' => ContactAttemptDisposition::Connected]);

    bookAppointment($lead, $this->sam, $this->manager);
    undo($lead->fresh(), $this->manager);

    expect($cadence->fresh()->state)->toBe(CadenceState::Connected);
});

test('an earlier issue that still counts keeps the lead\'s issue times', function () {
    $lead = bookableLead($this->lakeview);

    // An issue from an earlier appointment that still counts.
    $earlier = Appointment::factory()->forLead($lead)->with($this->sam)->at(now()->subDays(3))->create();
    $issue = new LeadIssue;
    $issue->forceFill([
        'lead_id' => $lead->id, 'appointment_id' => $earlier->id, 'location_id' => $this->lakeview->id,
        'salesperson_id' => $this->sam->id, 'salesperson_name' => 'Sam Sales',
        'issued_at' => '2026-10-01 12:00:00', 'created_at' => '2026-10-01 12:00:00',
    ])->save();
    $earlier->forceFill(['state' => AppointmentState::BookedInError])->save();

    bookAppointment($lead, $this->sam, $this->manager, time: '18:00');
    undo($lead->fresh(), $this->manager);

    $lead = $lead->fresh();

    expect($lead->first_issued_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    expect($lead->last_issued_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
});

test('who may mark an appointment booked in error', function (Closure $person, bool $allowed) {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    $lead = $lead->fresh();
    $user = $person($this);

    expect($user->can('manageAppointment', $lead))->toBe($allowed);

    if ($allowed) {
        undo($lead, $user);

        expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
    } else {
        expect(fn () => undo($lead, $user))->toThrow(AuthorizationException::class);

        expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
        expect(Appointment::query()->standing()->count())->toBe(1);
        expect(LeadIssue::query()->counted()->count())->toBe(1);
    }
})->with([
    'an owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'leadership' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Leadership)->create(), true],
    'the location manager there' => [fn ($test) => $test->manager, true],
    'a call center agent, who may book' => [fn ($test) => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), false],
    'the salesperson' => [fn ($test) => $test->sam, false],
    'a location manager of another location' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, $test->harbor)->create(), false],
    'finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), false],
]);

test('it needs a note', function (?string $note) {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    expect(fn () => undo($lead->fresh(), $this->manager, $note))
        ->toThrow(ValidationException::class, 'Say why this appointment was booked in error.');

    expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(Appointment::query()->standing()->count())->toBe(1);
})->with([null, '', '   ']);

test('an appointment that is already undone cannot be undone again', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    undo($lead->fresh(), $this->manager);

    expect(fn () => undo($lead->fresh(), $this->manager))
        ->toThrow(ValidationException::class, 'This appointment is no longer standing, so there is nothing to undo.');

    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentBookedInError)->count())->toBe(1);
});

test('after an undo the lead can be booked again and the new issue counts', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);
    undo($lead->fresh(), $this->manager);

    $this->travelTo('2026-10-07 16:00:00');

    // The first appointment no longer holds Sam's time.
    bookAppointment($lead->fresh(), $this->sam, $this->manager);

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->first_issued_at->toDateTimeString())->toBe('2026-10-07 16:00:00');
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect(LeadIssue::query()->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| While an appointment stands (decision 95)
|--------------------------------------------------------------------------
*/

test('a lead with an upcoming appointment cannot be moved, returned to central or disqualified', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    $lead = $lead->fresh();
    $refusal = 'This lead has an upcoming appointment. Cancel or undo its appointment first.';

    expect(fn () => app(RouteLead::class)->handle($lead, $this->harbor, 'Wrong office.', $owner))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(ReturnLeadToCentral::class)->handle($lead, 'Wrong office.', $owner))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(DisqualifyLead::class)->handle($lead, LeadDisqualificationReason::factory()->create(), 'Not real.', $owner))->toThrow(ValidationException::class, $refusal);

    $lead = $lead->fresh();

    expect($lead->location_id)->toBe($this->lakeview->id);
    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
});

test('once the appointment is undone the lead can be moved again', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);
    undo($lead->fresh(), $this->manager);

    app(RouteLead::class)->handle($lead->fresh(), $this->harbor, 'Wrong office.', $owner);

    expect($lead->fresh()->location_id)->toBe($this->harbor->id);
});

test('an appointment that has finished no longer blocks the lead', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager);

    // The day after the appointment.
    $this->travelTo('2026-10-09 14:00:00');

    expect($lead->fresh()->hasUpcomingAppointment())->toBeFalse();

    app(DisqualifyLead::class)->handle($lead->fresh(), LeadDisqualificationReason::factory()->create(), 'Not real.', $owner);

    expect($lead->fresh()->status)->toBe(LeadStatus::Disqualified);
});

test('when the salesperson is deactivated the appointment stays and the lead is left for a manager', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = bookableLead($this->lakeview);
    $appointment = bookAppointment($lead, $this->sam, $this->manager)->appointment;

    $this->actingAs($owner);
    app(DeactivateStaffMember::class)->handle($this->sam);

    $lead = $lead->fresh();

    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($appointment->fresh()->stands())->toBeTrue();
    expect($appointment->fresh()->salesperson_name)->toBe('Sam Sales');

    // A manager can still undo it, and it then goes back as it was.
    undo($lead, $this->manager);

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('the timer takes a booked lead out of the queue if a crash left it there', function () {
    $lead = bookableLead($this->lakeview);
    $cadence = enterQueue($lead);

    // What a crash between two writes could leave: booked, with its cadence still in the queue.
    $lead->forceFill(['status' => LeadStatus::AppointmentBooked])->save();

    $done = app(RunQueueTick::class)->handle();

    expect($done)->toMatchArray(['ended' => 1, 'failed' => 0]);
    expect($cadence->fresh()->state)->toBe(CadenceState::AppointmentBooked);
    expect(LeadCadence::query()->where('state', CadenceState::InQueue)->count())->toBe(0);
});
