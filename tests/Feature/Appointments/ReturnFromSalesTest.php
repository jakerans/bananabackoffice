<?php

use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AppointmentState;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\SalesReturn;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * A Lead that is with Sales going back to Contact Center
 * (docs/slice-6-appointments-plan.md, step 6b; rulebook rule 9; decisions 97,
 * 100, 101, 103, 105 and 110 to 114).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern. The appointment is
 * for the next day at 3:00 pm Eastern, 90 minutes, with Sam Sales.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    enterQueue($this->lead);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->lead = $this->lead->fresh();
});

function sendBack(Lead $lead, User $by, ?string $issuedCount = StopCountingLeadAsIssued::ANOTHER_REASON, ?string $note = 'The customer wants a call first.'): void
{
    app(SendLeadBackToContactCenter::class)->handle($lead, $issuedCount, $note, $by);
}

/**
 * Cancel the Lead's appointment the way the customer would, so the Lead is
 * Appointment cancelled and still with its salesperson.
 */
function cancelledLead(Lead $lead, User $by): Lead
{
    cancelAppointment($lead, $by);

    return $lead->fresh();
}

/*
|--------------------------------------------------------------------------
| Send back to Contact Center (rule 9)
|--------------------------------------------------------------------------
*/

test('sending back returns the lead to the contact center: qualified, with nobody, in the call queue from attempt 1', function () {
    $this->travelTo('2026-10-07 15:00:00');

    $cycle = LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->cycle;

    sendBack($this->lead, $this->manager);

    $lead = $this->lead->fresh();
    $appointment = $this->appointment->fresh();

    // Decisions 97 and 103.
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->returned_from_sales_at->toDateTimeString())->toBe('2026-10-07 15:00:00');
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->end_reason)->toBe(AssignmentEndReason::ReturnedFromSales);

    // Decision 111: its upcoming appointment is cancelled in the same step, as the company's.
    expect($appointment->state)->toBe(AppointmentState::Cancelled);
    expect($appointment->cancelled_by_party)->toBe(CancellationParty::Company);
    expect($appointment->cancellation_reason_id)->toBeNull();
    expect($appointment->state_note)->toBe('The customer wants a call first.');
    expect($appointment->state_changed_by)->toBe($this->manager->id);

    // Decision 100: for another reason it still counts as issued.
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect($lead->first_issued_at)->not->toBeNull();

    // Decision 105: the cadence starts over, as a new cycle and not as a Lead nobody has called.
    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->cycle)->toBe($cycle + 1);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->due_at)->not->toBeNull();
    expect($cadence->ended_at)->toBeNull();
    expect(queueHistory($lead))->toContain('returned_to_queue');

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::ReturnedFromSales);
    expect($change->from_status)->toBe(LeadStatus::AppointmentBooked);
    expect($change->to_status)->toBe(LeadStatus::Qualified);
    expect($change->assigned_user_id)->toBe($this->sam->id);
    expect($change->note)->toBe('The customer wants a call first.');

    $event = AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole();

    expect($event->actor_id)->toBe($this->manager->id);
    expect($event->reason)->toBe('The customer wants a call first.');
    expect($event->values_before)->toMatchArray(['status' => 'appointment_booked', 'salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['status' => 'qualified', 'how' => 'sent_back', 'counts_as_issued' => true]);
    expect(CustomerActivity::label(AuditAction::LeadReturnedFromSales))->toBe('Lead went back to Contact Center');
});

test('a lead that should never have been booked stops counting as issued (decisions 100 and 110)', function () {
    sendBack($this->lead, $this->manager, StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED, 'It was a wrong number.');

    $lead = $this->lead->fresh();
    $issue = LeadIssue::query()->sole();

    expect($issue->counts())->toBeFalse();
    expect($issue->voided_by)->toBe($this->manager->id);
    expect($issue->voided_by_name)->toBe('Morgan Manager');
    expect($lead->first_issued_at)->toBeNull();
    expect($lead->last_issued_at)->toBeNull();
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole()->values_after)->toMatchArray(['counts_as_issued' => false]);
});

