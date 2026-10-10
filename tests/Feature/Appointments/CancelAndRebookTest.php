<?php

use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Actions\Staff\DeactivateStaffMember;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\AuditEvent;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentChanged;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Recording a cancellation, and booking the Lead again
 * (docs/slice-6-appointments-plan.md, step 6b; rulebook rules 8 and 11;
 * decisions 99, 104, 106 to 109 and 113).
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern. The appointment is
 * for the next day at 3:00 pm Eastern, 90 minutes, with Sam Sales.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->noLongerNeeded = AppointmentCancellationReason::query()->where('name', 'No longer needed')->sole();
    $this->other = AppointmentCancellationReason::query()->where('name', 'Other')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->lead = $this->lead->fresh();
});

/*
|--------------------------------------------------------------------------
| Cancelling (rule 8)
|--------------------------------------------------------------------------
*/

test('the starting cancellation reasons are the owner\'s list (decision 108)', function () {
    expect(AppointmentCancellationReason::query()->ordered()->pluck('name')->all())
        ->toBe(['Holding off project', 'Went with someone else', 'No longer needed', 'Other']);

    expect($this->other->requires_note)->toBeTrue();
    expect($this->other->asks_if_disqualified)->toBeTrue();
    expect(AppointmentCancellationReason::query()->where('asks_if_disqualified', true)->count())->toBe(1);
});

test('a cancellation keeps the appointment as a record, leaves the lead with the salesperson and still counts as issued', function () {
    $this->travelTo('2026-10-07 16:00:00');

    cancelAppointment($this->lead, $this->agent, ['note' => 'They sold the house.']);

    $appointment = $this->appointment->fresh();
    $lead = $this->lead->fresh();

    expect($appointment->state)->toBe(AppointmentState::Cancelled);
    expect($appointment->stands())->toBeFalse();
    expect($appointment->cancelled_by_party)->toBe(CancellationParty::Customer);
    expect($appointment->cancellation_reason_id)->toBe($this->noLongerNeeded->id);
    expect($appointment->cancellation_reason_name)->toBe('No longer needed');
    expect($appointment->state_note)->toBe('They sold the house.');
    expect($appointment->state_changed_by)->toBe($this->agent->id);
    expect($appointment->state_changed_by_name)->toBe('Casey Caller');
    expect($appointment->state_changed_at->toDateTimeString())->toBe('2026-10-07 16:00:00');

    // Rule 8: it stays with the salesperson and still counts as issued.
    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->first_issued_at)->not->toBeNull();
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect($lead->hasUpcomingAppointment())->toBeFalse();

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::AppointmentCancelled);
    expect($change->from_status)->toBe(LeadStatus::AppointmentBooked);
    expect($change->to_status)->toBe(LeadStatus::AppointmentCancelled);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentCancelled)->sole();

    expect($event->actor_id)->toBe($this->agent->id);
    expect($event->reason)->toBe('They sold the house.');
    expect($event->values_after)->toMatchArray(['status' => 'appointment_cancelled', 'cancelled_by' => 'customer', 'reason' => 'No longer needed', 'disqualified' => false]);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentCancelled))->toBe('Appointment cancelled');
});

test('a cancellation by the salesperson or the company is the same record under the same rules (decision 104)', function (CancellationParty $party) {
    cancelAppointment($this->lead, $this->manager, ['party' => $party->value]);

    expect($this->appointment->fresh()->cancelled_by_party)->toBe($party);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
})->with([
    'the salesperson' => [CancellationParty::Salesperson],
    'the company' => [CancellationParty::Company],
]);

test('a cancelled appointment frees its time', function () {
    cancelAppointment($this->lead, $this->manager);

    $other = bookableLead($this->lakeview);

    expect(bookAppointment($other, $this->sam, $this->manager)->appointment->stands())->toBeTrue();
});

test('who may record a cancellation (decision 107)', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        cancelAppointment($this->lead, $actor);

        expect($this->appointment->fresh()->state)->toBe(AppointmentState::Cancelled);

        return;
    }

    expect(fn () => cancelAppointment($this->lead, $actor))->toThrow(AuthorizationException::class);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
})->with([
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'a Call Center Agent' => [fn ($test) => $test->agent, true],
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
    'Finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), false],
]);

