<?php

use App\Actions\Appointments\ReleaseCancelledLead;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Appointments\TakeLeadBackFromContactCenter;
use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AssignmentEndReason;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\CallQueue;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ContactCenterSettings;
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
use App\Support\Queue\DueQueue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * A cancelled Lead that is not booked again is released to the Contact
 * Center, worked from the Cancelled Appointments queue, and can be taken back
 * by its salesperson (docs/slice-6-appointments-plan.md, step 6c; rulebook
 * rules 8 and 12; decisions 115 to 120).
 *
 * The clock starts on Wednesday 7 October 2026, 10:00 am Eastern. The
 * appointment is for the next day at 3:00 pm with Sam Sales, and is cancelled
 * at 11:00 am on the 7th. The waiting period is 14 days.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->lead = bookableLead($this->lakeview);
    enterQueue($this->lead);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-07 15:00:00');
    cancelAppointment($this->lead->fresh(), $this->agent);
    $this->lead = $this->lead->fresh();
});

/**
 * Run the every-minute timer as the scheduler does: with nobody signed in.
 *
 * @return array<string, int>
 */
function tick(): array
{
    return app(RunQueueTick::class)->handle();
}

function takeBack(Lead $lead, User $by, ?string $note = 'The homeowner called me back.'): void
{
    app(TakeLeadBackFromContactCenter::class)->handle($lead, $note, $by);
}

/*
|--------------------------------------------------------------------------
| The waiting period (decisions 116 and 119)
|--------------------------------------------------------------------------
*/

test('the waiting period starts at 14 days', function () {
    expect(ContactCenterSettings::current()->cancelled_release_days)->toBe(14);
    expect(ContactCenterSettings::DEFAULT_RELEASE_DAYS)->toBe(14);
});

test('a cancelled lead is released once the whole waiting period has passed, and not a minute before', function () {
    $this->travelTo('2026-10-21 14:59:00');

    expect(tick()['released'])->toBe(0);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);

    $this->travelTo('2026-10-21 15:00:00');

    expect(tick()['released'])->toBe(1);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);

    // Running it again does nothing more.
    expect(tick()['released'])->toBe(0);
    expect(AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->count())->toBe(1);
});

test('a released lead is back with the contact center, in the cancelled appointments queue, and still counts as issued', function () {
    $version = $this->lead->lifecycle_version;

    $this->travelTo('2026-10-21 15:00:00');
    tick();

    $lead = $this->lead->fresh();

    // Decisions 97 and 103: Qualified, with nobody.
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->assigned_user_id)->toBeNull();
    expect($lead->lifecycle_version)->toBe($version + 1);
    expect(LeadAssignment::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->end_reason)->toBe(AssignmentEndReason::ReturnedFromSales);

    // It remembers who it was released from, so they can take it back (rule 12).
    expect($lead->released_from_user_id)->toBe($this->sam->id);
    expect($lead->released_from_sales_at->toDateTimeString())->toBe('2026-10-21 15:00:00');
    expect($lead->returned_from_sales_at->toDateTimeString())->toBe('2026-10-21 15:00:00');

    // Rule 8: it still counts as issued.
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    // Decisions 115 and 117: its own queue, the ordinary cadence from attempt 1.
    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->state)->toBe(CadenceState::InQueue);
    expect($cadence->queue)->toBe(CallQueue::CancelledAppointments);
    expect($cadence->attempts_made)->toBe(0);
    expect($cadence->due_at)->not->toBeNull();

    // The cancelled appointment is untouched.
    expect($this->appointment->fresh()->cancellation_reason_name)->toBe('No longer needed');

    // Recorded as the timer's doing: nobody is named.
    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::ReturnedFromSales);
    expect($change->changed_by)->toBeNull();
    expect($change->note)->toBe('Not booked again within 14 days of the cancellation.');

    $event = AuditEvent::query()->where('action', AuditAction::LeadReturnedFromSales)->sole();

    expect($event->actor_id)->toBeNull();
    expect($event->values_before)->toMatchArray(['status' => 'appointment_cancelled', 'salesperson' => 'Sam Sales']);
    expect($event->values_after)->toMatchArray(['status' => 'qualified', 'how' => 'released', 'counts_as_issued' => true]);

    // What the call center is told (decision 114).
    $return = SalesReturn::of($lead);

    expect($return->appointmentWasCancelled)->toBeTrue();
    expect($return->returnedBy)->toBeNull();
    expect($return->note)->toBe('Not booked again within 14 days of the cancellation.');
});