test('only a manager may send a lead back', function (Closure $person) {
    expect(fn () => sendBack($this->lead, $person($this)))->toThrow(AuthorizationException::class);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
})->with([
    'the salesperson it is with (decision 111)' => [fn ($test) => $test->sam],
    'a Call Center Agent (decision 88)' => [fn ($test) => $test->agent],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create()],
]);

test('sending back needs the answer and a note, and a lead that is with Sales', function () {
    expect(fn () => sendBack($this->lead, $this->manager, null))->toThrow(ValidationException::class, 'Say whether this lead should ever have been booked.');
    expect(fn () => sendBack($this->lead, $this->manager, 'maybe'))->toThrow(ValidationException::class, 'Say whether this lead should ever have been booked.');
    expect(fn () => sendBack($this->lead, $this->manager, StopCountingLeadAsIssued::ANOTHER_REASON, '  '))->toThrow(ValidationException::class, 'Say why this lead is going back to the Contact Center.');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);

    $qualified = bookableLead($this->lakeview);

    expect(fn () => sendBack($qualified, $this->manager))->toThrow(ValidationException::class, 'This lead is not with Sales');
});

test('sending back a lead whose appointment was already cancelled leaves that record as it is', function () {
    $lead = cancelledLead($this->lead, $this->agent);

    sendBack($lead, $this->manager);

    $appointment = $this->appointment->fresh();

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($appointment->cancelled_by_party)->toBe(CancellationParty::Customer);
    expect($appointment->cancellation_reason_name)->toBe('No longer needed');
    expect($appointment->state_changed_by)->toBe($this->agent->id);
});

test('sending back a lead whose appointment has passed leaves the appointment standing on its record', function () {
    $this->travelTo('2026-10-09 14:00:00');

    sendBack($this->lead->fresh(), $this->manager);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

test('a lead that never had a cadence is given one that starts as a second cycle', function () {
    $lead = bookableLead($this->lakeview);
    bookAppointment($lead, $this->sam, $this->manager, time: '18:00');

    expect(LeadCadence::query()->where('lead_id', $lead->id)->exists())->toBeFalse();

    sendBack($lead->fresh(), $this->manager);

    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->cycle)->toBe(2);
    expect($cadence->attempts_made)->toBe(0);
});

test('the salesperson is told in the app that their lead went back', function () {
    Notification::fake();

    sendBack($this->lead, $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::LEAD_RETURNED && $notice->channel === AppointmentChanged::IN_APP);
});

/*
|--------------------------------------------------------------------------
| In the call queue again (decisions 105 and 114)
|--------------------------------------------------------------------------
*/

test('a lead sent back is handed out by the call queue after leads nobody has called', function () {
    sendBack($this->lead, $this->manager);

    $returned = LeadCadence::query()->where('lead_id', $this->lead->id)->sole();
    $returned->forceFill(['due_at' => now()->subHour()])->save();

    // A new Lead that came in later and has waited less is still handed out first.
    $new = dueLead($this->lakeview);
    $second = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect(nextLead($this->agent)->lead->id)->toBe($new->id);
    expect(nextLead($second)->lead->id)->toBe($this->lead->id);
});

test('the call center is told what it is calling: a cancelled appointment, by whom and why', function () {
    $lead = cancelledLead($this->lead, $this->agent);

    expect(SalesReturn::of($lead))->toBeNull();

    sendBack($lead, $this->manager, note: 'Sam could not reach them to rebook.');

    $return = SalesReturn::of($lead->fresh());

    expect($return->appointmentWasCancelled)->toBeTrue();
    expect($return->headline)->toBe('Cancelled appointment: this lead came back from Sales');
    expect($return->appointment)->toBe('Its appointment on Thu, Oct 8 at 3:00 PM EDT with Sam Sales was cancelled. Cancelled by: the customer. Reason: No longer needed.');
    expect($return->returnedBy)->toBe('Morgan Manager');
    expect($return->note)->toBe('Sam could not reach them to rebook.');
});

test('a lead sent back with its appointment still upcoming says the appointment was cancelled when it was sent back', function () {
    sendBack($this->lead, $this->manager);

    $return = SalesReturn::of($this->lead->fresh());

    expect($return->appointmentWasCancelled)->toBeTrue();
    expect($return->appointment)->toBe('Its appointment on Thu, Oct 8 at 3:00 PM EDT with Sam Sales was cancelled when it was sent back.');
});

test('a lead sent back after its appointment passed says it had one', function () {
    $this->travelTo('2026-10-09 14:00:00');

    sendBack($this->lead->fresh(), $this->manager);

    $return = SalesReturn::of($this->lead->fresh());

    expect($return->appointmentWasCancelled)->toBeFalse();
    expect($return->headline)->toBe('This lead came back from Sales');
    expect($return->appointment)->toBe('It had an appointment on Thu, Oct 8 at 3:00 PM EDT with Sam Sales.');
});

test('the marker is cleared when the lead is booked again, and that booking is a new issue', function () {
    sendBack($this->lead, $this->manager);

    bookAppointment($this->lead->fresh(), $this->sam, $this->agent, '2026-10-12', '10:00');

    $lead = $this->lead->fresh();

    expect($lead->returned_from_sales_at)->toBeNull();
    expect(SalesReturn::of($lead))->toBeNull();
    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);

    // It left Sales and was issued again: a re-issue, recorded with full history (rule 8).
    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(2);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->state)->toBe(CadenceState::AppointmentBooked);
});