test('a cancellation must say who cancelled and why', function (Closure $with, string $message) {
    expect(fn () => cancelAppointment($this->lead, $this->manager, $with($this)))->toThrow(ValidationException::class, $message);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
})->with([
    'nobody named' => [fn ($test) => ['party' => ''], 'Say who cancelled the appointment.'],
    'someone not on the list' => [fn ($test) => ['party' => 'the weather'], 'Say who cancelled the appointment.'],
    'no reason' => [fn ($test) => ['reason' => null], 'Choose why the appointment was cancelled.'],
    'a reason no longer offered' => [fn ($test) => ['reason' => AppointmentCancellationReason::factory()->inactive()->create(['name' => 'Old reason'])], 'Old reason is no longer offered. Choose another.'],
    'Other with no note' => [fn ($test) => ['reason' => $test->other, 'disqualifies' => false], 'Explain why, since "Other" says little by itself.'],
    'Other with no answer about disqualifying' => [fn ($test) => ['reason' => $test->other, 'note' => 'Something else.'], 'Say whether this lead is disqualified.'],
    'Other, disqualified, with no disqualification reason' => [fn ($test) => ['reason' => $test->other, 'note' => 'Something else.', 'disqualifies' => true], 'Choose why this lead is disqualified.'],
]);

test('the salesperson is told in the app when someone else records the cancellation, and not by email', function () {
    Notification::fake();

    cancelAppointment($this->lead, $this->agent);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::CANCELLED && $notice->channel === AppointmentChanged::IN_APP);
});

test('the salesperson is not told about a cancellation they recorded themselves', function () {
    Notification::fake();

    cancelAppointment($this->lead, $this->sam);

    Notification::assertNothingSent();
});

test('an appointment whose time has passed cannot be cancelled (decision 102)', function () {
    $this->travelTo('2026-10-08 21:00:00');

    expect(fn () => cancelAppointment($this->lead->fresh(), $this->manager))
        ->toThrow(ValidationException::class, 'This appointment is no longer upcoming');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

/*
|--------------------------------------------------------------------------
| "Other" that disqualifies the Lead (decision 108)
|--------------------------------------------------------------------------
*/

test('Other answered "not disqualified" is an ordinary cancellation', function () {
    cancelAppointment($this->lead, $this->agent, ['reason' => $this->other, 'note' => 'They will call back.', 'disqualifies' => false]);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
    expect($this->appointment->fresh()->cancellation_reason_name)->toBe('Other');
});

test('Other answered "disqualified" cancels the appointment and disqualifies the lead in one step', function () {
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);

    cancelAppointment($this->lead, $this->agent, ['reason' => $this->other, 'note' => 'They wanted a roof.', 'disqualifies' => true, 'disqualifyFor' => $reason]);

    $lead = $this->lead->fresh();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Cancelled);
    expect($lead->status)->toBe(LeadStatus::Disqualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->disqualification_reason_id)->toBe($reason->id);
    expect($lead->disqualification_note)->toBe('They wanted a roof.');

    // An agent cannot take the issue away, so it still counts (decision 110).
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentCancelled)->sole()->values_after)->toMatchArray(['disqualified' => true]);
    expect(AuditEvent::query()->where('action', AuditAction::LeadDisqualified)->sole()->values_after)->toMatchArray(['counts_as_issued' => true]);
});

test('a manager disqualifying through Other can say it should never have been booked, and an agent cannot', function () {
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);
    $with = ['reason' => $this->other, 'note' => 'They wanted a roof.', 'disqualifies' => true, 'disqualifyFor' => $reason, 'neverShouldHaveBeenBooked' => true];

    expect(fn () => cancelAppointment($this->lead, $this->agent, $with))
        ->toThrow(ValidationException::class, 'Only a manager can say a lead should never have been booked.');

    // Nothing of the refused change was saved.
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    cancelAppointment($this->lead->fresh(), $this->manager, $with);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Disqualified);
    expect(LeadIssue::query()->counted()->count())->toBe(0);
    expect(LeadIssue::query()->sole()->voided_by)->toBe($this->manager->id);
    expect($lead->first_issued_at)->toBeNull();
    expect(AuditEvent::query()->where('action', AuditAction::LeadDisqualified)->sole()->values_after)->toMatchArray(['counts_as_issued' => false]);
});

