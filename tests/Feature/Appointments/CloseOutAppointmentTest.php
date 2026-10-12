<?php

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Appointments\CorrectAppointmentCloseOut;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Queue\RunQueueTick;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CallQueue;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Support\Appointments\SalesReturn;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Saying what happened to an appointment once its time has come: run, or
 * no-show (docs/slice-7-appointment-results-plan.md, step 7a; decisions 121,
 * 123, 124 and 125).
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern (19:00
 * UTC), 90 minutes, with Sam Sales. Tests start at 3:00 pm that day, as it
 * begins, unless they say otherwise.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 19:00:00');
    $this->lead = $this->lead->fresh();
});

function closeOut(Lead $lead, User $by, AppointmentState|string|null $outcome = AppointmentState::Run, ?string $note = null, ?Appointment $appointment = null): void
{
    app(CloseOutAppointment::class)->handle(
        $lead,
        $appointment ?? $lead->standingAppointment() ?? $lead->appointments()->firstOrFail(),
        $outcome instanceof AppointmentState ? $outcome->value : $outcome,
        $note,
        $by,
    );
}

/*
|--------------------------------------------------------------------------
| Run
|--------------------------------------------------------------------------
*/

test('marking an appointment run records it, and the lead shows appointment run with its salesperson', function () {
    closeOut($this->lead, $this->sam, AppointmentState::Run, 'Walked the whole exterior.');

    $appointment = $this->appointment->fresh();
    $lead = $this->lead->fresh();

    expect($appointment->state)->toBe(AppointmentState::Run);
    expect($appointment->stands())->toBeFalse();
    expect($appointment->state_changed_by)->toBe($this->sam->id);
    expect($appointment->state_changed_by_name)->toBe('Sam Sales');
    expect($appointment->state_changed_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect($appointment->state_note)->toBe('Walked the whole exterior.');

    // Decision 125: Appointment run, still with the salesperson, still issued.
    expect($lead->status)->toBe(LeadStatus::AppointmentRun);
    expect($lead->isWithSales())->toBeTrue();
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    $change = LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail();

    expect($change->event)->toBe(LeadStatusEvent::AppointmentRun);
    expect($change->from_status)->toBe(LeadStatus::AppointmentBooked);
    expect($change->to_status)->toBe(LeadStatus::AppointmentRun);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentRun)->sole();

    expect($event->actor_id)->toBe($this->sam->id);
    expect($event->reason)->toBe('Walked the whole exterior.');
    expect($event->values_after)->toMatchArray(['status' => 'appointment_run', 'appointment' => 'run', 'salesperson' => 'Sam Sales']);
    expect(CustomerActivity::label(AuditAction::LeadAppointmentRun))->toBe('Appointment run');
});

test('a run appointment has no result of its own (decision 124)', function () {
    closeOut($this->lead, $this->sam);

    // Nothing about Sold, Pending or Lost is kept on an appointment.
    expect(array_keys($this->appointment->fresh()->getAttributes()))->not->toContain('result', 'outcome');
    expect(AppointmentState::Run->label())->toBe('Run');
});

test('after a run appointment the lead can be booked again with no new issued count, sent back, or disqualified', function () {
    closeOut($this->lead, $this->sam);

    $another = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;

    expect($another->follows_appointment_id)->toBe($this->appointment->id);
    expect($another->previous_status)->toBe(LeadStatus::AppointmentRun);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->count())->toBe(1);

    $second = bookableLead($this->lakeview);
    bookAppointment($second, $this->riley, $this->manager, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 15:00:00');
    closeOut($second->fresh(), $this->riley);

    app(SendLeadBackToContactCenter::class)->handle($second->fresh(), StopCountingLeadAsIssued::ANOTHER_REASON, 'They want to wait a year.', $this->manager);

    expect($second->fresh()->status)->toBe(LeadStatus::Qualified);

    // Its appointment was run, not cancelled, so it goes back to the ordinary queue.
    expect(LeadCadence::query()->where('lead_id', $second->id)->sole()->queue)->toBe(CallQueue::Main);
    expect(SalesReturn::of($second->fresh())->appointment)->toBe('Its appointment on Fri, Oct 9 at 10:00 AM EDT with Riley Rep was run.');
});

