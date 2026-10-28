<?php

use App\Enums\AppointmentState;
use App\Enums\LeadStatus;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Objection;
use App\Models\Proposal;
use App\Models\SalesLossReason;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The after-action report page
 * (docs/slice-7-appointment-results-plan.md, step 7c), where what was
 * presented is ticked from the Lead's estimates (step 31e; decision 207).
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. Tests start at 4:35 pm that day, just after it ended, with nothing
 * said about it yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    // Built before the visit: two options, $8,450 and $3,200 (step 31e).
    $this->exterior = estimateFor($this->lead->fresh(), $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    [$this->wholeExterior, $this->frontOnly] = $this->exterior->options->all();

    $this->travelTo('2026-10-08 20:35:00');
    $this->lead = $this->lead->fresh();
});

/**
 * The report page for an appointment, as a staff member opens it.
 */
function reportPage(User $user, Lead $lead, Appointment $appointment): Testable
{
    return Livewire::actingAs($user)->test('pages::leads.report', ['lead' => $lead, 'appointment' => $appointment]);
}

test('the appointment card, the calendar and the prompt lead to the report once the time has come', function () {
    $report = route('leads.report', [$this->lead, $this->appointment]);

    appointmentCard($this->sam, $this->lead)
        ->assertSeeHtml('data-test="after-action-report-link"')
        ->assertSeeHtml('href="'.$report.'"')
        // The two quick answers are still there.
        ->assertSeeHtml('data-test="close-out-run"')
        ->assertSeeHtml('data-test="close-out-no-show"');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertSeeHtml('href="'.$report.'"');
    Livewire::actingAs($this->sam)->test('pages::sales.close-out-prompt')->assertSeeHtml('href="'.$report.'"');

    // Someone who may not answer is not offered it.
    appointmentCard($this->agent, $this->lead)->assertDontSeeHtml('data-test="after-action-report-link"');
});

test('who may open the report', function (Closure $person, int $status) {
    $this->actingAs($person($this))->get(route('leads.report', [$this->lead, $this->appointment]))->assertStatus($status);
})->with([
    'the salesperson whose appointment it is' => [fn ($test) => $test->sam, 200],
    'the Location Manager there' => [fn ($test) => $test->manager, 200],
    'a Call Center Agent' => [fn ($test) => $test->agent, 403],
    'another Sales Consultant there' => [fn ($test) => $test->riley, 403],
    'a Sales Consultant somewhere else' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create(), 403],
]);

test('the report is for an appointment of that lead, and nobody signed out reaches it', function () {
    $other = bookableLead($this->lakeview);
    $theirs = Appointment::factory()->forLead($other)->with($this->sam)->create();

    $this->actingAs($this->manager)->get(route('leads.report', [$this->lead, $theirs]))->assertNotFound();

    auth()->logout();

    $this->get(route('leads.report', [$this->lead, $this->appointment]))->assertRedirect(route('login'));
});

test('an appointment that has not started, or is already answered, goes back to the lead', function () {
    $this->travelTo('2026-10-08 18:00:00');

    reportPage($this->sam, $this->lead, $this->appointment)->assertRedirect(route('leads.show', $this->lead));

    $this->travelTo('2026-10-08 20:35:00');
    runAppointment($this->lead, $this->sam);

    reportPage($this->sam, $this->lead->fresh(), $this->appointment->fresh())->assertRedirect(route('leads.show', $this->lead));
});

test('the report asks only what the answers so far make necessary', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->assertSee('After-action report')
        ->assertSee('Pat Customer')
        ->assertSee('1. What happened?')
        ->assertDontSeeHtml('data-test="report-presented"')
        ->assertDontSeeHtml('data-test="report-estimates"')
        ->assertDontSeeHtml('data-test="report-submit"')
        ->set('outcome', 'run')
        ->assertSee('2. Was anything presented?')
        ->assertDontSeeHtml('data-test="report-estimates"')
        ->assertSeeHtml('data-test="report-submit"')
        ->set('presented', 'yes')
        ->assertSee('3. Which estimate was presented?')
        // The estimate as it is: its options' range, and its options by name.
        ->assertSee('Residential Exterior estimate, $3,200 to $8,450')
        ->assertSee('Whole exterior · Front only')
        ->assertDontSee('What became of it?')
        ->set('estimates.'.$this->exterior->id.'.presented', true)
        ->assertSee('What became of it?')
        ->assertDontSeeHtml('data-test="result-option"')
        ->set('estimates.'.$this->exterior->id.'.result', 'sold')
        // Sold for the option the customer chose, never a typed amount.
        ->assertSeeHtml('data-test="result-option"')
        ->assertSee('Front only, $3,200')
        ->assertDontSeeHtml('data-test="result-sold-amount"')
        ->set('estimates.'.$this->exterior->id.'.result', 'lost')
        ->assertSeeHtml('data-test="result-loss-reason"')
        ->assertSeeHtml('data-test="result-objection"')
        ->set('presented', 'no')
        ->assertDontSeeHtml('data-test="report-estimates"')
        ->assertSee('Why was nothing presented? (required)')
        ->assertSee('The appointment will count as run with nothing presented, whatever the reason.')
        ->set('presented', 'yes')
        ->assertDontSeeHtml('data-test="report-no-demo-reason"')
        ->set('presented', 'no')
        ->set('outcome', 'no_show')
        ->assertDontSeeHtml('data-test="report-presented"')
        ->assertSeeHtml('data-test="report-submit"');
});

