<?php

use App\Actions\Appointments\MarkAppointmentBookedInError;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\PresentProposalAgain;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Queue\RunQueueTick;
use App\Enums\LeadStatusEvent;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Objection;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The Pending figures on Sales > Performance
 * (docs/slice-9-pending-rehash-plan.md, step 9c; product-architecture.md, 5.2;
 * decision 137, answer 8): Pending Rate, return visit booked at the
 * appointment, and Rehash conversion.
 *
 * The month is the worked one in tests/Pest.php, octoberOfSales(): October
 * 2026 at Lakeview, ending at 10:00 am on the 12th. Of its Leads, "a" was
 * demoed by Sam Sales on the 6th and sold that day; "b" was run by Sam on the
 * 7th with nothing presented; "e" was demoed by Riley Rep on the 12th and is
 * still pending.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(['name' => 'Casey Caller']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->price = SalesLossReason::query()->where('name', 'Price')->sole();
    $this->thinking = Objection::query()->where('name', 'Wants to think it over')->sole();

    $this->leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);
});

/**
 * The figures for a month at Lakeview, for everyone or one salesperson.
 */
function pendingFigures(string $month, ?User $salesperson = null): SalesFigures
{
    return SalesFigures::for(test()->lakeview, CarbonImmutable::parse($month.'-15 12:00:00', 'UTC'), $salesperson?->id);
}

/**
 * A Lead of Sam's demoed on October 13 at 10:00 am, with "Whole exterior"
 * presented. The clock is left at 10:30 am that day.
 */
function demoedOnThe13th($test): Lead
{
    $lead = bookableLead($test->lakeview);

    $test->travelTo('2026-10-12 15:00:00');
    bookAppointment($lead, $test->sam, $test->manager, '2026-10-13', '10:00');

    $test->travelTo('2026-10-13 14:30:00');
    runAppointment($lead->fresh(), $test->sam);
    addProposal($lead->fresh(), $test->sam);

    return $lead->fresh();
}

/*
|--------------------------------------------------------------------------
| Pending Rate
|--------------------------------------------------------------------------
*/

test('pending rate is the leads that left their first demo with nothing sold and something still open', function () {
    $october = pendingFigures('2026-10');

    // Two Leads were demoed: one sold at the demo, one left it pending. The Lead with nothing presented is in neither.
    expect([$october->leadsDemoed, $october->leadsLeftPending, $october->pendingRate()])->toBe([2, 1, 50]);

    $sam = pendingFigures('2026-10', $this->sam);
    $riley = pendingFigures('2026-10', $this->riley);

    expect([$sam->leadsDemoed, $sam->leadsLeftPending, $sam->pendingRate()])->toBe([1, 0, 0]);
    expect([$riley->leadsDemoed, $riley->leadsLeftPending, $riley->pendingRate()])->toBe([1, 1, 100]);
});

test('a lead sold a week later still left its demo pending', function () {
    $this->travelTo('2026-10-19 15:00:00');
    app(RecordProposalResult::class)->handle($this->leads['e'], $this->leads['e']->proposals()->sole(), 'sold', [], $this->riley);

    $riley = pendingFigures('2026-10', $this->riley);

    expect([$riley->leadsLeftPending, $riley->pendingRate()])->toBe([1, 100]);
    // And it is a win all the same.
    expect($riley->closeRate())->toBe(100);
});

test('a lead whose bid was lost at the demo did not leave it pending, and one with a bid lost and a bid open did', function () {
    $lead = demoedOnThe13th($this);

    app(RecordProposalResult::class)->handle($lead, $lead->proposals()->sole(), 'lost', ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->sam);

    $sam = pendingFigures('2026-10', $this->sam);

    expect([$sam->leadsDemoed, $sam->leadsLeftPending])->toBe([2, 0]);

    // A second thing pitched at the same visit is still open: the Lead is pending.
    addProposal($lead->fresh(), $this->sam, ['name' => 'Deck', 'price' => '1800']);

    expect(pendingFigures('2026-10', $this->sam)->leadsLeftPending)->toBe(1);
});

test('a proposal lost the day after the demo does not change that the lead left it pending', function () {
    $lead = demoedOnThe13th($this);

    $this->travelTo('2026-10-14 15:00:00');
    app(RecordProposalResult::class)->handle($lead, $lead->proposals()->sole(), 'lost', ['lossReasonId' => $this->price->id, 'objectionId' => $this->thinking->id], $this->sam);

    expect(pendingFigures('2026-10', $this->sam)->leadsLeftPending)->toBe(1);
});