test('the timer takes a lead out of the queue if it is with Sales and still in it', function () {
    $lead = cancelledLead($this->lead, $this->agent);

    LeadCadence::query()->where('lead_id', $lead->id)->update(['state' => CadenceState::InQueue->value, 'due_at' => now(), 'ended_at' => null]);

    app(RunQueueTick::class)->handle();

    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->state)->toBe(CadenceState::AppointmentBooked);
});

/*
|--------------------------------------------------------------------------
| Moved, returned to central or disqualified while with Sales (decisions 101 and 112)
|--------------------------------------------------------------------------
*/

test('a lead with an upcoming appointment still cannot be moved, returned or disqualified (decision 95)', function () {
    $reason = LeadDisqualificationReason::factory()->create();

    expect(fn () => app(RouteLead::class)->handle($this->lead, $this->harbor, 'Wrong area.', $this->leader))->toThrow(ValidationException::class, 'upcoming appointment');
    expect(fn () => app(ReturnLeadToCentral::class)->handle($this->lead, 'Wrong area.', $this->leader))->toThrow(ValidationException::class, 'upcoming appointment');
    expect(fn () => app(DisqualifyLead::class)->handle($this->lead, $reason, 'Not real.', $this->leader))->toThrow(ValidationException::class, 'upcoming appointment');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('a cancelled lead moved to another location goes back to the contact center there (decisions 101 and 112)', function () {
    $lead = cancelledLead($this->lead, $this->agent);
    $version = $lead->lifecycle_version;

    $this->travelTo('2026-10-07 16:00:00');

    app(RouteLead::class)->handle($lead, $this->harbor, 'It is in the Harbor service area.', $this->leader);

    $lead = $lead->fresh();

    expect($lead->location_id)->toBe($this->harbor->id);
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->returned_from_sales_at->toDateTimeString())->toBe('2026-10-07 16:00:00');
    expect($lead->lifecycle_version)->toBe($version + 1);
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->attempts_made)->toBe(0);

    // The cancelled appointment stays on its record, with its reason.
    expect($this->appointment->fresh()->cancellation_reason_name)->toBe('No longer needed');

    expect(LeadAssignment::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->end_reason)->toBe(AssignmentEndReason::ReturnedFromSales);
    expect(AuditEvent::query()->where('action', AuditAction::LeadTransferred)->count())->toBe(1);

    $event = AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole();

    expect($event->values_after)->toMatchArray(['how' => 'moved', 'counts_as_issued' => true]);
    expect($event->location_id)->toBe($this->lakeview->id);
    expect(SalesReturn::of($lead)->note)->toBe('It is in the Harbor service area.');
});

