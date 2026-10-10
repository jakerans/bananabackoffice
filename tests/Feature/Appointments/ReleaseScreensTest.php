<?php

use App\Actions\Queue\RunQueueTick;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ContactCenterSettings;
use App\Models\LeadCadence;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

/*
 * The screens of step 6c (docs/slice-6-appointments-plan.md): the second
 * button on the Call Queue, "Take this lead back" on the Appointment card, the
 * days left and the released list in Sales > Calendar, and the number of days
 * on Contact Center settings.
 *
 * The clock starts on Wednesday 7 October 2026. The appointment with Sam Sales
 * is cancelled at 11:00 am Eastern that day; the waiting period is 14 days.
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
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    enterQueue($this->lead);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-07 15:00:00');
    cancelAppointment($this->lead->fresh(), $this->agent);
    $this->lead = $this->lead->fresh();
});

/**
 * Let the waiting period run out and the timer release the Lead.
 */
function releaseByTimer($test): void
{
    $test->travelTo('2026-10-21 15:00:00');

    app(RunQueueTick::class)->handle();

    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);
}

/*
|--------------------------------------------------------------------------
| The Call Queue
|--------------------------------------------------------------------------
*/

test('the call queue has a second button with its own count', function () {
    releaseByTimer($this);
    dueLead($this->lakeview);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->assertSeeHtml('data-test="next-lead"')
        ->assertSeeHtml('data-test="next-cancelled-appointment"')
        ->assertSee('1 lead due now')
        ->assertSee('1 cancelled appointment due now')
        ->call('nextCancelledAppointment')
        ->assertSeeHtml('data-test="queue-card"')
        ->assertSee($this->lead->displayName())
        ->assertSee('Cancelled appointment: this lead came back from Sales')
        ->assertSee('Not booked again within 14 days of the cancellation.')
        // With a Lead on the card, both counts of what is left are still shown.
        ->assertSee('1 other lead due now')
        ->assertSee('0 cancelled appointments due now');
});

test('pressing the second button with nothing behind it gives no lead', function () {
    dueLead($this->lakeview);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->assertSee('0 cancelled appointments due now')
        ->call('nextCancelledAppointment')
        ->assertDontSeeHtml('data-test="queue-card"')
        ->assertSee('1 lead due now');
});

test('the manager summary counts cancelled appointments apart from the ordinary queue', function () {
    releaseByTimer($this);
    dueLead($this->lakeview);

    Livewire::actingAs($this->leader)->test('pages::marketing.call-queue')
        ->assertSeeHtml('data-test="summary-cancelled-due"')
        ->assertSee('1 cancelled appointment');
});

/*
|--------------------------------------------------------------------------
| Take back
|--------------------------------------------------------------------------
*/

test('only the salesperson a lead was released from is offered to take it back', function () {
    appointmentCard($this->sam, $this->lead)->assertDontSeeHtml('data-test="take-lead-back"');

    releaseByTimer($this);

    appointmentCard($this->sam, $this->lead->fresh())
        ->assertSeeHtml('data-test="take-lead-back"')
        ->assertSee('This lead was released from you because it was not booked again in time.');

    foreach ([$this->riley, $this->manager, $this->agent] as $other) {
        appointmentCard($other, $this->lead->fresh())
            ->assertDontSeeHtml('data-test="take-lead-back"')
            ->call('openTakeBack')
            ->assertForbidden();
    }
});

test('the salesperson takes the lead back from the card, with a note', function () {
    releaseByTimer($this);

    $card = appointmentCard($this->sam, $this->lead->fresh())->call('openTakeBack');

    $card->call('takeBack')
        ->assertHasErrors('takeBackNote')
        ->assertSee('Say briefly why you are taking this lead back.');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);

    $card->set('takeBackNote', 'The homeowner called me back.')
        ->call('takeBack')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertDontSeeHtml('data-test="take-lead-back"')
        ->assertDontSeeHtml('data-test="sales-return"')
        ->assertSee('Book again');

    $lead = $this->lead->fresh();

    expect($lead->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($lead->assigned_user_id)->toBe($this->sam->id);
    expect(LeadIssue::query()->where('lead_id', $lead->id)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Sales > Calendar
|--------------------------------------------------------------------------
*/

test('"to rebook" says how many days are left before a lead goes back', function () {
    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSee('Pat Customer')
        ->assertSee('Goes back to the Contact Center in 14 days unless it is booked again.');

    $this->travelTo('2026-10-20 16:00:00');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSee('Goes back to the Contact Center in 1 day unless it is booked again.');
});

test('a released lead moves to "released from you" until it is taken back or booked', function () {
    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertDontSeeHtml('data-test="calendar-released"');

    releaseByTimer($this);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-to-rebook"')
        ->assertSeeHtml('data-test="calendar-released"')
        ->assertSee('Released from you')
        ->assertSee('Pat Customer');

    // A colleague does not see it. A manager sees it, with whose it was.
    Livewire::actingAs($this->riley)->test('pages::sales.calendar')->assertDontSee('Pat Customer');

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSee('Released to the Contact Center')
        ->assertSee('Pat Customer')
        ->assertSee('Sam Sales');

    appointmentCard($this->sam, $this->lead->fresh())->call('openTakeBack')->set('takeBackNote', 'They called.')->call('takeBack');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-released"')
        ->assertSeeHtml('data-test="calendar-to-rebook"')
        ->assertSee('Goes back to the Contact Center in 14 days unless it is booked again.');
});

/*
|--------------------------------------------------------------------------
| Contact Center settings
|--------------------------------------------------------------------------
*/

test('the settings page shows the number of days and saves a new one', function () {
    Livewire::actingAs($this->leader)->test('pages::settings.contact-center')
        ->assertSeeHtml('data-test="release-section"')
        ->assertSet('releaseDays', '14')
        ->set('releaseDays', '21')
        ->call('saveReleaseDays')
        ->assertHasNoErrors()
        ->assertSet('releaseDays', '21');

    expect(ContactCenterSettings::current()->cancelled_release_days)->toBe(21);
    expect(AuditEvent::query()->where('action', AuditAction::ContactCenterReleaseDaysChanged)->count())->toBe(1);
});

test('a number of days outside 1 to 180 is refused at its field', function (string $days) {
    Livewire::actingAs($this->leader)->test('pages::settings.contact-center')
        ->set('releaseDays', $days)
        ->call('saveReleaseDays')
        ->assertHasErrors('releaseDays')
        ->assertSee('The number of days must be a whole number from 1 to 180.');

    expect(ContactCenterSettings::current()->cancelled_release_days)->toBe(14);
})->with(['0', '181', '-2', '2.5', 'a week', '']);

test('someone who may not change the settings cannot save the number of days', function () {
    Livewire::actingAs($this->manager)->test('pages::settings.contact-center')->assertForbidden();
});