test('a sale corrected in a later month does not make the month of the demo pending', function () {
    // Lead a was sold at its demo on October 6. The sale is corrected in November.
    $this->travelTo('2026-11-04 15:00:00');
    app(CorrectProposalResult::class)->handle($this->leads['a'], $this->leads['a']->proposals()->sole(), 'The customer backed out.', $this->manager);

    // October is not rewritten (decision 133).
    expect(pendingFigures('2026-10', $this->sam)->leadsLeftPending)->toBe(0);
});

test('a sale made and corrected on the day of the demo leaves the lead pending', function () {
    $lead = demoedOnThe13th($this);

    app(RecordProposalResult::class)->handle($lead, $lead->proposals()->sole(), 'sold', [], $this->sam);

    expect(pendingFigures('2026-10', $this->sam)->leadsLeftPending)->toBe(0);

    app(CorrectProposalResult::class)->handle($lead->fresh(), $lead->proposals()->sole(), 'Marked on the wrong lead.', $this->manager);

    expect(pendingFigures('2026-10', $this->sam)->leadsLeftPending)->toBe(1);
});

test('a proposal typed in days late makes the lead demoed, and pending', function () {
    // Lead b was run on the 7th with nothing presented. Its proposal is typed in on the 12th.
    addProposal($this->leads['b'], $this->sam, ['name' => 'Deck', 'price' => '1800']);

    $sam = pendingFigures('2026-10', $this->sam);

    expect([$sam->leadsDemoed, $sam->leadsLeftPending, $sam->pendingRate()])->toBe([2, 1, 50]);
});

/*
|--------------------------------------------------------------------------
| Return visit booked
|--------------------------------------------------------------------------
*/

test('a return visit booked the day of the demo is counted, for the leads that left pending', function () {
    $before = pendingFigures('2026-10', $this->riley);

    expect([$before->leadsLeftPending, $before->pendingWithReturnVisit, $before->returnVisitRate()])->toBe([1, 0, 0]);

    // Riley books the return visit an hour after the demo.
    $this->travelTo('2026-10-12 15:00:00');
    bookAppointment($this->leads['e'], $this->riley, $this->riley, '2026-10-20', '10:00');

    $riley = pendingFigures('2026-10', $this->riley);

    expect([$riley->leadsLeftPending, $riley->pendingWithReturnVisit, $riley->returnVisitRate()])->toBe([1, 1, 100]);

    // For everyone: one pending Lead, and it has its return visit.
    expect(pendingFigures('2026-10')->returnVisitRate())->toBe(100);
    // Sam has no pending Lead, so there is nothing to divide by.
    expect(pendingFigures('2026-10', $this->sam)->returnVisitRate())->toBeNull();
});

