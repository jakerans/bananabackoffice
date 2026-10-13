<?php

use App\Actions\Proposals\EditProposal;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ContactCenterSettings;
use App\Models\Location;
use App\Models\Objection;
use App\Models\User;
use Livewire\Livewire;

/*
 * Where people see the clock on a pending proposal
 * (docs/slice-9-pending-rehash-plan.md, step 9a): the Proposals card on a
 * Lead, "Pending bids" on Sales > Calendar, and the number of days in
 * Settings > Contact Center.
 *
 * The appointment is on Thursday 8 October 2026 at 3:00 pm Eastern with Sam
 * Sales. Tests start at 4:35 pm that day, with the appointment run and "Whole
 * exterior" pending, valid until Thursday the 15th.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->lead = bookableLead($this->lakeview);
    $this->lead->account->forceFill(['display_name' => 'Pat Customer'])->save();
    bookAppointment($this->lead, $this->sam, $this->manager);

    $this->travelTo('2026-10-08 20:35:00');
    runAppointment($this->lead->fresh(), $this->sam);
    $this->proposal = addProposal($this->lead->fresh(), $this->sam);
    $this->lead = $this->lead->fresh();
});

/*
|--------------------------------------------------------------------------
| The Proposals card
|--------------------------------------------------------------------------
*/

test('the card shows how long each pending proposal has left, and when the lead leaves its salesperson', function () {
    proposalsCard($this->sam, $this->lead)
        ->assertSeeHtml('data-test="proposal-validity"')
        ->assertSee('Valid until Thu, Oct 15 · 7 days left')
        ->assertSeeHtml('data-test="pending-ownership"')
        ->assertSee('After Thu, Oct 15 this lead goes to the Contact Center for rehash, unless a return visit is booked or it is sold or lost.');

    // A second proposal with a later day holds the Lead until then.
    $deck = addProposal($this->lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);
    app(EditProposal::class)->handle($this->lead->fresh(), $deck, 'Deck', '1800', $this->sam, validUntil: '2026-10-22');

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSee('Valid until Thu, Oct 15 · 7 days left')
        ->assertSee('Valid until Thu, Oct 22 · 14 days left')
        ->assertSee('After Thu, Oct 22 this lead goes to the Contact Center for rehash');
});

test('the card says when a proposal is on its last day and when everything pending has expired', function () {
    $this->travelTo('2026-10-15 15:00:00');

    proposalsCard($this->sam, $this->lead)->assertSee('Valid until today, Thu, Oct 15 · last day');

    $this->travelTo('2026-10-18 15:00:00');

    proposalsCard($this->sam, $this->lead)
        ->assertSee('Expired 3 days ago, Thu, Oct 15')
        ->assertSee('Everything pending on this lead has expired. It is due to go to the Contact Center for rehash');
});

test('a proposal with a result has no clock, and a lead with a return visit booked is not leaving', function () {
    // With a return visit booked the Lead shows Appointment booked: the days are still shown, the line about leaving is not.
    bookAppointment($this->lead, $this->sam, $this->sam, '2026-10-20', '10:00');

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertSeeHtml('data-test="proposal-validity"')
        ->assertDontSeeHtml('data-test="pending-ownership"');

    cancelAppointment($this->lead->fresh(), $this->sam);
    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 16:00:00');
    runAppointment($this->lead->fresh(), $this->sam);
    app(RecordProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'sold', [], $this->sam);

    proposalsCard($this->sam, $this->lead->fresh())
        ->assertDontSeeHtml('data-test="proposal-validity"')
        ->assertDontSeeHtml('data-test="pending-ownership"');
});

test('the day is changed from the proposal\'s change dialog, and a refusal is shown at the day', function () {
    proposalsCard($this->sam, $this->lead)
        ->call('openEdit', $this->proposal->id)
        ->assertSet('proposalValidUntil', '2026-10-15')
        ->assertSee('You can move it up to 30 days ahead. A manager can set a later day.')
        ->set('proposalValidUntil', '2026-12-25')
        ->call('saveEdit')
        ->assertHasErrors(['proposalValidUntil'])
        ->assertSee('You can move it up to 30 days ahead, to Nov 7, 2026.')
        ->set('proposalValidUntil', '2026-10-25')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSee('Valid until Sun, Oct 25 · 17 days left');

    expect($this->proposal->fresh()->valid_until->toDateString())->toBe('2026-10-25');

    // A manager is not held to thirty days.
    proposalsCard($this->manager, $this->lead->fresh())
        ->call('openEdit', $this->proposal->id)
        ->assertDontSee('You can move it up to 30 days ahead.')
        ->set('proposalValidUntil', '2026-12-25')
        ->call('saveEdit')
        ->assertHasNoErrors();

    expect($this->proposal->fresh()->valid_until->toDateString())->toBe('2026-12-25');
});

