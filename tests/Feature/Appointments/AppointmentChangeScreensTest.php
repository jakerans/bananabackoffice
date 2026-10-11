<?php

use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Enums\AppointmentState;
use App\Enums\AuditAction;
use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\ManagedList;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\AppointmentCancellationReason;
use App\Models\AuditEvent;
use App\Models\LeadCadence;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadIssue;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

/*
 * The screens of step 6b (docs/slice-6-appointments-plan.md): what the
 * Appointment card offers once an appointment is booked, the notice a Lead
 * carries when it came back from Sales, the question asked when a Lead leaves
 * Sales from the Lead page, and the Calendar's "To rebook" list.
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern. The appointment is
 * for the next day at 3:00 pm Eastern, 90 minutes, with Sam Sales.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->other = AppointmentCancellationReason::query()->where('name', 'Other')->sole();
    $this->noLongerNeeded = AppointmentCancellationReason::query()->where('name', 'No longer needed')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    enterQueue($this->lead);
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;
    $this->lead = $this->lead->fresh();
});

/*
|--------------------------------------------------------------------------
| What the card offers, and to whom
|--------------------------------------------------------------------------
*/

test('an upcoming appointment offers each action only to the people who may take it', function (Closure $person, array $offered) {
    $card = appointmentCard($person($this), $this->lead);

    foreach (['reschedule-appointment', 'move-appointment', 'cancel-appointment', 'send-lead-back', 'appointment-booked-in-error'] as $button) {
        in_array($button, $offered, true)
            ? $card->assertSeeHtml('data-test="'.$button.'"')
            : $card->assertDontSeeHtml('data-test="'.$button.'"');
    }
})->with([
    'a manager' => [fn ($test) => $test->manager, ['reschedule-appointment', 'move-appointment', 'cancel-appointment', 'send-lead-back', 'appointment-booked-in-error']],
    'the salesperson' => [fn ($test) => $test->sam, ['reschedule-appointment', 'cancel-appointment']],
    'a call center agent' => [fn ($test) => $test->agent, ['cancel-appointment']],
]);

test('the openers refuse people who may not take the action, whatever the browser sends', function () {
    appointmentCard($this->agent, $this->lead)->call('openReschedule')->assertForbidden();
    appointmentCard($this->agent, $this->lead)->call('openMove')->assertForbidden();
    appointmentCard($this->sam, $this->lead)->call('openMove')->assertForbidden();
    appointmentCard($this->sam, $this->lead)->call('openSendBack')->assertForbidden();
    appointmentCard($this->riley, $this->lead)->call('openCancel')->assertForbidden();
    appointmentCard($this->sam, $this->lead)->set('issuedCount', StopCountingLeadAsIssued::ANOTHER_REASON)->set('sendBackNote', 'I cannot make it.')->call('sendBack')->assertForbidden();

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

/*
|--------------------------------------------------------------------------
| Reschedule and move
|--------------------------------------------------------------------------
*/

test('a manager reschedules from the card, which then shows the new time and the earlier one', function () {
    appointmentCard($this->manager, $this->lead)
        ->call('openReschedule')
        // The dialog starts on the time the appointment has.
        ->assertSet('appointmentDate', '2026-10-08')
        ->assertSet('appointmentTime', '15:00')
        ->assertSet('appointmentMinutes', 90)
        ->set('appointmentDate', '2026-10-09')
        ->set('appointmentTime', '11:00')
        ->set('appointmentMinutes', 60)
        ->call('reschedule')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Fri, Oct 9, 2026')
        ->assertSee('11:00 AM to 12:00 PM EDT')
        ->assertSeeHtml('data-test="appointment-changes"')
        ->assertSee('Was Thu, Oct 8, 2026, 3:00 PM to 4:30 PM EDT with Sam Sales.')
        ->assertSee('Rescheduled');

    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-09 15:00:00');
});

test('a reschedule that clashes is refused at the time field', function () {
    bookAppointment(bookableLead($this->lakeview), $this->sam, $this->manager, time: '17:00');

    appointmentCard($this->sam, $this->lead)
        ->call('openReschedule')
        ->set('appointmentTime', '16:30')
        ->call('reschedule')
        ->assertHasErrors('appointmentTime')
        ->assertSee('Sam Sales already has an appointment from 5:00 PM to 6:30 PM EDT then. Choose another time.');

    expect($this->appointment->fresh()->starts_at->toDateTimeString())->toBe('2026-10-08 19:00:00');
});

test('a manager moves the appointment from the card to someone the page offered', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Away Sales']);

    $card = appointmentCard($this->manager, $this->lead)->call('openMove');

    // The dialog offers the other Sales Consultants there, not the one who has it.
    expect($card->instance()->otherSalespeople->pluck('name')->all())->toBe(['Riley Rep']);

    $card->call('move')->assertHasErrors('newSalespersonId')->assertSee('Choose one of the people listed.');
    $card->set('newSalespersonId', $elsewhere->id)->call('move')->assertHasErrors('newSalespersonId');
    $card->set('newSalespersonId', $this->sam->id)->call('move')->assertHasErrors('newSalespersonId');

    expect($this->appointment->fresh()->salesperson_id)->toBe($this->sam->id);

    $card->set('newSalespersonId', $this->riley->id)
        ->set('moveNote', 'Sam is out sick.')
        ->call('move')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Was Thu, Oct 8, 2026, 3:00 PM to 4:30 PM EDT with Sam Sales.')
        ->assertSee('Moved to another salesperson')
        ->assertSee('Sam is out sick.');

    expect($this->appointment->fresh()->salesperson_name)->toBe('Riley Rep');
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->riley->id);
});