test('a no-show is reported and the page goes back to the lead', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'no_show')
        ->set('note', 'Nobody home.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::NoShow);
    expect($this->appointment->fresh()->state_note)->toBe('Nobody home.');
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentCancelled);
});

test('run, the estimate presented and sold: the whole report in one save', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('estimates.'.$this->exterior->id.'.presented', true)
        ->set('estimates.'.$this->exterior->id.'.result', 'sold')
        ->set('estimates.'.$this->exterior->id.'.optionId', (string) $this->wholeExterior->id)
        ->set('note', 'Signed on the spot.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_note)->toBe('Signed on the spot.');
    expect(Proposal::query()->sole()->only(['estimate_id', 'estimate_option_id', 'sold_cents']))->toBe(['estimate_id' => $this->exterior->id, 'estimate_option_id' => $this->wholeExterior->id, 'sold_cents' => 845000]);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('a refusal lands on the estimate it is about, and nothing is saved', function () {
    $cabinets = estimateFor($this->lead, $this->sam, ['Cabinets only' => '3200']);

    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('estimates.'.$this->exterior->id.'.presented', true)
        ->set('estimates.'.$this->exterior->id.'.result', 'pending')
        ->set('estimates.'.$this->exterior->id.'.objectionId', (string) $this->thinking->id)
        ->set('estimates.'.$cabinets->id.'.presented', true)
        ->set('estimates.'.$cabinets->id.'.result', 'sold')
        ->call('submit')
        ->assertHasErrors('estimates.'.$cabinets->id.'.optionId')
        ->assertHasNoErrors('estimates.'.$this->exterior->id.'.optionId')
        ->assertSee('Choose the option the customer chose.')
        ->assertNoRedirect()
        ->set('estimates.'.$cabinets->id.'.result', 'lost')
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasErrors('estimates.'.$cabinets->id.'.lossReasonId')
        ->assertNoRedirect();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect(Proposal::query()->count())->toBe(0);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('something presented with nothing ticked is refused', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->call('submit')
        ->assertHasErrors('presented')
        ->assertSee('Tick the estimate that was presented, or answer that nothing was.')
        ->assertNoRedirect();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

test('with no estimate on the lead, the report says to build it first', function () {
    $lead = bookableLead($this->lakeview);
    $this->travelTo('2026-10-07 14:00:00');
    $appointment = bookAppointment($lead, $this->sam, $this->manager, '2026-10-08', '17:00', 30)->appointment;
    $this->travelTo('2026-10-08 22:00:00');

    reportPage($this->sam, $lead->fresh(), $appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->assertSeeHtml('data-test="report-no-estimates"')
        ->assertSee('This lead has no estimate yet. Build it from the Estimates card on the lead, then come back to this report.');
});

test('on a return visit the report lists what is still pending and can sell it', function () {
    runAppointment($this->lead, $this->sam);
    $exterior = addProposal($this->lead->fresh(), $this->sam);

    $second = bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00')->appointment;
    $this->travelTo('2026-10-12 15:35:00');

    reportPage($this->sam, $this->lead->fresh(), $second)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->assertSee('From an earlier visit')
        ->assertSee('Whole exterior, $8,450')
        // Recorded by hand before step 31e, and still given its result here.
        ->assertSee('Recorded by hand')
        ->assertSee('New today')
        ->set('existing.'.$exterior->id.'.presented', true)
        ->assertSee('What became of it?')
        ->call('submit')
        // It was presented again, so it needs its result.
        ->assertHasErrors('existing.'.$exterior->id.'.proposalResult')
        ->set('existing.'.$exterior->id.'.result', 'sold')
        ->set('existing.'.$exterior->id.'.soldAmount', '8,000')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect($exterior->fresh()->result)->toBe(ProposalResult::Sold);
    expect($exterior->fresh()->sold_cents)->toBe(800000);
    expect($exterior->appointments()->count())->toBe(2);
    // The estimate not ticked was not presented.
    expect(Proposal::query()->count())->toBe(1);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('someone who may not answer cannot save the report, whatever the browser sends', function () {
    reportPage($this->agent, $this->lead, $this->appointment)->assertForbidden();

    // Riley can see the Lead's Location but it is not their appointment.
    reportPage($this->riley, $this->lead, $this->appointment)->assertForbidden();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});
