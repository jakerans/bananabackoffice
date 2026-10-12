<?php

use App\Actions\Appointments\CloseOutAppointment;
use App\Actions\Appointments\CorrectAppointmentCloseOut;
use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Actions\Appointments\RemindToCloseOutAppointment;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
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
use App\Models\LeadDisqualificationReason;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\User;
use App\Notifications\AppointmentChanged;
use App\Support\Appointments\SalesReturn;
use App\Support\Customers\CustomerActivity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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

test('answering an earlier appointment while a later one stands leaves the lead as it is', function () {
    // Since decision 126 a second cannot be booked before the first is answered. A record from before it can look like this.
    $this->travelTo('2026-10-09 14:00:00');
    $second = Appointment::factory()->forLead($this->lead)->with($this->sam)->at(Carbon::parse('2026-10-12 14:00:00'))->create(['follows_appointment_id' => $this->appointment->id]);

    closeOut($this->lead->fresh(), $this->sam, AppointmentState::NoShow, appointment: $this->appointment);

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($second->fresh()->state)->toBe(AppointmentState::Booked);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

/*
|--------------------------------------------------------------------------
| Close out first (decision 126)
|--------------------------------------------------------------------------
|
| The appointment ends at 4:30 pm Eastern on Thursday 8 October (20:30 UTC).
*/

test('once an appointment is over, nothing else can be done with the lead until someone says what happened', function () {
    $this->travelTo('2026-10-08 20:30:00');

    $lead = $this->lead->fresh();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $harbor = Location::factory()->create(['name' => 'Harbor']);
    $refusal = 'Say what happened at this lead\'s last appointment first: run or no-show.';

    expect($lead->hasAppointmentToCloseOut())->toBeTrue();

    expect(fn () => bookAppointment($lead, $this->sam, $this->sam, '2026-10-13', '10:00'))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(SendLeadBackToContactCenter::class)->handle($lead, StopCountingLeadAsIssued::ANOTHER_REASON, 'Wants a call first.', $this->manager))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(RouteLead::class)->handle($lead, $harbor, 'Wrong office.', $owner))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(ReturnLeadToCentral::class)->handle($lead, 'Wrong office.', $owner))->toThrow(ValidationException::class, $refusal);
    expect(fn () => app(DisqualifyLead::class)->handle($lead, LeadDisqualificationReason::factory()->create(), 'Not real.', $owner))->toThrow(ValidationException::class, $refusal);

    $lead = $lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentBooked);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect($lead->location_id)->toBe($this->lakeview->id);
    expect(Appointment::query()->count())->toBe(1);

    // With the answer given, the Lead is free again.
    closeOut($lead, $this->sam);

    expect($lead->fresh()->hasAppointmentToCloseOut())->toBeFalse();
    expect(bookAppointment($lead->fresh(), $this->sam, $this->sam, '2026-10-13', '10:00')->appointment->follows_appointment_id)->toBe($this->appointment->id);
});

test('while the appointment is still going on it can be answered and is not yet overdue', function () {
    $this->travelTo('2026-10-08 20:29:00');

    expect($this->lead->fresh()->hasAppointmentToCloseOut())->toBeFalse();
    expect($this->appointment->fresh()->awaitsCloseOutAt(now()))->toBeTrue();
});

test('a salesperson closes out their appointments in order, and a manager is not held to it', function () {
    $later = bookableLead($this->lakeview);
    $this->travelTo('2026-10-08 20:00:00');
    $second = bookAppointment($later, $this->sam, $this->manager, '2026-10-08', '17:00', 30)->appointment;

    // Both are over: 4:30 pm and 5:30 pm.
    $this->travelTo('2026-10-08 22:00:00');

    expect(fn () => closeOut($later->fresh(), $this->sam))
        ->toThrow(ValidationException::class, 'Close out your earlier appointment first: the one on Thu, Oct 8 at 3:00 PM EDT.');
    expect($second->fresh()->state)->toBe(AppointmentState::Booked);

    // Someone else's unanswered appointment does not hold Sam's up, and a manager may answer any.
    closeOut($later->fresh(), $this->manager, AppointmentState::NoShow);
    expect($second->fresh()->state)->toBe(AppointmentState::NoShow);

    closeOut($this->lead->fresh(), $this->sam);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
});