test('a lead booked again in time is never released', function () {
    bookAppointment($this->lead, $this->sam, $this->agent, '2026-10-30', '10:00');

    $this->travelTo('2026-10-25 15:00:00');

    expect(tick()['released'])->toBe(0);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
});

test('the number of days can be changed by the people who change the contact center settings, within its limits', function () {
    $change = app(ChangeContactCenterSettings::class);
    $version = ContactCenterSettings::current()->version;

    expect(fn () => $change->releaseDays($this->manager, $version, 7))->toThrow(AuthorizationException::class);

    foreach ([0, 181, '7.5', 'a week', -3] as $refused) {
        expect(fn () => $change->releaseDays($this->leader, $version, $refused))
            ->toThrow(ValidationException::class, 'The number of days must be a whole number from 1 to 180.');
    }

    expect($change->releaseDays($this->leader, $version, 14))->toBeFalse();
    expect($change->releaseDays($this->leader, $version, 7))->toBeTrue();

    $settings = ContactCenterSettings::current();

    expect($settings->version)->toBe($version + 1);
    expect($settings->cancelled_release_days)->toBe(7);

    $event = AuditEvent::query()->where('action', AuditAction::ContactCenterReleaseDaysChanged)->sole();

    expect($event->values_before)->toBe(['cancelled_release_days' => 14]);
    expect($event->values_after)->toBe(['cancelled_release_days' => 7]);

    // Saving another section afterwards keeps the number.
    $change->holdLength($this->leader, $settings->version, 9);

    expect(ContactCenterSettings::current()->cancelled_release_days)->toBe(7);
});

test('a lead already waiting is measured against the number in force when the timer looks', function () {
    $this->travelTo('2026-10-14 15:00:00');

    expect(tick()['released'])->toBe(0);

    saveQueueSettings(['cancelled_release_days' => 7]);

    expect(tick()['released'])->toBe(1);
});

test('a lead whose salesperson is gone is still released, with nobody to tell or to take it back', function () {
    $this->lead->forceFill(['assigned_user_id' => null, 'assigned_at' => null])->save();
    LeadAssignment::query()->where('lead_id', $this->lead->id)->whereNull('ended_at')->update(['ended_at' => now(), 'end_reason' => AssignmentEndReason::AccessRemoved->value]);

    Notification::fake();

    $this->travelTo('2026-10-21 15:00:00');

    expect(tick()['released'])->toBe(1);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($this->lead->fresh()->released_from_user_id)->toBeNull();
    Notification::assertNothingSent();
});

/*
|--------------------------------------------------------------------------
| Telling the salesperson (decision 118)
|--------------------------------------------------------------------------
*/

test('the salesperson is warned once, five days before, and told again at the release', function () {
    Notification::fake();

    // Nine days in: more than five days left.
    $this->travelTo('2026-10-16 14:59:00');

    expect(tick()['warned'])->toBe(0);
    Notification::assertNothingSent();

    $this->travelTo('2026-10-16 15:00:00');

    expect(tick()['warned'])->toBe(1);
    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, function (AppointmentChanged $notice) {
        expect($notice->kind)->toBe(AppointmentChanged::RELEASE_WARNING);
        expect($notice->channel)->toBe(AppointmentChanged::IN_APP);
        expect($notice->toArray($this->sam)['message'])->toBe('A cancelled lead of yours goes back to the Contact Center in 5 days unless it is booked again.');

        return true;
    });

    // Not again the next minute, nor the next day.
    $this->travelTo('2026-10-17 15:00:00');

    expect(tick()['warned'])->toBe(0);
    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);

    $this->travelTo('2026-10-21 15:00:00');

    $done = tick();

    expect($done['warned'])->toBe(0);
    expect($done['released'])->toBe(1);
    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::LEAD_RELEASED && $notice->channel === AppointmentChanged::IN_APP);
});

test('a waiting period of five days or fewer has no warning', function () {
    saveQueueSettings(['cancelled_release_days' => 5]);
    Notification::fake();

    $this->travelTo('2026-10-10 15:00:00');

    expect(tick()['warned'])->toBe(0);
    Notification::assertNothingSent();
});