/*
|--------------------------------------------------------------------------
| Cancel
|--------------------------------------------------------------------------
*/

test('an agent records a cancellation from the card, which then shows the record and offers to book again', function () {
    $card = appointmentCard($this->agent, $this->lead)->call('openCancel');

    $card->call('cancel')->assertHasErrors('cancelledBy')->assertSee('Say who cancelled the appointment.');
    $card->set('cancelledBy', 'customer')->call('cancel')->assertHasErrors('cancellationReasonId')->assertSee('Choose why the appointment was cancelled.');

    $card->set('cancellationReasonId', $this->noLongerNeeded->id)
        // A reason that does not ask shows no question about disqualifying.
        ->assertDontSeeHtml('data-test="cancellation-disqualifies"')
        ->set('cancellationNote', 'They sold the house.')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSeeHtml('data-test="appointment-cancelled"')
        ->assertSee('Cancelled by: the customer')
        ->assertSee('Reason: No longer needed')
        ->assertSee('They sold the house.')
        ->assertSee('Casey Caller')
        ->assertSee('Book again')
        ->assertDontSeeHtml('data-test="reschedule-appointment"')
        ->assertDontSeeHtml('data-test="cancel-appointment"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect($this->lead->fresh()->assigned_user_id)->toBe($this->sam->id);
});

test('choosing Other asks for a note and whether the lead is disqualified', function () {
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);

    $card = appointmentCard($this->agent, $this->lead)
        ->call('openCancel')
        ->set('cancelledBy', 'customer')
        ->set('cancellationReasonId', $this->other->id)
        ->assertSeeHtml('data-test="cancellation-disqualifies"')
        ->assertSee('What happened (required)');

    $card->call('cancel')->assertHasErrors('cancellationNote');
    $card->set('cancellationNote', 'They wanted a roof.')->call('cancel')->assertHasErrors('cancellationDisqualifies')->assertSee('Say whether this lead is disqualified.');

    $card->set('cancellationDisqualifies', 'yes')
        ->assertSeeHtml('data-test="cancellation-disqualification-reason"')
        // Only a manager is asked whether it should ever have been booked.
        ->assertDontSeeHtml('data-test="cancellation-issued-count"')
        ->call('cancel')
        ->assertHasErrors('disqualificationReasonId');

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);

    // An agent who sends the manager's answer anyway is not believed.
    $card->set('disqualificationReasonId', $reason->id)
        ->set('issuedCount', StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED)
        ->call('cancel')
        ->assertHasNoErrors();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Cancelled);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
});