test('an earlier unanswered appointment comes before the one the salesperson is at now', function () {
    $later = bookableLead($this->lakeview);
    $this->travelTo('2026-10-08 20:00:00');
    bookAppointment($later, $this->sam, $this->manager, '2026-10-08', '17:00', 30);

    // The first ended at 4:30 pm; the second started at 5:00 pm.
    $this->travelTo('2026-10-08 21:05:00');

    expect(fn () => closeOut($later->fresh(), $this->sam))->toThrow(ValidationException::class, 'Close out your earlier appointment first');

    closeOut($this->lead->fresh(), $this->sam);
    closeOut($later->fresh(), $this->sam);

    expect($later->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

/*
|--------------------------------------------------------------------------
| The reminder, fifteen minutes after the appointment ends (decision 126)
|--------------------------------------------------------------------------
*/

test('fifteen minutes after an appointment ends its salesperson is reminded once, in the app and by email', function () {
    Notification::fake();

    $this->travelTo('2026-10-08 20:44:00');
    expect(app(RunQueueTick::class)->handle()['reminded'])->toBe(0);
    Notification::assertNothingSent();

    $this->travelTo('2026-10-08 20:45:00');
    expect(app(RunQueueTick::class)->handle()['reminded'])->toBe(1);
    expect($this->appointment->fresh()->close_out_reminded_at?->toDateTimeString())->toBe('2026-10-08 20:45:00');

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
    Notification::assertNotSentTo($this->manager, AppointmentChanged::class);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::CLOSE_OUT_REMINDER && $notice->channel === AppointmentChanged::IN_APP);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::CLOSE_OUT_REMINDER && $notice->channel === AppointmentChanged::EMAIL);

    // Never a second time, however long it stays unanswered.
    $this->travelTo('2026-10-09 14:00:00');
    expect(app(RunQueueTick::class)->handle()['reminded'])->toBe(0);
    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 2);
});

test('the reminder says what to do and carries nothing about the customer', function () {
    $this->travelTo('2026-10-08 20:45:00');

    $notice = new AppointmentChanged($this->lead->id, AppointmentChanged::CLOSE_OUT_REMINDER, AppointmentChanged::EMAIL, $this->appointment);
    $mail = $notice->toMail($this->sam);
    $text = implode(' ', [$mail->subject, $mail->greeting, ...$mail->introLines, ...$mail->outroLines, $mail->actionText, $mail->actionUrl]);

    expect($mail->subject)->toStartWith('Close out your appointment in ');
    expect($mail->actionText)->toBe('Close it out');
    expect($text)->toContain('Your appointment on Thu, Oct 8, 2026 at 3:00 PM EDT is over. Say whether it was run or was a no-show.');
    expect($text)->toContain(route('leads.show', $this->lead->id));
    expect($text)->not->toContain('Pat Customer');
    expect($text)->not->toContain((string) $this->lead->inquiry_phone);

    // The notice inside the app says the same and opens the Lead.
    app(RemindToCloseOutAppointment::class)->handle($this->appointment->id);

    $saved = $this->sam->notifications()->where('type', AppointmentChanged::class)->sole();

    expect($saved->data['message'])->toContain('is over. Say whether it was run or was a no-show.');
    expect(json_encode($saved->data))->not->toContain('Pat Customer');
});

test('nobody is reminded about an appointment that needs no answer', function (Closure $change) {
    Notification::fake();

    $change($this);

    $this->travelTo('2026-10-08 21:00:00');

    expect(app(RunQueueTick::class)->handle()['reminded'])->toBe(0);
    Notification::assertNotSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::CLOSE_OUT_REMINDER);
})->with([
    'already marked run' => [fn ($test) => closeOut($test->lead, $test->sam)],
    'already marked a no-show' => [fn ($test) => closeOut($test->lead, $test->sam, AppointmentState::NoShow)],
    'undone as booked in error' => [fn ($test) => app(MarkAppointmentBookedInError::class)->handle($test->lead, $test->appointment, 'Wrong customer.', $test->manager)],
    'its salesperson was deactivated' => [fn ($test) => $test->sam->forceFill(['is_active' => false])->save()],
]);

/*
|--------------------------------------------------------------------------
| The salesperson is told when a manager touches their appointment (decision 126)
|--------------------------------------------------------------------------
*/

