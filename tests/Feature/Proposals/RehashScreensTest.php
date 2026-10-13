<?php

use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Queue\RunQueueTick;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\LeadCadence;
use App\Models\Location;
use App\Models\Objection;
use App\Models\SalesLossReason;
use App\Models\User;
use Livewire\Livewire;

/*
 * Where people meet Rehash (docs/slice-9-pending-rehash-plan.md, step 9b):
 * the Call Queue's third button and its card, the Lead's Proposals and
 * Appointment cards, and Sales > Calendar.
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. "Whole exterior" is pending, valid until the 15th, with the
 * objection "Wants to think it over". Tests that need it released start at
 * 11:00 am on Friday the 16th, with the Lead's first rehash call due.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 20:35:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'pending', ['objectionId' => $this->thinking->id], $this->sam);
    $this->lead = $this->lead->fresh();
});

/**
 * Let the Lead's last day pass and the timer release it, with its first call due.
 */
function sendToRehash($test): void
{
    $test->travelTo('2026-10-16 15:00:00');

    app(RunQueueTick::class)->handle();

    LeadCadence::query()->where('state', CadenceState::InQueue)->update(['due_at' => now()->subMinute()]);

    $test->lead = $test->lead->fresh();
}

/*
|--------------------------------------------------------------------------
| The Call Queue
|--------------------------------------------------------------------------
*/

test('the call queue has a third button with its own count, and its card says what was pitched', function () {
    sendToRehash($this);
    dueLead($this->lakeview);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->assertSeeHtml('data-test="next-rehash"')
        ->assertSee('1 lead due now')
        ->assertSee('0 cancelled appointments due now')
        ->assertSee('1 rehash due now')
        ->call('nextRehash')
        ->assertSeeHtml('data-test="queue-card"')
        ->assertSee($this->lead->displayName())
        ->assertSee('Rehash: this lead has a bid that is still pending')
        ->assertSee('What was pitched:')
        ->assertSee('Whole exterior, $8,450. Still pending. Main objection: Wants to think it over. Expired yesterday, Thu, Oct 15.')
        ->assertSee('Nothing was sold, lost or booked before the last pending proposal expired on Oct 15, 2026.')
        // With a Lead on the card, the counts of what is left are still shown.
        ->assertSee('1 other lead due now')
        ->assertSee('0 rehashes due now');
});

test('the other buttons do not hand out a rehash, and the third gives nothing when there is none', function () {
    dueLead($this->lakeview);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->assertSee('0 rehashes due now')
        ->call('nextRehash')
        ->assertDontSeeHtml('data-test="queue-card"');

    sendToRehash($this);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->call('nextCancelledAppointment')
        ->assertDontSeeHtml('data-test="queue-card"')
        ->assertSee('1 rehash due now');
});

test('the manager summary counts rehashes apart from the ordinary queue', function () {
    sendToRehash($this);
    dueLead($this->lakeview);

    Livewire::actingAs($this->leader)->test('pages::marketing.call-queue')
        ->assertSeeHtml('data-test="summary-rehash-due"')
        ->assertSee('1 rehash');
});

/*
|--------------------------------------------------------------------------
| The Lead page
|--------------------------------------------------------------------------
*/

test('on the lead, the call center can record that the customer said no', function () {
    // While the Lead is with its salesperson the call center is offered nothing.
    proposalsCard($this->agent, $this->lead)->assertDontSeeHtml('data-test="rehash-lost"');

    sendToRehash($this);

    $card = proposalsCard($this->agent, $this->lead)
        ->assertSeeHtml('data-test="rehash-lost"')
        ->assertSee('The customer said no: mark lead lost')
        // A result on the proposal itself is Sales' to give, not the call center's.
        ->assertDontSeeHtml('data-test="proposal-sold"')
        ->call('openLost')
        ->call('markLost')
        ->assertHasErrors(['lossReasonId'])
        ->set('result.lossReasonId', $this->price->id)
        ->set('result.objectionId', $this->thinking->id)
        ->set('result.note', 'Went with a cheaper quote.')
        ->call('markLost')
        ->assertHasNoErrors()
        ->assertSee('Lead marked lost: Price')
        ->assertDontSeeHtml('data-test="rehash-lost"');

    expect($this->lead->fresh()->only(['status', 'lost_by_name']))->toBe(['status' => LeadStatus::Lost, 'lost_by_name' => 'Casey Caller']);
});

test('in rehash nobody is offered sold, lost or still pending on a proposal, and the salesperson is not offered to mark the lead lost', function () {
    sendToRehash($this);

    // A manager there may still change the proposal, and may mark the Lead lost as the call center does.
    proposalsCard($this->manager, $this->lead)
        ->assertDontSeeHtml('data-test="proposal-sold"')
        ->assertDontSeeHtml('data-test="proposal-pending"')
        ->assertSeeHtml('data-test="edit-proposal"')
        ->assertSeeHtml('data-test="rehash-lost"');

    foreach ([$this->sam, $this->riley] as $salesperson) {
        proposalsCard($salesperson, $this->lead)
            ->assertDontSeeHtml('data-test="rehash-lost"')
            ->assertDontSeeHtml('data-test="proposal-sold"')
            ->call('openLost')
            ->assertForbidden();
    }
});

test('the salesperson it came from takes it back from the appointment card, as appointment run', function () {
    appointmentCard($this->sam, $this->lead)->assertDontSeeHtml('data-test="take-lead-back"');

    sendToRehash($this);

    appointmentCard($this->riley, $this->lead)->assertDontSeeHtml('data-test="take-lead-back"');

    appointmentCard($this->sam, $this->lead)
        ->assertSeeHtml('data-test="take-lead-back"')
        ->assertSee('This lead went from you to the Contact Center for rehash because it was not sold, lost or booked again in time.')
        ->call('openTakeBack')
        ->set('takeBackNote', 'The homeowner called me back.')
        ->call('takeBack')
        ->assertHasNoErrors();

    expect($this->lead->fresh()->only(['status', 'assigned_user_id']))->toBe(['status' => LeadStatus::AppointmentRun, 'assigned_user_id' => $this->sam->id]);

    // Theirs again, with a week from today although the offer itself has expired.
    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSee('Expired yesterday, Thu, Oct 15')
        ->assertSee('After Fri, Oct 23 this lead goes to the Contact Center for rehash');
});

test('a lead run with nothing presented says when it goes to rehash', function () {
    $other = bookableLead($this->lakeview);
    bookAppointment($other, $this->sam, $this->manager, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 16:00:00');
    runAppointment($other->fresh(), $this->sam);

    proposalsCard($this->sam, $other->fresh())
        ->assertSeeHtml('data-test="pending-ownership"')
        ->assertSee('Nothing is pending on this lead. After Fri, Oct 16 it goes to the Contact Center for rehash, unless a proposal is added, a return visit is booked or it is marked lost.');
});

/*
|--------------------------------------------------------------------------
| Sales > Calendar
|--------------------------------------------------------------------------
*/

test('the calendar lists a lead released for rehash under released, for the salesperson it came from', function () {
    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSeeHtml('data-test="calendar-pending"')
        ->assertDontSeeHtml('data-test="calendar-released"');

    sendToRehash($this);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-pending"')
        ->assertSeeHtml('data-test="calendar-released"')
        ->assertSee('Pat Customer')
        ->assertSee('Leads that were not booked again, sold or lost in time.');

    Livewire::actingAs($this->riley)->test('pages::sales.calendar')->assertDontSee('Pat Customer');
});
