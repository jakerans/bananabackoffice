<?php

use App\Enums\AppointmentState;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;

/*
 * The Proposals card on the Lead page
 * (docs/slice-7-appointment-results-plan.md, step 7b).
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

test('the card shows nothing until an appointment has been run, and appears the moment one is', function () {
    $card = proposalsCard($this->sam, $this->lead)
        ->assertDontSee('Proposals')
        ->assertDontSeeHtml('data-test="add-proposal"');

    runAppointment($this->lead, $this->sam);

    // The Appointment card says an appointment changed; no reload is needed.
    $card->dispatch('lead-appointment-changed')
        ->assertSee('Proposals')
        ->assertSee('The appointment on Thu, Oct 8, 2026 at 3:00 PM EDT was run and has no proposal yet. Until one is added it counts as run with nothing presented.')
        ->assertSeeHtml('data-test="add-proposal"');
});

test('the lead page holds the card', function () {
    runAppointment($this->lead, $this->sam);

    $this->actingAs($this->sam)->get(route('leads.show', $this->lead))
        ->assertOk()
        ->assertSee('data-test="lead-proposals"', false)
        ->assertSee('has no proposal yet');
});

test('the salesperson adds a proposal from the card', function () {
    runAppointment($this->lead, $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openAdd')
        // The one run appointment is already chosen.
        ->assertSet('proposalAppointmentIds', [(string) $this->appointment->id])
        ->set('proposalName', 'Whole exterior')
        ->set('proposalPrice', '8,450')
        ->call('add')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Whole exterior', '$8,450', 'Presented at: Thu, Oct 8, 2026 at 3:00 PM EDT', 'Added by Sam Sales'])
        ->assertDontSeeHtml('data-test="no-proposal-yet"')
        // One appointment, already recorded: nothing to present it at again.
        ->assertDontSeeHtml('data-test="present-proposal-again"');

    expect(Proposal::query()->sole()->price_cents)->toBe(845000);
});

test('a refusal is shown at the field it is about', function () {
    runAppointment($this->lead, $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openAdd')
        ->set('proposalPrice', '8450')
        ->call('add')
        ->assertHasErrors('proposalName')
        ->assertSee('Say what was pitched.')
        ->set('proposalName', 'Whole exterior')
        ->assertHasNoErrors()
        ->set('proposalPrice', 'about eight thousand')
        ->call('add')
        ->assertHasErrors('proposalPrice')
        ->assertSee('Enter the price in dollars, like 8450 or 8,450.50.')
        ->set('proposalPrice', '8450')
        ->set('proposalAppointmentIds', [])
        ->call('add')
        ->assertHasErrors('proposalAppointmentIds')
        ->assertSee('Choose the appointment it was presented at.');

    expect(Proposal::query()->count())->toBe(0);
});

test('only this lead\'s run appointments are accepted, whatever the browser sends', function () {
    runAppointment($this->lead, $this->sam);

    $theirs = Appointment::factory()->forLead(bookableLead($this->lakeview))->with($this->sam)->create(['state' => AppointmentState::Run]);

    proposalsCard($this->sam, $this->lead->fresh())
        ->call('openAdd')
        ->set('proposalName', 'Whole exterior')
        ->set('proposalPrice', '8450')
        ->set('proposalAppointmentIds', [(string) $theirs->id])
        ->call('add')
        ->assertHasErrors('proposalAppointmentIds');

    expect(Proposal::query()->count())->toBe(0);
});

test('everyone who may see the lead sees its proposals, and only some may add', function () {
    runAppointment($this->lead, $this->sam);
    addProposal($this->lead->fresh(), $this->sam);

    proposalsCard($this->manager, $this->lead->fresh())
        ->assertSee('Whole exterior')
        ->assertSeeHtml('data-test="add-proposal"');

    // The plan lets the Contact Center see what was pitched and for how much (11.4).
    proposalsCard($this->agent, $this->lead->fresh())
        ->assertSee('Whole exterior')
        ->assertSee('$8,450')
        ->assertDontSeeHtml('data-test="add-proposal"')
        ->call('openAdd')
        ->assertForbidden();

    proposalsCard($this->agent, $this->lead->fresh())
        ->set('proposalName', 'Cabinets only')
        ->set('proposalPrice', '3200')
        ->set('proposalAppointmentIds', [(string) $this->appointment->id])
        ->call('add')
        ->assertForbidden();

    expect(Proposal::query()->count())->toBe(1);
});

test('someone who may not see the lead cannot open its card', function () {
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    proposalsCard($elsewhere, $this->lead)->assertForbidden();
});

test('a proposal is recorded at a return visit from the card', function () {
    runAppointment($this->lead, $this->sam);
    $proposal = addProposal($this->lead->fresh(), $this->sam);

    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-12', '10:00');
    $this->travelTo('2026-10-12 14:00:00');
    $second = runAppointment($this->lead->fresh(), $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        // The return visit has no proposal of its own yet.
        ->assertSee('The appointment on Mon, Oct 12, 2026 at 10:00 AM EDT was run and has no proposal yet.')
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