test('the salesperson is told in the app when a manager says what happened, and not when they say it themselves', function (AppointmentState $outcome, string $kind, string $message) {
    Notification::fake();

    closeOut($this->lead, $this->manager, $outcome);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertNotSentTo($this->manager, AppointmentChanged::class);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, function (AppointmentChanged $notice) use ($kind, $message) {
        expect($notice->toArray($this->sam)['message'])->toBe($message);

        return $notice->kind === $kind && $notice->channel === AppointmentChanged::IN_APP;
    });
})->with([
    'run' => [AppointmentState::Run, AppointmentChanged::MARKED_RUN, 'A manager marked your appointment on Thu, Oct 8, 2026 at 3:00 PM EDT as run.'],
    'no-show' => [AppointmentState::NoShow, AppointmentChanged::MARKED_NO_SHOW, 'A manager marked your appointment on Thu, Oct 8, 2026 at 3:00 PM EDT as a no-show.'],
]);

test('the salesperson is not told about an answer they gave themselves', function () {
    Notification::fake();

    closeOut($this->lead, $this->sam);

    Notification::assertNothingSent();
});

test('the salesperson is told in the app when a manager corrects the answer', function () {
    closeOut($this->lead, $this->sam);

    Notification::fake();

    app(CorrectAppointmentCloseOut::class)->handle($this->lead->fresh(), $this->appointment->fresh(), 'Nobody was home after all.', $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::CLOSE_OUT_CORRECTED
        && $notice->channel === AppointmentChanged::IN_APP
        && $notice->toArray($this->sam)['message'] === 'A manager corrected what happened at your appointment on Thu, Oct 8, 2026 at 3:00 PM EDT.');
});

test('the salesperson is told in the app when a manager marks their appointment booked in error', function () {
    Notification::fake();

    app(MarkAppointmentBookedInError::class)->handle($this->lead, $this->appointment, 'Wrong customer.', $this->manager);

    Notification::assertSentToTimes($this->sam, AppointmentChanged::class, 1);
    Notification::assertSentTo($this->sam, AppointmentChanged::class, fn (AppointmentChanged $notice) => $notice->kind === AppointmentChanged::BOOKED_IN_ERROR && $notice->channel === AppointmentChanged::IN_APP);
});

/*
|--------------------------------------------------------------------------
| The prompt on every page (decision 126)
|--------------------------------------------------------------------------
*/

test('every page tells a salesperson about appointments of theirs that are over and unanswered', function () {
    // Still going on: nothing yet.
    $this->actingAs($this->sam)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="close-out-prompt"', false);

    $this->travelTo('2026-10-08 20:30:00');

    $this->actingAs($this->sam)->get(route('sales.calendar'))
        ->assertOk()
        ->assertSee('data-test="close-out-prompt"', false)
        ->assertSee('You have 1 appointment to close out.')
        ->assertSee('Close out now');

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))->assertSee('You have 1 appointment to close out.');

    // It is theirs alone: nobody else is prompted about it.
    $this->actingAs($this->riley)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="close-out-prompt"', false);
    $this->actingAs($this->manager)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="close-out-prompt"', false);

    // The link opens the oldest one's Lead, and the prompt goes as soon as the card says the answer was given.
    Livewire::actingAs($this->sam)->test('pages::sales.close-out-prompt')
        ->assertSeeHtml('href="'.route('leads.show', $this->lead).'"')
        ->tap(fn () => closeOut($this->lead->fresh(), $this->sam))
        ->dispatch('lead-appointment-changed')
        ->assertDontSeeHtml('data-test="close-out-prompt"');

    $this->actingAs($this->sam)->get(route('sales.calendar'))->assertOk()->assertDontSee('data-test="close-out-prompt"', false);
});

test('the prompt counts every appointment waiting and opens the oldest first', function () {
    $later = bookableLead($this->lakeview);
    $this->travelTo('2026-10-08 20:00:00');
    bookAppointment($later, $this->sam, $this->manager, '2026-10-08', '17:00', 30);
    $this->travelTo('2026-10-08 22:00:00');

    Livewire::actingAs($this->sam)->test('pages::sales.close-out-prompt')
        ->assertSee('You have 2 appointments to close out.')
        ->assertSeeHtml('href="'.route('leads.show', $this->lead).'"')
        ->assertDontSee('Pat Customer');
});

test('the lead page tells a manager why an unanswered lead cannot be moved, returned or disqualified', function (string $opener) {
    $this->travelTo('2026-10-09 14:00:00');

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    // No dialog whose save would be refused: the person is told why instead.
    Livewire::actingAs($owner)->test('pages::leads.show', ['lead' => $this->lead->fresh()])
        ->call($opener)
        ->assertDispatched('toast-show', fn (string $event, array $with): bool => str_contains((string) json_encode($with), 'last appointment first: run or no-show.'));

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->lead->fresh()->location_id)->toBe($this->lakeview->id);
})->with(['openRouting', 'openReturn', 'openDisqualify']);

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