test('a manager cancelling through Other can say the lead should never have been booked', function () {
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);

    appointmentCard($this->manager, $this->lead)
        ->call('openCancel')
        ->set('cancelledBy', 'company')
        ->set('cancellationReasonId', $this->other->id)
        ->set('cancellationNote', 'They wanted a roof.')
        ->set('cancellationDisqualifies', 'yes')
        ->assertSeeHtml('data-test="cancellation-issued-count"')
        ->set('disqualificationReasonId', $reason->id)
        ->set('issuedCount', StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED)
        ->call('cancel')
        ->assertHasNoErrors();

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Disqualified);
    expect(LeadIssue::query()->counted()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Book again
|--------------------------------------------------------------------------
*/

test('booking a cancelled lead again offers an agent only its salesperson, and a manager everyone', function () {
    cancelAppointment($this->lead, $this->agent);

    $card = appointmentCard($this->agent, $this->lead->fresh())->call('openBook');

    expect($card->instance()->salespeople->pluck('name')->all())->toBe(['Sam Sales']);

    $card->assertSet('salespersonId', $this->sam->id)
        ->assertSee('Book this lead again')
        ->assertSee('does not count as a new issued lead')
        ->assertSeeHtml('data-test="appointment-same-salesperson"')
        // Someone the page did not offer is refused.
        ->set('salespersonId', $this->riley->id)
        ->set('appointmentDate', '2026-10-12')
        ->set('appointmentTime', '10:00')
        ->call('book')
        ->assertHasErrors('salespersonId');

    $card->set('salespersonId', $this->sam->id)
        ->call('book')
        ->assertHasNoErrors()
        ->assertSee('Mon, Oct 12, 2026')
        ->assertSeeHtml('data-test="appointment-follows"')
        // The cancelled appointment stays on the card as a record.
        ->assertSeeHtml('data-test="earlier-appointments"')
        ->assertSee('Cancelled: No longer needed');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
    expect(LeadIssue::query()->count())->toBe(1);

    $managerCard = appointmentCard($this->manager, bookableLeadCancelledWith($this))->call('openBook');

    expect($managerCard->instance()->salespeople->pluck('name')->all())->toBe(['Riley Rep', 'Sam Sales']);
    $managerCard->assertSet('salespersonId', $this->sam->id)->assertDontSeeHtml('data-test="appointment-same-salesperson"');
});

/**
 * Another Lead at Lakeview whose appointment with Sam was cancelled.
 */
function bookableLeadCancelledWith($test)
{
    $lead = bookableLead($test->lakeview);
    bookAppointment($lead, $test->sam, $test->manager, time: '18:00');
    cancelAppointment($lead->fresh(), $test->manager);

    return $lead->fresh();
}

test('once the time has passed the card offers another appointment and nothing that changes the passed one', function () {
    $this->travelTo('2026-10-09 14:00:00');

    appointmentCard($this->sam, $this->lead->fresh())
        ->assertSeeHtml('data-test="appointment-passed"')
        ->assertSee('The time of this appointment has passed. If it was missed, book another.')
        ->assertSee('Book another appointment')
        ->assertDontSeeHtml('data-test="reschedule-appointment"')
        ->assertDontSeeHtml('data-test="cancel-appointment"')
        ->call('openReschedule')
        ->assertStatus(409);

    appointmentCard($this->manager, $this->lead->fresh())
        ->assertDontSeeHtml('data-test="move-appointment"')
        ->assertSeeHtml('data-test="send-lead-back"')
        ->call('openCancel')
        ->assertStatus(409);

    appointmentCard($this->sam, $this->lead->fresh())
        ->call('openBook')
        ->set('appointmentDate', '2026-10-13')
        ->set('appointmentTime', '10:00')
        ->call('book')
        ->assertHasNoErrors()
        ->assertSee('Tue, Oct 13, 2026')
        ->assertSee('Passed');

    expect(LeadIssue::query()->count())->toBe(1);
    expect(Appointment::query()->where('lead_id', $this->lead->id)->count())->toBe(2);
});

/*
|--------------------------------------------------------------------------
| Send back, and what the call center is then told (decision 114)
|--------------------------------------------------------------------------
*/

test('a manager sends the lead back from the card, which then says it came back from Sales', function () {
    $card = appointmentCard($this->manager, $this->lead)
        ->call('openSendBack')
        // With an appointment still upcoming the dialog points at Move instead.
        ->assertSeeHtml('data-test="send-back-use-move"')
        ->assertSee('use Move to another salesperson instead');

    $card->call('sendBack')->assertHasErrors('issuedCount')->assertSee('Say whether this lead should ever have been booked.');
    $card->set('issuedCount', StopCountingLeadAsIssued::ANOTHER_REASON)->call('sendBack')->assertHasErrors('sendBackNote');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);

    $card->set('sendBackNote', 'The customer wants a call first.')
        ->call('sendBack')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSeeHtml('data-test="sales-return"')
        ->assertSee('Cancelled appointment: this lead came back from Sales')
        ->assertSee('The customer wants a call first.')
        ->assertSee('Morgan Manager')
        // It is Qualified again, so it can be booked like any Qualified Lead.
        ->assertSeeHtml('data-test="book-appointment"')
        ->assertDontSeeHtml('data-test="send-lead-back"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Qualified);
    expect(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->state)->toBe(CadenceState::InQueue);
});

test('the agent who is handed a lead that came back from Sales is told on the call queue card', function () {
    cancelAppointment($this->lead, $this->agent);
    app(SendLeadBackToContactCenter::class)->handle($this->lead->fresh(), StopCountingLeadAsIssued::ANOTHER_REASON, 'Sam could not reach them to rebook.', $this->manager);

    $this->travelTo(LeadCadence::query()->where('lead_id', $this->lead->id)->sole()->due_at);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        // It waits behind its own button and count, and "Next lead" does not hand it out (decision 117).
        ->assertSee('1 cancelled appointment due now')
        ->assertSee('0 leads due now')
        ->call('nextLead')
        ->assertDontSeeHtml('data-test="queue-card"')
        ->call('nextCancelledAppointment')
        ->assertSeeHtml('data-test="queue-card"')
        ->assertSeeHtml('data-test="card-sales-return"')
        ->assertSee('Cancelled appointment: this lead came back from Sales')
        ->assertSee('Cancelled by: the customer. Reason: No longer needed.')
        ->assertSee('Sam could not reach them to rebook.');
});

test('a lead that never left the call center carries no such notice', function () {
    $lead = dueLead($this->lakeview);

    Livewire::actingAs($this->agent)->test('pages::marketing.call-queue')
        ->call('nextLead')
        ->assertSeeHtml('data-test="queue-card"')
        ->assertDontSeeHtml('data-test="card-sales-return"');

    expect($lead->fresh()->returned_from_sales_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Leaving Sales from the Lead page (decisions 101, 110 and 112)
|--------------------------------------------------------------------------
*/

test('the lead page shows the cancelled status and asks a manager whether the lead still counts when it is moved', function () {
    cancelAppointment($this->lead, $this->agent);

    $page = Livewire::actingAs($this->owner)->test('pages::leads.show', ['lead' => $this->lead->fresh()]);

    $page->assertSee('Appointment cancelled')
        ->call('openRouting')
        ->assertSeeHtml('data-test="issued-count"')
        ->assertSee('This lead is with Sales. Moving it takes it from its salesperson')
        ->set('destinationId', $this->harbor->id)
        ->set('routingReason', 'It is in the Harbor service area.')
        ->set('issuedCount', StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED)
        ->call('route')
        ->assertHasNoErrors()
        // The Appointment card is a component of its own, so it is told to read the Lead again.
        ->assertDispatched('lead-changed');

    $lead = $this->lead->fresh();

    expect($lead->location_id)->toBe($this->harbor->id);
    expect($lead->status)->toBe(LeadStatus::Qualified);
    expect($lead->returned_from_sales_at)->not->toBeNull();
    expect(LeadIssue::query()->counted()->count())->toBe(0);
});

test('the card reads the lead again when the lead page says it changed', function () {
    $card = appointmentCard($this->manager, $this->lead)->assertSeeHtml('data-test="cancel-appointment"');

    // The Lead page did something to the Lead that the card did not see.
    cancelAppointment($this->lead, $this->manager);

    $card->dispatch('lead-changed')
        ->assertSeeHtml('data-test="appointment-cancelled"')
        ->assertDontSeeHtml('data-test="cancel-appointment"');
});

test('the lead page says nothing to the card when the lead has not changed', function () {
    Livewire::actingAs($this->owner)->test('pages::leads.show', ['lead' => $this->lead->fresh()])
        ->call('openRouting')
        ->assertNotDispatched('lead-changed');
});

test('a refusal is cleared from a field once that field is changed', function () {
    appointmentCard($this->agent, $this->lead)
        ->call('openCancel')
        ->set('cancelledBy', 'customer')
        ->set('cancellationReasonId', $this->other->id)
        ->set('cancellationNote', 'They wanted a roof.')
        ->call('cancel')
        ->assertHasErrors('cancellationDisqualifies')
        ->set('cancellationDisqualifies', 'no')
        ->assertHasNoErrors('cancellationDisqualifies');
});

test('a lead that is not with Sales is not asked the question', function () {
    $lead = bookableLead($this->lakeview);

    Livewire::actingAs($this->owner)->test('pages::leads.show', ['lead' => $lead->fresh()])
        ->call('openRouting')
        ->assertDontSeeHtml('data-test="leaves-sales"')
        ->call('openDisqualify')
        ->assertDontSeeHtml('data-test="leaves-sales"');
});

test('someone who is not a manager is told the lead still counts, and their answer is not taken', function () {
    cancelAppointment($this->lead, $this->agent);
    $reason = LeadDisqualificationReason::factory()->create(['name' => 'Outside What We Do']);

    Livewire::actingAs($this->sam)->test('pages::leads.show', ['lead' => $this->lead->fresh()])
        ->call('openDisqualify')
        ->assertSeeHtml('data-test="issued-count-stays"')
        ->assertDontSeeHtml('data-test="issued-count"')
        ->set('disqualificationReasonId', $reason->id)
        ->set('disqualificationNote', 'They wanted a roof.')
        ->set('issuedCount', StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED)
        ->call('disqualify')
        ->assertHasErrors('issuedCount');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
    expect(LeadIssue::query()->counted()->count())->toBe(1);
    expect(AuditEvent::query()->where('action', AuditAction::LeadDisqualified)->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Sales > Calendar: To rebook
|--------------------------------------------------------------------------
*/

test('the calendar lists cancelled leads still waiting to be booked again, to the people who may see them', function () {
    $rileys = bookableLead($this->lakeview);
    $rileys->account->forceFill(['display_name' => 'Riley Customer'])->save();
    bookAppointment($rileys, $this->riley, $this->manager, time: '18:00');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertDontSeeHtml('data-test="calendar-to-rebook"');

    cancelAppointment($this->lead, $this->agent);
    cancelAppointment($rileys->fresh(), $this->agent);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSeeHtml('data-test="calendar-to-rebook"')
        ->assertSee('To rebook')
        ->assertSee('Pat Customer')
        ->assertSee('No longer needed')
        ->assertDontSee('Riley Customer')
        ->assertDontSeeHtml('data-test="calendar-rebook-salesperson"');

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSee('Pat Customer')
        ->assertSee('Riley Customer')
        ->assertSeeHtml('data-test="calendar-rebook-salesperson"');

    // Someone managing another Location sees neither.
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, $this->harbor)->create();

    Livewire::actingAs($elsewhere)->test('pages::sales.calendar')->assertDontSee('Pat Customer')->assertDontSee('Riley Customer');
});

test('a lead leaves the "to rebook" list once it is booked again or goes back to the contact center', function () {
    cancelAppointment($this->lead, $this->agent);
    $second = bookableLeadCancelledWith($this);
    $second->account->forceFill(['display_name' => 'Second Customer'])->save();

    bookAppointment($this->lead->fresh(), $this->sam, $this->agent, '2026-10-12', '10:00');
    app(SendLeadBackToContactCenter::class)->handle($second, StopCountingLeadAsIssued::ANOTHER_REASON, 'Could not rebook.', $this->manager);

    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-to-rebook"')
        ->assertSee('Pat Customer')
        ->assertDontSee('Second Customer');
});

/*
|--------------------------------------------------------------------------
| Lead & Sales Lists
|--------------------------------------------------------------------------
*/

test('cancellation reasons are a list on lead and sales lists, and say what Other asks', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.lists', ['list' => ManagedList::CancellationReasons->value])
        ->assertSee('Cancellation Reasons')
        ->assertSee('Why an appointment was cancelled.')
        ->assertSee('Holding off project')
        ->assertSee('Went with someone else')
        ->assertSee('No longer needed')
        ->assertSee('Note required')
        ->assertSee('Asks if the lead is disqualified');
});