test('a return visit booked the next day, or booked in error, is not counted', function () {
    // Lead e: booked the day after its demo.
    $this->travelTo('2026-10-13 14:00:00');
    bookAppointment($this->leads['e'], $this->riley, $this->riley, '2026-10-20', '10:00');

    expect(pendingFigures('2026-10', $this->riley)->pendingWithReturnVisit)->toBe(0);

    // Another Lead: booked at the demo, then marked "booked in error".
    $lead = demoedOnThe13th($this);
    $visit = bookAppointment($lead, $this->sam, $this->sam, '2026-10-21', '10:00')->appointment;

    expect(pendingFigures('2026-10', $this->sam)->pendingWithReturnVisit)->toBe(1);

    app(MarkAppointmentBookedInError::class)->handle($lead->fresh(), $visit, 'Wrong day.', $this->manager);

    expect(pendingFigures('2026-10', $this->sam)->pendingWithReturnVisit)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Rehash conversion
|--------------------------------------------------------------------------
*/

test('rehash conversion is the leads released to rehash in the month that were sold afterwards', function () {
    // By the 25th the timer has released b (nothing presented) and e (its bid expired) to Rehash,
    // and c and d, whose appointments fell through, to Cancelled Appointments.
    $this->travelTo('2026-10-25 15:00:00');

    expect(app(RunQueueTick::class)->handle()['released'])->toBe(4);
    expect(LeadStatusChange::query()->where('event', LeadStatusEvent::ReleasedToRehash)->pluck('lead_id')->all())->toEqualCanonicalizing([$this->leads['b']->id, $this->leads['e']->id]);

    $october = pendingFigures('2026-10');

    // Only the two that went to Rehash; a cancelled Lead that was released is not a rehash.
    expect([$october->leadsReleasedToRehash, $october->rehashSold, $october->rehashConversion()])->toBe([2, 0, 0]);
    expect(pendingFigures('2026-10', $this->sam)->leadsReleasedToRehash)->toBe(1);
    expect(pendingFigures('2026-10', $this->riley)->leadsReleasedToRehash)->toBe(1);

    // The call center books e a return visit with Sam, who presents the same proposal again and sells it in November.
    bookAppointment($this->leads['e']->fresh(), $this->sam, $this->agent, '2026-11-03', '10:00');
    $this->travelTo('2026-11-03 16:00:00');
    $visit = runAppointment($this->leads['e']->fresh(), $this->sam);
    app(PresentProposalAgain::class)->handle($this->leads['e']->fresh(), $this->leads['e']->proposals()->sole(), $visit->id, $this->sam);
    app(RecordProposalResult::class)->handle($this->leads['e']->fresh(), $this->leads['e']->proposals()->sole(), 'sold', [], $this->sam);

    // October's conversion goes up: the Lead went to Rehash in October and has been sold since.
    $october = pendingFigures('2026-10');

    expect([$october->leadsReleasedToRehash, $october->rehashSold, $october->rehashConversion()])->toBe([2, 1, 50]);

    // It is counted for the salesperson it was released from, whoever sold it.
    $riley = pendingFigures('2026-10', $this->riley);
    $sam = pendingFigures('2026-10', $this->sam);

    expect([$riley->leadsReleasedToRehash, $riley->rehashSold, $riley->rehashConversion()])->toBe([1, 1, 100]);
    expect([$sam->leadsReleasedToRehash, $sam->rehashSold, $sam->rehashConversion()])->toBe([1, 0, 0]);

    // Nothing went to Rehash in November.
    expect(pendingFigures('2026-11')->rehashConversion())->toBeNull();
    // The sale itself is Sam's, in November (decision 134).
    expect([pendingFigures('2026-11', $this->sam)->leadsWon, pendingFigures('2026-11', $this->riley)->leadsWon])->toBe([1, 0]);
});

test('a lead a manager sends back by hand is not a rehash', function () {
    app(SendLeadBackToContactCenter::class)->handle($this->leads['e'], StopCountingLeadAsIssued::ANOTHER_REASON, 'Riley is leaving.', $this->manager);

    expect(pendingFigures('2026-10')->leadsReleasedToRehash)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| With nothing to count, and on the page
|--------------------------------------------------------------------------
*/

test('with nothing recorded the three rates are empty, not zero', function () {
    $september = pendingFigures('2026-09');

    expect([$september->leadsLeftPending, $september->pendingWithReturnVisit, $september->leadsReleasedToRehash, $september->rehashSold])->toBe([0, 0, 0, 0]);
    expect([$september->pendingRate(), $september->returnVisitRate(), $september->rehashConversion()])->toBe([null, null, null]);
});

test('the performance page shows the three figures, each with how it was reached', function () {
    $page = Livewire::actingAs($this->manager)->test('pages::sales.performance');
    $card = fn (string $figure): array => [
        (string) str($page->html())->after('data-test="figure-'.$figure.'"')->after('data-test="figure-value"')->after('>')->before('<')->trim(),
        (string) str($page->html())->after('data-test="figure-'.$figure.'"')->after('data-test="figure-detail"')->after('>')->before('</div>')->squish(),
    ];

    expect($card('pending-rate'))->toBe(['50%', '1 of 2 leads first demoed this month left the demo still pending.']);
    expect($card('return-visit'))->toBe(['0%', '0 of 1 pending lead had a return visit booked the day of the demo.']);
    expect($card('rehash-conversion'))->toBe(['—', 'No lead went to rehash this month.']);

    $page->assertSee('Pending rate: of the leads demoed for the first time in the month')
        ->assertSee('Rehash conversion: of the leads that went from a salesperson to the Contact Center for rehash in the month');

    // A salesperson sees their own.
    $own = Livewire::actingAs($this->sam)->test('pages::sales.performance');

    expect((string) str($own->html())->after('data-test="figure-pending-rate"')->after('data-test="figure-detail"')->after('>')->before('</div>')->squish())
        ->toBe('0 of 1 lead first demoed this month left the demo still pending.');
});
