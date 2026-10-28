<?php

use App\Actions\Estimates\StartEstimate;
use App\Enums\AppointmentState;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;

/*
 * The Estimates card on the Lead page, which took over the Proposals card
 * (docs/slice-7-appointment-results-plan.md, step 7b;
 * docs/slice-31-estimating-plan.md, step 31e).
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. Tests start at 3:00 pm that day, before anyone has marked it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    $this->appointment = bookAppointment($this->lead, $this->sam, $this->manager)->appointment;

    $this->travelTo('2026-10-08 19:00:00');
    $this->lead = $this->lead->fresh();
});

test('the card lists the lead\'s estimates, and says when a run appointment has nothing presented', function () {
    $card = proposalsCard($this->sam, $this->lead)
        ->assertSee('Estimates')
        ->assertSee('No estimates yet.')
        ->assertSeeHtml('data-test="start-estimate"')
        ->assertDontSeeHtml('data-test="no-proposal-yet"');

    runAppointment($this->lead, $this->sam);

    // The Appointment card says an appointment changed; no reload is needed.
    $card->dispatch('lead-appointment-changed')
        ->assertSee('The appointment on Thu, Oct 8, 2026 at 3:00 PM EDT was run and has no estimate marked presented yet. Until one is, it counts as run with nothing presented.');
});

test('the lead page holds one Estimates card', function () {
    runAppointment($this->lead, $this->sam);

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))
        ->assertOk()
        ->assertSee('data-test="estimates"', false)
        ->assertDontSee('data-test="lead-proposals"', false)
        ->assertSee('has no estimate marked presented yet');
});

test('the salesperson marks an estimate presented from the card, at an appointment whose report did not say so', function () {
    $estimate = estimateFor($this->lead, $this->sam, ['Whole exterior' => '8450', 'Front only' => '3200']);
    runAppointment($this->lead, $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSeeInOrder(['Residential Exterior estimate', '$3,200 to $8,450', 'Whole exterior', 'Front only', 'Not presented yet'])
        ->assertSeeHtml('data-test="mark-presented"')
        ->call('openPresent', $estimate->id)
        // The one run appointment is already chosen.
        ->assertSet('proposalAppointmentIds', [(string) $this->appointment->id])
        ->assertSee('Residential Exterior estimate, $3,200 to $8,450.')
        ->call('present')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Residential Exterior estimate', '$3,200 to $8,450', 'Pending', 'Presented at: Thu, Oct 8, 2026 at 3:00 PM EDT', 'Marked presented by Sam Sales'])
        ->assertDontSee('Not presented yet')
        ->assertDontSeeHtml('data-test="mark-presented"')
        ->assertDontSeeHtml('data-test="no-proposal-yet"')
        // One appointment, already recorded: nothing to present it at again.
        ->assertDontSeeHtml('data-test="present-proposal-again"');

    expect(Proposal::query()->sole()->only(['estimate_id', 'price_from_cents', 'price_cents']))->toBe(['estimate_id' => $estimate->id, 'price_from_cents' => 320000, 'price_cents' => 845000]);
});

test('a refusal is shown in the dialog', function () {
    $estimate = estimateFor($this->lead, $this->sam);
    $empty = app(StartEstimate::class)->handle($this->lead, 'residential_interior', $this->sam);
    runAppointment($this->lead, $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openPresent', $estimate->id)
        ->set('proposalAppointmentIds', [])
        ->call('present')
        ->assertHasErrors('proposalAppointmentIds')
        ->assertSee('Choose the appointment it was presented at.')
        ->call('openPresent', $empty->id)
        ->call('present')
        ->assertHasErrors('estimate')
        ->assertSee('This estimate has no price yet. Add what it covers first.');

    expect(Proposal::query()->count())->toBe(0);
});

test('only this lead\'s run appointments are accepted, whatever the browser sends', function () {
    $estimate = estimateFor($this->lead, $this->sam);
    runAppointment($this->lead, $this->sam);

    $theirs = Appointment::factory()->forLead(bookableLead($this->lakeview))->with($this->sam)->create(['state' => AppointmentState::Run]);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openPresent', $estimate->id)
        ->set('proposalAppointmentIds', [(string) $theirs->id])
        ->call('present')
        ->assertHasErrors('proposalAppointmentIds');

    expect(Proposal::query()->count())->toBe(0);
});

test('everyone who may see the lead sees its estimates, and only some may mark one presented', function () {
    $estimate = estimateFor($this->lead, $this->sam);
    runAppointment($this->lead, $this->sam);

    proposalsCard($this->manager, $this->lead->fresh())
        ->assertSee('Residential Exterior estimate')
        ->assertSeeHtml('data-test="mark-presented"');

    // The plan lets the Contact Center see what was pitched and for how much (11.4).
    proposalsCard($this->agent, $this->lead->fresh())
        ->assertSee('Residential Exterior estimate')
        ->assertSee('$8,450')
        ->assertDontSeeHtml('data-test="mark-presented"')
        ->assertDontSeeHtml('data-test="start-estimate"')
        ->call('openPresent', $estimate->id)
        ->assertForbidden();

    expect(Proposal::query()->count())->toBe(0);
});

test('someone who may not see the lead cannot open its card', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    proposalsCard($elsewhere, $this->lead)->assertForbidden();
});

test('a proposal recorded by hand before estimates is still listed, marked so, and its name and price can still be changed', function () {
    runAppointment($this->lead, $this->sam);
    $proposal = addProposal($this->lead->fresh(), $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSeeInOrder(['Whole exterior', '$8,450', 'Pending', 'Recorded by hand'])
        ->assertDontSeeHtml('data-test="mark-presented"')
        ->call('openEdit', $proposal->id)
        ->assertSeeHtml('data-test="edit-proposal-name"')
        ->set('proposalPrice', '8,000')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($proposal->fresh()->price_cents)->toBe(800000);
});

test('a proposal is recorded at a return visit from the card', function () {
    runAppointment($this->lead, $this->sam);
    $proposal = addProposal($this->lead->fresh(), $this->sam);

    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00');
    $this->travelTo('2026-10-12 14:00:00');
    $second = runAppointment($this->lead->fresh(), $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        // The return visit has no proposal of its own yet.
        ->assertSee('The appointment on Mon, Oct 12, 2026 at 10:00 AM EDT was run and has no estimate marked presented yet.')
        ->assertSeeHtml('data-test="present-proposal-again"')
        ->call('openAgain', $proposal->id)
        ->assertSet('againAppointmentId', $second->id)
        ->assertSee('Whole exterior, $8,450, was presented again on a return visit.')
        ->call('presentAgain')
        ->assertHasNoErrors()
        ->assertSee('Presented at: Thu, Oct 8, 2026 at 3:00 PM EDT; Mon, Oct 12, 2026 at 10:00 AM EDT')
        ->assertDontSeeHtml('data-test="no-proposal-yet"')
        ->assertDontSeeHtml('data-test="present-proposal-again"');

    expect($proposal->appointments()->count())->toBe(2);
});

test('the dialog for a return visit refuses a proposal with nowhere left to record it, and people who may not', function () {
    runAppointment($this->lead, $this->sam);
    $proposal = addProposal($this->lead->fresh(), $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())->call('openAgain', $proposal->id)->assertStatus(409);
    proposalsCard($this->agent, $this->lead->fresh())->call('openAgain', $proposal->id)->assertForbidden();
});