test('a reason that does not ask never disqualifies the lead, whatever is sent', function () {
    $reason = LeadDisqualificationReason::factory()->create();

    cancelAppointment($this->lead, $this->manager, ['disqualifies' => true, 'disqualifyFor' => $reason, 'neverShouldHaveBeenBooked' => true]);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Booking again (decisions 99, 109 and 113)
|--------------------------------------------------------------------------
*/

test('booking a cancelled lead again with the same salesperson adds no issued count (decision 99)', function () {
    cancelAppointment($this->lead, $this->agent);

    $issuedAt = $this->lead->fresh()->last_issued_at;

    $this->travelTo('2026-10-07 17:00:00');
    Notification::fake();

    $rebooked = bookAppointment($this->lead->fresh(), $this->sam, $this->agent, '2026-10-12', '10:00')->appointment;

    $lead = $this->lead->fresh();

    expect($rebooked->stands())->toBeTrue();
    expect($rebooked->follows_appointment_id)->toBe($this->appointment->id);
    expect($rebooked->followsAnother())->toBeTrue();
    expect($rebooked->previous_status)->toBe(LeadStatus::AppointmentCancelled);

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->sam->id);

    // One Lead issued once: no new issue, and the issued times are as they were.
    expect(LeadIssue::query()->count())->toBe(1);
    expect($lead->last_issued_at->equalTo($issuedAt))->toBeTrue();

    // The cancelled appointment is still on record.
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Cancelled);
    expect(Appointment::query()->where('lead_id', $lead->id)->count())->toBe(2);

    expect(LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->event)->toBe(LeadStatusEvent::AppointmentRebooked);
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentRebooked)->count())->toBe(1);
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentBooked)->count())->toBe(1);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentRebooked))->toBe('Appointment booked again');

    Notification::assertSentToTimes($this->sam, AppointmentBooked::class, 2);
});

test('the salesperson can book their own cancelled lead again, for themselves', function () {
    cancelAppointment($this->lead, $this->sam);

    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->count())->toBe(1);
});

test('booking a cancelled lead with a different salesperson takes a manager (decision 109)', function () {
    cancelAppointment($this->lead, $this->agent);

    expect(fn () => bookAppointment($this->lead->fresh(), $this->riley, $this->agent, '2026-10-12', '10:00'))
        ->toThrow(ValidationException::class, 'This lead is with Sam Sales. Book it with them, or ask a manager to give it to someone else.');

    expect(Appointment::query()->count())->toBe(1);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);

    $rebooked = bookAppointment($this->lead->fresh(), $this->riley, $this->manager, '2026-10-12', '10:00')->appointment;

    expect($rebooked->salesperson_id)->toBe($this->riley->id);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->riley->id);

    // Still one issue, as first recorded; the audit event shows both people.
    expect(LeadIssue::query()->sole()->salesperson_id)->toBe($this->sam->id);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentRebooked)->sole();

    expect($event->values_before)->toMatchArray(['salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['salesperson' => 'Riley Rep']);
});

test('a cancelled lead whose salesperson lost access waits for a manager to choose a new one', function () {
    cancelAppointment($this->lead, $this->agent);
    app(DeactivateStaffMember::class)->handle($this->sam);

    expect($this->lead->fresh()->assigned_user_id)->toBeNull();

    expect(fn () => bookAppointment($this->lead->fresh(), $this->riley, $this->agent, '2026-10-12', '10:00'))
        ->toThrow(ValidationException::class, 'This lead is with Sales and has no salesperson now. A manager chooses the new one.');

    bookAppointment($this->lead->fresh(), $this->riley, $this->manager, '2026-10-12', '10:00');

    expect($this->lead->fresh()->assigned_user_id)->toBe($this->riley->id);
    expect(LeadIssue::query()->count())->toBe(1);
});

test('after an appointment has passed another can be booked, with no new issued count (decision 113)', function () {
    $this->travelTo('2026-10-09 14:00:00');

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->hasUpcomingAppointment())->toBeFalse();

    $another = bookAppointment($lead, $this->sam, $this->agent, '2026-10-13', '10:00')->appointment;

    expect($another->follows_appointment_id)->toBe($this->appointment->id);
    expect($another->previous_status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->count())->toBe(1);

    // The passed appointment is left exactly as it was.
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->standingAppointment()->id)->toBe($another->id);
});

test('"booked in error" on a rebooking puts the lead back as cancelled and leaves its issue counted', function () {
    cancelAppointment($this->lead, $this->agent);
    $rebooked = bookAppointment($this->lead->fresh(), $this->sam, $this->agent, '2026-10-12', '10:00')->appointment;

    app(MarkAppointmentBookedInError::class)->handle($this->lead->fresh(), $rebooked, 'Wrong day entered.', $this->manager);

    $lead = $this->lead->fresh();

    expect($rebooked->fresh()->state)->toBe(AppointmentState::BookedInError);
    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect($lead->first_issued_at)->not->toBeNull();
});