test('the days left count a part of a day as a day', function () {
    expect(ReleaseCancelledLead::daysLeft($this->lead, 14, now()))->toBe(14);

    $this->travelTo('2026-10-08 15:00:01');

    expect(ReleaseCancelledLead::daysLeft($this->lead, 14, now()))->toBe(13);

    $this->travelTo('2026-10-21 14:00:00');

    expect(ReleaseCancelledLead::daysLeft($this->lead, 14, now()))->toBe(1);

    $this->travelTo('2026-10-22 09:00:00');

    expect(ReleaseCancelledLead::daysLeft($this->lead, 14, now()))->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The Cancelled Appointments queue (decision 117)
|--------------------------------------------------------------------------
*/

test('each button hands out only its own queue, and the newest return comes first', function () {
    // A second cancelled Lead, cancelled a day later, so released a day later.
    $second = bookableLead($this->lakeview);
    bookAppointment($second, $this->riley, $this->manager, '2026-10-12', '10:00');
    $this->travelTo('2026-10-08 15:00:00');
    cancelAppointment($second->fresh(), $this->agent);

    $this->travelTo('2026-10-21 15:00:00');
    tick();
    $this->travelTo('2026-10-22 15:00:00');
    tick();

    $new = dueLead($this->lakeview);
    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);

    $others = User::factory()->count(3)->withRoleEverywhere(Role::CallCenterAgent)->create();

    // "Next lead" gives the ordinary Lead and then nothing: the cancelled ones are not its to give.
    expect(nextLead($this->agent)->lead->id)->toBe($new->id);
    expect(nextLead($others[0])->given())->toBeFalse();

    // The other button gives the one that came back most recently first.
    expect(nextLead($others[1], CallQueue::CancelledAppointments)->lead->id)->toBe($second->id);
    expect(nextLead($others[2], CallQueue::CancelledAppointments)->lead->id)->toBe($this->lead->id);
    expect(nextLead($others[0], CallQueue::CancelledAppointments)->given())->toBeFalse();
});

test('a person holds one lead at a time, whichever button it came from', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    $new = dueLead($this->lakeview);
    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);

    expect(nextLead($this->agent)->lead->id)->toBe($new->id);

    $again = nextLead($this->agent, CallQueue::CancelledAppointments);

    expect($again->alreadyHeld)->toBeTrue();
    expect($again->lead->id)->toBe($new->id);
});

test('the counts are kept apart', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    dueLead($this->lakeview);
    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);

    $queue = app(DueQueue::class);

    expect($queue->dueNowCount($this->agent, now()))->toBe(1);
    expect($queue->dueNowCount($this->agent, now(), CallQueue::CancelledAppointments))->toBe(1);

    $summary = $queue->summary($queue->managerScope($this->leader), now());

    expect($summary['dueNow'])->toBe(1);
    expect($summary['cancelledDueNow'])->toBe(1);
});

test('booking a released lead is a new issue and takes it out of the queue', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    bookAppointment($this->lead->fresh(), $this->riley, $this->agent, '2026-10-26', '10:00');

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->riley->id);
    expect($lead->returned_from_sales_at)->toBeNull();
    expect($lead->released_from_user_id)->toBeNull();
    expect($lead->released_from_sales_at)->toBeNull();

    // A re-issue, with full history (rule 8): both issues are kept.
    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(2);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->state)->toBe(CadenceState::AppointmentBooked);
});

/*
|--------------------------------------------------------------------------
| Take back (rule 12, decision 120)
|--------------------------------------------------------------------------
*/

