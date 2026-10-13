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
 * (docs/slice-7-appointment-results-plan.md, step 7c).
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
        ->assertDontSeeHtml('data-test="report-proposals"')
        ->assertDontSeeHtml('data-test="report-submit"')
        ->set('outcome', 'run')
        ->assertSee('2. Was anything presented?')
        ->assertDontSeeHtml('data-test="report-proposals"')
        ->assertSeeHtml('data-test="report-submit"')
        ->set('presented', 'yes')
        ->assertSee('3. What was presented?')
        ->assertDontSeeHtml('data-test="result-sold-amount"')
        ->set('proposals.0.result', 'sold')
        ->assertSeeHtml('data-test="result-sold-amount"')
        ->set('proposals.0.result', 'lost')
        ->assertSeeHtml('data-test="result-loss-reason"')
        ->assertSeeHtml('data-test="result-objection"')
        ->set('presented', 'no')
        ->assertDontSeeHtml('data-test="report-proposals"')
        ->assertSee('The appointment will count as run with nothing presented.')
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

test('run, one proposal, sold: the whole report in one save', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('proposals.0.name', 'Whole exterior')
        ->set('proposals.0.price', '8,450')
        ->set('proposals.0.result', 'sold')
        ->set('note', 'Signed on the spot.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Run);
    expect($this->appointment->fresh()->state_note)->toBe('Signed on the spot.');
    expect(Proposal::query()->sole()->only(['name', 'price_cents', 'sold_cents']))->toBe(['name' => 'Whole exterior', 'price_cents' => 845000, 'sold_cents' => 845000]);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('a refusal lands on the proposal it is about, and nothing is saved', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('proposals.0.name', 'Whole exterior')
        ->set('proposals.0.price', '8450')
        ->set('proposals.0.result', 'pending')
        ->set('proposals.0.objectionId', (string) $this->thinking->id)
        ->call('addProposal')
        ->set('proposals.1.name', 'Cabinets only')
        ->set('proposals.1.price', 'about three')
        ->set('proposals.1.result', 'lost')
        ->call('submit')
        ->assertHasErrors('proposals.1.proposalPrice')
        ->assertHasNoErrors('proposals.0.proposalPrice')
        ->assertNoRedirect()
        ->set('proposals.1.price', '3200')
        ->assertHasNoErrors()
        ->call('submit')
        ->assertHasErrors('proposals.1.lossReasonId')
        ->assertNoRedirect();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
    expect(Proposal::query()->count())->toBe(0);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentBooked);
});

test('something presented with nothing filled in is refused at the first proposal', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->call('submit')
        ->assertHasErrors('proposals.0.proposalName')
        ->assertSee('Say what was pitched.')
        ->assertNoRedirect();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});

test('a proposal left empty is dropped, and one can be removed', function () {
    reportPage($this->sam, $this->lead, $this->appointment)
        ->set('outcome', 'run')
        ->set('presented', 'yes')
        ->set('proposals.0.name', 'Not this one')
        ->call('addProposal')
        ->set('proposals.1.name', 'Whole exterior')
        ->set('proposals.1.price', '8450')
        ->set('proposals.1.result', 'sold')
        ->call('addProposal')
        ->call('removeProposal', 0)
        ->assertSet('proposals.0.name', 'Whole exterior')
        ->assertCount('proposals', 2)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $this->lead));

    expect(Proposal::query()->pluck('name')->all())->toBe(['Whole exterior']);
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
    // The empty "new today" proposal was not needed.
    expect(Proposal::query()->count())->toBe(1);
    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('someone who may not answer cannot save the report, whatever the browser sends', function () {
    reportPage($this->agent, $this->lead, $this->appointment)->assertForbidden();

    // Riley can see the Lead's Location but it is not their appointment.
    reportPage($this->riley, $this->lead, $this->appointment)->assertForbidden();

    expect($this->appointment->fresh()->state)->toBe(AppointmentState::Booked);
});