/*
|--------------------------------------------------------------------------
| No-show (decision 125, answer 2)
|--------------------------------------------------------------------------
*/

test('a no-show is treated as a cancellation by the customer: the lead stays with its salesperson to be booked again', function () {
    closeOut($this->lead, $this->sam, AppointmentState::NoShow, 'Nobody home.');

    $appointment = $this->appointment->fresh();
    $lead = $this->lead->fresh();

    expect($appointment->state)->toBe(AppointmentState::NoShow);
    expect($appointment->fellThrough())->toBeTrue();
    expect($appointment->state_note)->toBe('Nobody home.');

    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->status_changed_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
    expect(LeadIssue::query()->counted()->count())->toBe(1);

    expect(LeadStatusChange::query()->where('lead_id', $lead->id)->orderByDesc('id')->firstOrFail()->event)->toBe(LeadStatusEvent::AppointmentNoShow);
    expect(AuditEvent::query()->where('action', AuditAction::LeadAppointmentNoShow)->sole()->values_after)
        ->toMatchArray(['status' => 'appointment_cancelled', 'appointment' => 'no_show']);

    // It can be booked again with the same salesperson, with no new issued count.
    bookAppointment($lead, $this->sam, $this->agent, '2026-10-12', '10:00');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->count())->toBe(1);
});

test('a no-show that is not booked again is released to the cancelled appointments queue, and the card says it was a no-show', function () {
    closeOut($this->lead, $this->sam, AppointmentState::NoShow);

    $this->travelTo('2026-10-22 18:59:00');

    expect(app(RunQueueTick::class)->handle()['released'])->toBe(0);

    $this->travelTo('2026-10-22 19:00:00');

    expect(app(RunQueueTick::class)->handle()['released'])->toBe(1);

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->released_from_user_id)->toBe($this->sam->id);
    expect(LeadCadence::query()->where('lead_id', $lead->id)->sole()->queue)->toBe(CallQueue::CancelledAppointments);

    $return = SalesReturn::of($lead);

    expect($return->headline)->toBe('No-show: this lead came back from Sales');
    expect($return->appointmentWasCancelled)->toBeTrue();
    expect($return->appointment)->toBe('The customer was a no-show for the appointment on Thu, Oct 8 at 3:00 PM EDT with Sam Sales.');
    expect($return->note)->toBe('Not booked again within 14 days of the no-show.');
});

/*
|--------------------------------------------------------------------------
| Who, and when
|--------------------------------------------------------------------------
*/

test('who may say what happened', function (Closure $person, bool $allowed) {
    $actor = $person($this);

    if ($allowed) {
        closeOut($this->lead, $actor);

        expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);

        return;
    }

    expect(fn () => closeOut($this->lead, $actor))->toThrow(AuthorizationException::class);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
})->with([
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam, true],
    'the Location Manager there' => [fn ($test) => $test->manager, true],
    'the Owner' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Owner)->create(), true],
    'a Call Center Agent' => [fn ($test) => $test->agent, false],
    'another Sales Consultant there' => [fn ($test) => $test->riley, false],
    'a Location Manager somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create(), false],
]);

test('an appointment cannot be marked before it starts, and can from the minute it does', function () {
    $this->travelTo('2026-10-08 18:59:00');

    expect(fn () => closeOut($this->lead, $this->sam))
        ->toThrow(ValidationException::class, 'This appointment has not started yet. To call it off, cancel it.');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);

    $this->travelTo('2026-10-08 19:00:00');

    closeOut($this->lead, $this->sam);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
});