test('the salesperson a lead was released from takes it back: theirs again, to book, with no new issued count', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    // Two months later, as in the rule's own example. There is no time limit.
    $this->travelTo('2026-12-21 15:00:00');

    // An agent is holding it in the queue at that moment.
    LeadCadence::query()->where('lead_id', $this->lead->id)->update(['due_at' => now()->subMinute()]);
    expect(nextLead($this->agent, CallQueue::CancelledAppointments)->lead->id)->toBe($this->lead->id);

    takeBack($this->lead->fresh(), $this->sam);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->returned_from_sales_at)->toBeNull();
    expect($lead->released_from_user_id)->toBeNull();
    expect($lead->released_from_sales_at)->toBeNull();
    expect($lead->status_changed_at->toDateTimeString())->toBe('2026-12-21 15:00:00');

    // Rule 12: same history, no new issued count.
    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(1);
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    // It left the call queue, and the agent's hold ended with the reason.
    $cadence = LeadCadence::query()->where('lead_id', $lead->id)->sole();

    expect($cadence->isInQueue())->toBeFalse();
    expect($cadence->held_by_user_id)->toBeNull();
    expect(queueHistory($lead))->toContain('hold_ended:taken_back_by_sales');
    expect(queueHistory($lead))->toContain('ended:taken_back_by_sales');

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::TakenBackBySales);
    expect($change->from_status)->toBe(LeadStatus::Qualified);
    expect($change->to_status)->toBe(LeadStatus::AppointmentCancelled);
    expect($change->note)->toBe('The homeowner called me back.');

    $event = AuditEvent::query()->where('action', AuditAction::LeadTakenBackBySales)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->reason)->toBe('The homeowner called me back.');
    expect($event->values_after)->toMatchArray(['status' => 'appointment_cancelled', 'salesperson' => 'Sam Sales']);
    expect(CustomerActivity::label(AuditAction::LeadTakenBackBySales))->toBe('Lead taken back by its salesperson');

    // They can then book it for themselves, still with no new count.
    bookAppointment($lead, $this->sam, $this->sam, '2026-12-28', '10:00');

    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

test('the waiting period starts again when a lead is taken back', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    $this->travelTo('2026-10-23 15:00:00');
    takeBack($this->lead->fresh(), $this->sam);

    // Not released again a minute later, though its cancellation is long past.
    expect(tick()['released'])->toBe(0);

    $this->travelTo('2026-11-06 14:59:00');

    expect(tick()['released'])->toBe(0);

    $this->travelTo('2026-11-06 15:00:00');

    expect(tick()['released'])->toBe(1);
    expect($this->lead->fresh()->released_from_user_id)->toBe($this->sam->id);
});

test('who may take a released lead back', function (Closure $person) {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    expect(fn () => takeBack($this->lead->fresh(), $person($this)))->toThrow(AuthorizationException::class);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect($this->lead->fresh()->assigned_user_id)->toBeNull();
})->with([
    'another Sales Consultant there' => [fn ($test) => $test->riley],
    'a Call Center Agent' => [fn ($test) => $test->agent],
    'the Location Manager' => [fn ($test) => $test->manager],
]);

test('taking back needs a note', function () {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    expect(fn () => takeBack($this->lead->fresh(), $this->sam, '  '))
        ->toThrow(ValidationException::class, 'Say briefly why you are taking this lead back.');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
});

test('a lead cannot be taken back once it is no longer waiting with the contact center for them', function (Closure $change) {
    $this->travelTo('2026-10-21 15:00:00');
    tick();

    $change($this);

    expect(fn () => takeBack($this->lead->fresh(), $this->sam))->toThrow(AuthorizationException::class);
})->with([
    'it was booked with someone else (a manager must move it, rule 7)' => [fn ($test) => bookAppointment($test->lead->fresh(), $test->riley, $test->agent, '2026-10-26', '10:00')],
    'it was disqualified' => [fn ($test) => app(DisqualifyLead::class)->handle($test->lead->fresh(), LeadDisqualificationReason::factory()->create(), 'Not real.', $test->manager)],
    'the salesperson lost their access there' => [fn ($test) => $test->sam->accessGrants()->delete()],
]);

test('a lead a manager sent back cannot be taken back', function () {
    app(SendLeadBackToContactCenter::class)->handle($this->lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'Sam could not reach them.', $this->manager);

    expect($this->lead->fresh()->released_from_user_id)->toBeNull();
    expect(fn () => takeBack($this->lead->fresh(), $this->sam))->toThrow(AuthorizationException::class);
});

test('a lead that was never released cannot be taken, by anyone who may not already have it', function () {
    $other = bookableLead($this->lakeview);

    expect(fn () => takeBack($other->fresh(), $this->sam))->toThrow(AuthorizationException::class);
    expect($other->fresh()->assigned_user_id)->toBeNull();
});
