<?php

use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\LeadStatus;
use App\Enums\ManagedList;
use App\Enums\ProposalResult;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Objection;
use App\Models\SalesLossReason;
use App\Models\User;
use Livewire\Livewire;

/*
 * Results on the Proposals card: sold, lost, still pending; changing a
 * proposal; a manager's corrections; and a Lead that is lost
 * (docs/slice-7-appointment-results-plan.md, step 7c; decision 128).
 *
 * The appointment was on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales, who marked it run and added one proposal: "Whole exterior", $8,450.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->competitor = SalesLossReason::query()->where('name', 'Chose Another Contractor')->sole();
    $this->thinking = Objection::factory()->create(['name' => 'Wants to think it over']);
    $this->otherObjection = Objection::query()->where('name', 'Other (see note)')->sole();

    $this->lead = bookableLead($this->lakeview);
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 19:00:00');
    $this->appointment = runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

test('a pending proposal offers each action only to the people who may take it', function (Closure $person, array $offered) {
    $card = proposalsCard($person($this), $this->lead)->assertSee('Pending');

    foreach (['proposal-sold', 'proposal-lost', 'proposal-pending', 'edit-proposal', 'mark-lead-lost', 'proposal-added-in-error-button'] as $action) {
        in_array($action, $offered, true)
            ? $card->assertSeeHtml('data-test="'.$action.'"')
            : $card->assertDontSeeHtml('data-test="'.$action.'"');
    }

    // Nothing has a result yet, so there is nothing to correct.
    $card->assertDontSeeHtml('data-test="correct-proposal-result"');
})->with([
    'the salesperson the lead is with' => [fn ($test) => $test->sam, ['proposal-sold', 'proposal-lost', 'proposal-pending', 'edit-proposal', 'mark-lead-lost']],
    'the Location Manager there' => [fn ($test) => $test->manager, ['proposal-sold', 'proposal-lost', 'proposal-pending', 'edit-proposal', 'mark-lead-lost', 'proposal-added-in-error-button']],
    'a Call Center Agent' => [fn ($test) => $test->agent, []],
]);

test('the openers refuse people who may not take the action, and a proposal that cannot take it', function () {
    proposalsCard($this->agent, $this->lead)->call('openResult', $this->proposal->id, 'sold')->assertForbidden();
    proposalsCard($this->agent, $this->lead)->call('openEdit', $this->proposal->id)->assertForbidden();
    proposalsCard($this->agent, $this->lead)->call('openLost')->assertForbidden();
    proposalsCard($this->sam, $this->lead)->call('openAddedInError', $this->proposal->id)->assertForbidden();
    proposalsCard($this->sam, $this->lead)->call('openCorrect', $this->proposal->id)->assertForbidden();
    proposalsCard($this->sam, $this->lead)->call('openUndoLost')->assertForbidden();

    proposalsCard($this->sam, $this->lead)->call('openResult', $this->proposal->id, 'won')->assertStatus(409);
    proposalsCard($this->manager, $this->lead)->call('openCorrect', $this->proposal->id)->assertStatus(409);
    proposalsCard($this->manager, $this->lead)->call('openUndoLost')->assertStatus(409);

    app(RecordProposalResult::class)->handle($this->lead, $this->proposal, 'sold', [], $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())->call('openResult', $this->proposal->id, 'lost')->assertStatus(409);
    proposalsCard($this->sam, $this->lead->fresh())->call('openEdit', $this->proposal->id)->assertStatus(409);
    proposalsCard($this->manager, $this->lead->fresh())->call('openAddedInError', $this->proposal->id)->assertStatus(409);
    proposalsCard($this->sam, $this->lead->fresh())->call('openLost')->assertStatus(409);
});

test('the salesperson marks a proposal sold from the card, and the lead shows Sold', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openResult', $this->proposal->id, 'sold')
        ->assertSee('Mark this proposal sold?')
        ->assertSee('Whole exterior, $8,450.')
        ->set('result.soldAmount', 'a bit less')
        ->call('saveResult')
        ->assertHasErrors('soldAmount')
        ->assertSee('Enter the sold amount in dollars, like 8450 or 8,450.50.')
        ->set('result.soldAmount', '8,200')
        ->assertHasNoErrors()
        ->call('saveResult')
        ->assertHasNoErrors()
        // The Lead page and the Appointment card are told, so the status changes without a reload.
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Sold for $8,200')
        ->assertSee('Recorded by Sam Sales')
        ->assertDontSeeHtml('data-test="proposal-sold"')
        ->assertDontSeeHtml('data-test="edit-proposal"')
        // A sold Lead is not marked lost, and another proposal can still be added to it.
        ->assertDontSeeHtml('data-test="mark-lead-lost"')
        ->assertSeeHtml('data-test="add-proposal"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Sold);
});

test('lost asks for the reason and the objection, and for the competitor only when the reason asks', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openResult', $this->proposal->id, 'lost')
        ->assertSee('Mark this proposal lost?')
        ->assertDontSeeHtml('data-test="result-lost-to"')
        ->call('saveResult')
        ->assertHasErrors('lossReasonId')
        ->assertSee('Choose why it was lost.')
        ->set('result.lossReasonId', (string) $this->competitor->id)
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="result-lost-to"')
        ->set('result.lostTo', 'Brightside Painters')
        ->call('saveResult')
        ->assertHasErrors('objectionId')
        ->set('result.objectionId', (string) $this->otherObjection->id)
        ->assertSee('Note (required)')
        ->call('saveResult')
        ->assertHasErrors('resultNote')
        ->set('result.note', 'Their neighbour does painting.')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSee('Lost: Chose Another Contractor')
        ->assertSee('Went with Brightside Painters.')
        ->assertSee('Main objection: Other (see note).')
        ->assertSee('Their neighbour does painting.')
        ->assertDontSeeHtml('data-test="proposal-sold"');

    // One lost proposal does not make the Lead lost.
    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('still pending writes the objection down and leaves the proposal open', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openResult', $this->proposal->id, 'pending')
        ->assertSee('Still pending: what is holding it up?')
        ->call('saveResult')
        ->assertHasErrors('objectionId')
        ->set('result.objectionId', (string) $this->thinking->id)
        ->assertSee('Note (optional)')
        ->call('saveResult')
        ->assertHasNoErrors()
        ->assertSee('Pending')
        ->assertSee('Main objection: Wants to think it over.')
        ->assertSeeHtml('data-test="proposal-sold"');

    expect($this->proposal->fresh()->result)->toBe(ProposalResult::Pending);
});

test('the card says whether a return visit is booked for what is still pending', function () {
    proposalsCard($this->sam, $this->lead)->assertSee('No return visit is booked for what is still pending.');

    bookAppointment($this->lead, $this->sam, $this->sam, '2026-10-12', '10:00');

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSee('A return visit is booked for Mon, Oct 12, 2026 at 10:00 AM EDT.')
        // With an appointment still booked the Lead is not marked lost.
        ->assertDontSeeHtml('data-test="mark-lead-lost"');
});

test('a pending proposal is changed from the card', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openEdit', $this->proposal->id)
        ->assertSet('proposalName', 'Whole exterior')
        ->assertSet('proposalPrice', '8,450')
        ->set('proposalPrice', 'more')
        ->call('saveEdit')
        ->assertHasErrors('proposalPrice')
        ->set('proposalName', 'Whole exterior, two coats')
        ->set('proposalPrice', '9,100')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('Whole exterior, two coats')
        ->assertSee('$9,100')
        ->assertDontSee('$8,450');
});

test('a manager marks a proposal added in error from the card, and the appointment is again run with nothing presented', function () {
    proposalsCard($this->manager, $this->lead)
        ->assertDontSeeHtml('data-test="no-proposal-yet"')
        ->call('openAddedInError', $this->proposal->id)
        ->assertSee('Mark this proposal added in error?')
        ->call('markAddedInError')
        ->assertHasErrors('addedInErrorNote')
        ->set('addedInErrorNote', 'Sam added it to the wrong lead.')
        ->call('markAddedInError')
        ->assertHasNoErrors()
        ->assertSee('Added in error')
        ->assertSee('Marked by Morgan Manager')
        ->assertSee('Sam added it to the wrong lead.')
        ->assertSee('It counts for nothing.')
        ->assertSeeHtml('data-test="no-proposal-yet"')
        ->assertDontSeeHtml('data-test="proposal-sold"')
        ->assertDontSeeHtml('data-test="proposal-added-in-error-button"');
});

test('a manager corrects a result from the card, and the lead goes back to appointment run', function () {
    app(RecordProposalResult::class)->handle($this->lead, $this->proposal, 'sold', [], $this->sam);

    // The salesperson is not offered the correction.
    proposalsCard($this->sam, $this->lead->fresh())->assertDontSeeHtml('data-test="correct-proposal-result"');

    proposalsCard($this->manager, $this->lead->fresh())
        ->assertSeeHtml('data-test="correct-proposal-result"')
        ->call('openCorrect', $this->proposal->id)
        ->assertSee('Correct this result?')
        ->call('correct')
        ->assertHasErrors('correctionNote')
        ->set('correctionNote', 'Sam marked the wrong lead.')
        ->call('correct')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSee('Pending')
        ->assertDontSee('Sold for')
        ->assertSeeHtml('data-test="proposal-sold"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('the lead is marked lost from the card, and a manager undoes it there', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openLost')
        ->assertSee('Mark this lead lost?')
        ->call('markLost')
        ->assertHasErrors('lossReasonId')
        ->set('result.lossReasonId', (string) $this->price->id)
        ->set('result.objectionId', (string) $this->thinking->id)
        ->set('result.note', 'Two thousand over their budget.')
        ->call('markLost')
        ->assertHasNoErrors()
        ->assertDispatched('lead-appointment-changed')
        ->assertSeeInOrder(['Lead marked lost: Price', 'Main objection: Wants to think it over', 'Two thousand over their budget.', 'Recorded by Sam Sales'])
        ->assertSee('Lost: Price')
        // Finished: nothing more is offered, and only a manager can undo it.
        ->assertDontSeeHtml('data-test="add-proposal"')
        ->assertDontSeeHtml('data-test="mark-lead-lost"')
        ->assertDontSeeHtml('data-test="proposal-sold"')
        ->assertDontSeeHtml('data-test="undo-lead-lost"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Lost);

    proposalsCard($this->manager, $this->lead->fresh())
        // A lost proposal on a lost Lead is put right by undoing the Lead.
        ->assertDontSeeHtml('data-test="correct-proposal-result"')
        ->assertSeeHtml('data-test="undo-lead-lost"')
        ->call('openUndoLost')
        ->assertSee('This lead is not lost?')
        ->call('undoLost')
        ->assertHasErrors('undoLostNote')
        ->set('undoLostNote', 'The customer came back.')
        ->call('undoLost')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="lead-lost"')
        ->assertSee('Pending')
        ->assertSeeHtml('data-test="proposal-sold"');

    expect($this->lead->fresh()->status)->toBe(LeadStatus::AppointmentRun);
});

test('a finished lead shows its status on the lead page and is offered nothing that would change it', function (Closure $finish, string $status) {
    $finish($this);

    $this->actingAs($this->owner)->get(route('leads.show', $this->lead))
        ->assertOk()
        ->assertSee($status)
        ->assertDontSee('data-test="book-appointment"', false)
        ->assertDontSee('data-test="send-lead-back"', false)
        ->assertDontSee('data-test="disqualify-lead"', false)
        ->assertDontSee('data-test="reopen-lead"', false)
        ->assertDontSee('data-test="route-lead"', false)
        ->assertDontSee('No appointment can be booked yet.');
})->with([
    'sold' => [fn ($test) => app(RecordProposalResult::class)->handle($test->lead, $test->proposal, 'sold', [], $test->sam), 'Sold'],
    'lost' => [fn ($test) => app(MarkLeadLost::class)->handle($test->lead, ['lossReasonId' => $test->price->id, 'objectionId' => $test->thinking->id], $test->sam), 'Lost'],
]);

test('objections are a list on lead and sales lists, starting with the one that needs a note', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.lists', ['list' => ManagedList::Objections->value])
        ->assertSee('Objections')
        ->assertSee('What held a customer back from buying')
        ->assertSee('Other (see note)')
        ->assertSee('Note required');
});