/*
|--------------------------------------------------------------------------
| Sales > Calendar
|--------------------------------------------------------------------------
*/

test('the calendar lists the salesperson\'s pending bids, soonest to expire first', function () {
    app(RecordProposalResult::class)->handle($this->lead, $this->proposal, 'pending', [
        'objectionId' => Objection::query()->where('name', 'Wants to think it over')->sole()->id,
    ], $this->sam);

    // A second Lead of Sam's whose bid expires sooner.
    $sooner = bookableLead($this->lakeview);
    $sooner->account->forceFill(['display_name' => 'Quinn Customer'])->save();
    bookAppointment($sooner, $this->sam, $this->manager, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 16:00:00');
    runAppointment($sooner->fresh(), $this->sam);
    $cabinets = addProposal($sooner->fresh(), $this->sam, ['name' => 'Cabinets only', 'price' => '3200']);
    app(EditProposal::class)->handle($sooner->fresh(), $cabinets, 'Cabinets only', '3200', $this->sam, validUntil: '2026-10-11');

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')
        ->assertSeeHtml('data-test="calendar-pending"')
        ->assertSeeInOrder(['Pending bids', 'Quinn Customer', 'Cabinets only, $3,200', 'Valid until Sun, Oct 11 · 2 days left', 'Pat Customer', 'Whole exterior, $8,450', 'Main objection: Wants to think it over', 'Valid until Thu, Oct 15 · 6 days left'])
        // Their own, so nobody's name is needed.
        ->assertDontSeeHtml('data-test="calendar-pending-salesperson"');
});

test('a manager sees everyone\'s pending bids at their location, and another salesperson sees none of them', function () {
    Livewire::actingAs($this->manager)->test('pages::sales.calendar')
        ->assertSeeHtml('data-test="calendar-pending"')
        ->assertSee('Pat Customer')
        ->assertSeeHtml('data-test="calendar-pending-salesperson"')
        ->assertSee('Sam Sales');

    Livewire::actingAs($this->riley)->test('pages::sales.calendar')
        ->assertDontSeeHtml('data-test="calendar-pending"')
        ->assertDontSee('Pat Customer');

    // A manager of another Location sees nothing of Lakeview's.
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    Livewire::actingAs($elsewhere)->test('pages::sales.calendar')->assertDontSee('Pat Customer');
});

test('a lead with nothing pending, or with a return visit booked, is not among the pending bids', function () {
    bookAppointment($this->lead, $this->sam, $this->sam, '2026-10-20', '10:00');

    // Booked again, it is on the list of appointments instead.
    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertDontSeeHtml('data-test="calendar-pending"');

    cancelAppointment($this->lead->fresh(), $this->sam);
    bookAppointment($this->lead->fresh(), $this->sam, $this->sam, '2026-10-09', '10:00');
    $this->travelTo('2026-10-09 16:00:00');
    runAppointment($this->lead->fresh(), $this->sam);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertSeeHtml('data-test="calendar-pending"');

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $this->proposal, 'sold', [], $this->sam);

    Livewire::actingAs($this->sam)->test('pages::sales.calendar')->assertDontSeeHtml('data-test="calendar-pending"');
});

/*
|--------------------------------------------------------------------------
| Settings > Contact Center
|--------------------------------------------------------------------------
*/

test('the settings page shows the number of days a proposal is valid and saves a new one', function () {
    Livewire::actingAs($this->leader)->test('pages::settings.contact-center')
        ->assertSeeHtml('data-test="proposal-valid-section"')
        ->assertSet('proposalValidDays', '7')
        ->set('proposalValidDays', '10')
        ->call('saveProposalValidDays')
        ->assertHasNoErrors()
        ->assertSet('proposalValidDays', '10');

    expect(ContactCenterSettings::current()->proposal_valid_days)->toBe(10);
    expect(AuditEvent::query()->where('action', AuditAction::ContactCenterProposalValidDaysChanged)->count())->toBe(1);
    // The proposal that was already pending keeps its day.
    expect($this->proposal->fresh()->valid_until->toDateString())->toBe('2026-10-15');
});

test('a number of days outside 1 to 90 is refused at its field', function (string $days) {
    Livewire::actingAs($this->leader)->test('pages::settings.contact-center')
        ->set('proposalValidDays', $days)
        ->call('saveProposalValidDays')
        ->assertHasErrors(['proposalValidDays'])
        ->assertSee('The number of days must be a whole number from 1 to 90.');

    expect(ContactCenterSettings::current()->proposal_valid_days)->toBe(7);
})->with(['0', '91', 'a week']);