test('the answer must be run or no-show, and can be given once', function () {
    foreach ([null, '', 'cancelled', 'booked', 'sold'] as $refused) {
        expect(fn () => closeOut($this->lead, $this->sam, $refused))
            ->toThrow(ValidationException::class, 'Say whether the appointment was run or was a no-show.');
    }

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);

    closeOut($this->lead, $this->sam);

    expect(fn () => closeOut($this->lead->fresh(), $this->manager, AppointmentState::NoShow, appointment: $this->appointment))
        ->toThrow(ValidationException::class, 'This appointment is no longer waiting for an answer.');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
});

test('a cancelled appointment cannot be marked run or no-show', function () {
    $this->travelTo('2026-10-08 18:00:00');
    cancelAppointment($this->lead, $this->manager);
    $this->travelTo('2026-10-08 19:30:00');

    expect(fn () => closeOut($this->lead->fresh(), $this->manager, appointment: $this->appointment))
        ->toThrow(ValidationException::class, 'This appointment is no longer waiting for an answer.');
});

test('a lead that changed since the dialog opened is refused', function () {
    expect(fn () => app(CloseOutAppointment::class)->handle($this->lead, $this->appointment, 'run', null, $this->sam, $this->lead->lifecycle_version - 1))
        ->toThrow(ValidationException::class, 'This lead was just changed by someone else');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

test('answering an earlier appointment after a later one was booked leaves the lead as it is', function () {
    // The first has passed and a second is booked before anyone answered the first (decision 113).
    $this->travelTo('2026-10-09 14:00:00');
    $second = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;

    closeOut($this->lead->fresh(), $this->sam, AppointmentState::NoShow, appointment: $this->appointment);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($second->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

/*
|--------------------------------------------------------------------------
| Correcting an answer
|--------------------------------------------------------------------------
*/

test('a manager corrects a run appointment to a no-show, and back, with a note', function () {
    closeOut($this->lead, $this->sam);

    $this->travelTo('2026-10-08 21:00:00');

    app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment, 'Sam marked the wrong lead.', $this->manager);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($this->appointment->fresh()->state_changed_by)->toBe($this->manager->id);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);

    $event = AuditEvent::query()->where('action', AuditAction::LeadAppointmentCloseOutCorrected)->sole();

    expect($event->reason)->toBe('Sam marked the wrong lead.');
    expect($event->values_before)->toMatchArray(['status' => 'appointment_run', 'appointment' => 'run']);
    expect($event->values_after)->toMatchArray(['status' => 'appointment_cancelled', 'appointment' => 'no_show']);

    app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment, 'It was right the first time.', $this->manager);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('only a manager may correct, a note is required, and only an answered appointment can be corrected', function () {
    expect(fn () => app(CorrectAppointmentCloseOut::class)->handle($this->lead, $this->appointment, 'Not answered yet.', $this->manager))
        ->toThrow(ValidationException::class, 'This appointment has not been marked run or no-show');

    closeOut($this->lead, $this->sam);

    expect(fn () => app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment, 'I got it wrong.', $this->sam))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment, '  ', $this->manager))
        ->toThrow(ValidationException::class, 'Say why this is being corrected.');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
});

test('a correction changes only the appointment once the lead has moved on', function () {
    closeOut($this->lead, $this->sam);
    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00');

    app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment, 'It was a no-show after all.', $this->manager);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

/*
|--------------------------------------------------------------------------
| The screens
|--------------------------------------------------------------------------
*/

test('the card asks what happened once the time has come, and only of the people who may answer', function () {
    $this->travelTo('2026-10-08 18:00:00');

    appointmentCard($this->sam, $this->lead)->assertDontSeeHtml('data-test="close-out"');

    $this->travelTo('2026-10-08 19:00:00');

    appointmentCard($this->sam, $this->lead)
        ->assertSeeHtml('data-test="close-out"')
        ->assertSee('The time of the appointment on Thu, Oct 8 at 3:00 PM EDT has come. What happened?')
        ->assertSeeHtml('data-test="close-out-run"')
        ->assertSeeHtml('data-test="close-out-no-show"');

    appointmentCard($this->agent, $this->lead)
        ->assertSeeHtml('data-test="close-out-not-yours"')
        ->assertDontSeeHtml('data-test="close-out-run"')
        ->call('openCloseOut', 'run')
        ->assertForbidden();
});

test('the salesperson marks the appointment run from the card', function () {
    appointmentCard($this->sam, $this->lead)
        ->call('openCloseOut', 'run')
        ->assertSee('Mark this appointment as run?')
        ->set('closeOutNote', 'Walked the whole exterior.')
        ->call('closeOut')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSeeHtml('data-test="appointment-closed-out"')
        ->assertSee('Appointment was run')
        ->assertSee('Walked the whole exterior.')
        ->assertDontSeeHtml('data-test="close-out"')
        ->assertSee('Book another appointment')
        // Only a manager is offered the correction.
        ->assertDontSeeHtml('data-test="correct-close-out"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('a manager marks a no-show from the card and can correct it there', function () {
    $card = appointmentCard($this->manager, $this->lead)
        ->call('openCloseOut', 'no_show')
        ->assertSee('Mark this appointment a no-show?')
        ->call('closeOut')
        ->assertHasNoErrors()
        ->assertSee('No-show')
        ->assertSee('The lead stays with its salesperson to be booked again, as after a cancellation.')
        ->assertSee('Book again')
        ->assertSeeHtml('data-test="correct-close-out"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);

    $card->call('openCorrectCloseOut')
        ->call('correctCloseOut')
        ->assertHasErrors('correctionNote')
        ->set('correctionNote', 'They were there after all.')
        ->call('correctCloseOut')
        ->assertHasNoErrors()
        ->assertSee('Appointment was run');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('the card refuses an answer that is not run or no-show, whatever the browser sends', function () {
    appointmentCard($this->sam, $this->lead)->call('openCloseOut', 'sold')->assertNotFound();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

test('the calendar lists appointments waiting to be closed out, to the people who may see them', function () {
    $rileys = bookableLead($this->lakeview);
    $rileys->account->forceFill(['display_name' => 'Riley Customer'])->save();
    $this->travelTo('2026-10-08 18:00:00');
    bookAppointment($rileys, $this->riley, $this->manager, '2026-10-08', '14:30', 30);
    $this->travelTo('2026-10-08 19:00:00');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSeeHtml('data-test="calendar-to-close-out"')
        ->assertSee('To close out')
        ->assertSee('Pat Customer')
        ->assertDontSee('Riley Customer');

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSeeInOrder(['To close out', 'Riley Customer', 'Pat Customer'])
        ->assertSeeHtml('data-test="calendar-close-out-salesperson"');

    closeOut($this->lead, $this->sam);
    closeOut($rileys->fresh(), $this->riley, AppointmentState::NoShow);

    // Answered: gone from the list. The no-show waits under "To rebook".
    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-to-close-out"')
        ->assertSeeHtml('data-test="calendar-to-rebook"')
        ->assertSee('Riley Customer')
        ->assertSee('No-show');
});

test('the customer page shows whether each appointment was run or a no-show', function () {
    closeOut($this->lead, $this->sam);

    Livewire::actingAs($this->manager)->test('pages::customers.show', ['account' => $this->lead->account])
        ->assertSee('Thu, Oct 8, 2026')
        ->assertSee('Run')
        ->assertDontSee('Time has passed');
});

test('the timer takes a lead whose appointment was run out of the call queue if it is still in it', function () {
    closeOut($this->lead, $this->sam);

    $cadence = enterQueue($this->lead->fresh());

    expect($cadence->isInQueue())->toBeTrue();

    app(RunQueueTick::class)->handle();

    expect($cadence->fresh()->isInQueue())->toBeFalse();
});