test('a cancelled lead returned to central goes back to the contact center, unrouted', function () {
    $lead = cancelledLead($this->lead, $this->agent);

    app(ReturnLeadToCentral::class)->handle($lead, 'Nobody at Lakeview can take it.', $this->leader);

    $lead = $lead->fresh();

    expect($lead->location_id)->toBeNull();
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->returned_from_sales_at)->not->toBeNull();
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->state)->toBe(CadenceState::InQueue);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole()->values_after)->toMatchArray(['how' => 'returned_to_central', 'counts_as_issued' => true]);
});

test('a manager moving or returning a lead can say it should never have been booked', function (string $how) {
    $lead = cancelledLead($this->lead, $this->agent);

    $how === 'move'
        ? app(RouteLead::class)->handle($lead, $this->harbor, 'Wrong service area from the start.', $this->leader, true)
        : app(ReturnLeadToCentral::class)->handle($lead, 'Wrong service area from the start.', $this->leader, true);

    expect($lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect(LeadIssue::query()->counted()->count())->toBe(0);
    expect($lead->fresh()->first_issued_at)->toBeNull();
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole()->values_after)->toMatchArray(['counts_as_issued' => false]);
})->with(['move', 'return']);

test('someone who is not a manager cannot say it should never have been booked, and nothing is moved', function () {
    $lead = cancelledLead($this->lead, $this->agent);

    expect(fn () => app(RouteLead::class)->handle($lead, $this->harbor, 'Wrong service area.', $this->agent, true))
        ->toThrow(ValidationException::class, 'Only a manager can say a lead should never have been booked.');

    $lead = $lead->fresh();

    expect($lead->location_id)->toBe($this->lakeview->id);
    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
});

test('moving a lead that is not with Sales is as it always was', function () {
    $lead = bookableLead($this->lakeview, $this->sam)->fresh();
    $version = $lead->lifecycle_version;

    app(RouteLead::class)->handle($lead, $this->harbor, 'Wrong service area.', $this->leader, true);

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->returned_from_sales_at)->toBeNull();
    expect($lead->lifecycle_version)->toBe($version);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->count())->toBe(0);
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->end_reason)->toBe(AssignmentEndReason::LocationChanged);
});

test('a cancelled lead can be disqualified and still counts as issued unless a manager says otherwise', function (bool $never, int $counted) {
    $lead = cancelledLead($this->lead, $this->agent);
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);

    app(DisqualifyLead::class)->handle($lead, $reason, 'They wanted a roof.', $this->manager, neverShouldHaveBeenBooked: $never);

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Disqualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->returned_from_sales_at)->toBeNull();
    expect(LeadIssue::query()->counted()->count())->toBe($counted);
    expect(AuditEvent::query()->where('action', AuditAction::LeadDisqualified)->sole()->values_after)->toMatchArray(['counts_as_issued' => ! $never]);
})->with([
    'another reason' => [false, 1],
    'it should never have been booked' => [true, 0],
]);

test('the salesperson disqualifying their own cancelled lead cannot take its issue away', function () {
    $lead = cancelledLead($this->lead, $this->agent);
    $reason = LeadDisqualificationReason::factory()->create();

    expect(fn () => app(DisqualifyLead::class)->handle($lead, $reason, 'Not real.', $this->sam, neverShouldHaveBeenBooked: true))
        ->toThrow(ValidationException::class, 'Only a manager can say a lead should never have been booked.');

    expect($lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
});

test('disqualifying a lead that came back from Sales clears its marker', function () {
    sendBack($this->lead, $this->manager);

    app(DisqualifyLead::class)->handle($this->lead->fresh(), LeadDisqualificationReason::factory()->create(), 'Not real.', $this->manager);

    expect($this->lead->fresh()->returned_from_sales_at)->toBeNull();
});
