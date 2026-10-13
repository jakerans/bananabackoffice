<?php

use App\Actions\Appointments\MoveAppointment;
use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\MarkProposalAddedInError;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;

/*
 * The first Sales figures, counted from what people recorded
 * (docs/slice-7-appointment-results-plan.md, step 7d; product-architecture.md,
 * 5.2; decisions 124, 125, 133 and 134). What a later correction does to a
 * month is in SaleAdjustmentsTest.
 *
 * Most tests use the worked month in tests/Pest.php, octoberOfSales():
 * October 2026 at Lakeview, Sam Sales with five Leads and Riley Rep with one.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);
});

/**
 * The figures for a month at a Location, for everyone or one salesperson.
 */
function figures(Location $location, string $month, ?User $salesperson = null): SalesFigures
{
    return SalesFigures::for($location, CarbonImmutable::parse($month.'-15 12:00:00', 'UTC'), $salesperson?->id);
}

test('a month of work is counted for everyone at the location', function () {
    octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    $october = figures($this->lakeview, '2026-10');

    // Six Leads were issued and one was taken back the same month, booked in error: five count.
    expect([$october->leadsIssued, $october->issuesVoided, $october->netLeadsIssued()])->toBe([6, 1, 5]);
    expect($october->appointmentsBooked)->toBe(5);
    expect($october->appointmentsRun)->toBe(3);
    expect($october->appointmentsPresented)->toBe(2);
    expect($october->appointmentsCancelled)->toBe(1);
    expect($october->appointmentsNoShow)->toBe(1);
    expect($october->leadsRun)->toBe(3);
    expect($october->leadsWon)->toBe(1);
    expect($october->salesMade)->toBe(1);
    expect($october->soldCents)->toBe(820000);
    expect([$october->winsCorrected, $october->salesCorrected, $october->correctedCents])->toBe([0, 0, 0]);

    // $8,200 over 5 Leads issued; 2 of 3 run appointments presented; 1 Lead won against 3 first run; 1 of 5 booked cancelled.
    expect($october->nsliCents())->toBe(164000);
    expect($october->demoRate())->toBe(67);
    expect($october->closeRate())->toBe(33);
    expect($october->cancellationRate())->toBe(20);
});

test('each salesperson\'s figures are their own', function () {
    octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    $sam = figures($this->lakeview, '2026-10', $this->sam);

    expect([$sam->netLeadsIssued(), $sam->appointmentsBooked, $sam->appointmentsRun, $sam->appointmentsPresented, $sam->appointmentsCancelled, $sam->appointmentsNoShow])->toBe([4, 4, 2, 1, 1, 1]);
    expect([$sam->leadsRun, $sam->leadsWon, $sam->soldCents, $sam->salesMade])->toBe([2, 1, 820000, 1]);
    expect([$sam->nsliCents(), $sam->demoRate(), $sam->closeRate(), $sam->cancellationRate()])->toBe([205000, 50, 50, 25]);

    $riley = figures($this->lakeview, '2026-10', $this->riley);

    expect([$riley->leadsIssued, $riley->appointmentsBooked, $riley->appointmentsRun, $riley->appointmentsPresented])->toBe([1, 1, 1, 1]);
    expect([$riley->leadsRun, $riley->leadsWon, $riley->soldCents, $riley->salesMade])->toBe([1, 0, 0, 0]);
    // A Lead issued and nothing sold yet is $0 a Lead, not "nothing to show".
    expect([$riley->nsliCents(), $riley->demoRate(), $riley->closeRate(), $riley->cancellationRate()])->toBe([0, 100, 0, 0]);
});

test('with nothing recorded the rates are empty, not zero', function () {
    $empty = figures($this->lakeview, '2026-10');

    expect([$empty->leadsIssued, $empty->appointmentsBooked, $empty->appointmentsRun, $empty->soldCents])->toBe([0, 0, 0, 0]);
    expect([$empty->nsliCents(), $empty->demoRate(), $empty->closeRate(), $empty->cancellationRate()])->toBe([null, null, null, null]);
});

test('a proposal added a day late moves the appointment into demo rate (decision 124)', function () {
    $leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    expect(figures($this->lakeview, '2026-10', $this->sam)->demoRate())->toBe(50);

    // Lead b was run on Oct 7 with nothing presented. Its proposal is added five days later.
    addProposal($leads['b'], $this->sam, ['name' => 'Deck', 'price' => '1800']);

    $sam = figures($this->lakeview, '2026-10', $this->sam);

    expect([$sam->appointmentsPresented, $sam->appointmentsRun, $sam->demoRate()])->toBe([2, 2, 100]);
});

test('three proposals with one sold are one win, and two sold to one lead are one win with both amounts', function () {
    $leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    // Lead e has "Cabinets only" pending. Two more are added; one is sold, and nothing counts against the others.
    $deck = addProposal($leads['e'], $this->riley, ['name' => 'Deck', 'price' => '1800']);
    addProposal($leads['e'], $this->riley, ['name' => 'Fence', 'price' => '900']);
    app(RecordProposalResult::class)->handle($leads['e']->fresh(), $deck, 'sold', [], $this->riley);

    $riley = figures($this->lakeview, '2026-10', $this->riley);

    expect([$riley->leadsRun, $riley->leadsWon, $riley->closeRate()])->toBe([1, 1, 100]);
    expect([$riley->soldCents, $riley->salesMade, $riley->appointmentsPresented])->toBe([180000, 1, 1]);

    // A second one is sold to the same Lead: still one win, two sales, and the revenue is both.
    app(RecordProposalResult::class)->handle($leads['e']->fresh(), $leads['e']->proposals()->where('name', 'Cabinets only')->sole(), 'sold', ['soldAmount' => '2,750'], $this->riley);

    $riley = figures($this->lakeview, '2026-10', $this->riley);

    expect([$riley->leadsWon, $riley->closeRate(), $riley->salesMade, $riley->soldCents])->toBe([1, 100, 2, 455000]);
    expect($riley->nsliCents())->toBe(455000);
});

test('a sale closed on a return visit is one win, in the month the proposal was accepted (decision 134)', function () {
    $leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    // Lead e was first run on Oct 12. Riley goes back on Nov 3 and it is sold that day.
    bookAppointment($leads['e'], $this->riley, $this->riley, '2026-11-03', '10:00');
    $this->travelTo('2026-11-03 15:00:00');
    runAppointment($leads['e']->fresh(), $this->riley);
    app(RecordProposalResult::class)->handle($leads['e']->fresh(), $leads['e']->proposals()->sole(), 'sold', [], $this->riley);

    $october = figures($this->lakeview, '2026-10', $this->riley);
    $november = figures($this->lakeview, '2026-11', $this->riley);

    // One Lead, two visits: it is first run in October, and won once, in November when it was accepted.
    expect([$october->leadsRun, $october->leadsWon, $october->closeRate()])->toBe([1, 0, 0]);
    expect([$november->leadsRun, $november->leadsWon, $november->netWins()])->toBe([0, 1, 1]);
    // With no Lead first run in November there is nothing to divide the win by.
    expect($november->closeRate())->toBeNull();

    // The visit and the money are counted when they happened.
    expect([$october->appointmentsRun, $november->appointmentsRun])->toBe([1, 1]);
    expect([$october->soldCents, $november->soldCents, $november->salesMade])->toBe([0, 300000, 1]);

    // No Lead was issued in November, so there is no NSLI for it; the return visit is not a new issue.
    expect([$november->leadsIssued, $november->nsliCents()])->toBe([0, null]);
});

test('what is taken back in the same month comes off it: a corrected sale, a proposal added in error, a lead that should never have been booked', function () {
    $leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    app(CorrectProposalResult::class)->handle($leads['a'], $leads['a']->proposals()->sole(), 'Marked on the wrong lead.', $this->manager);

    $sam = figures($this->lakeview, '2026-10', $this->sam);

    // The sale and its correction are both in October, so October comes to nothing.
    expect([$sam->soldCents, $sam->correctedCents, $sam->salesCorrected, $sam->winsCorrected])->toBe([820000, 820000, 1, 1]);
    expect([$sam->netSoldCents(), $sam->netWins(), $sam->nsliCents(), $sam->closeRate()])->toBe([0, 0, 0, 0]);
    // The appointment was still run and still has its proposal.
    expect([$sam->appointmentsRun, $sam->appointmentsPresented])->toBe([2, 1]);

    app(MarkProposalAddedInError::class)->handle($leads['a']->fresh(), $leads['a']->proposals()->sole(), 'It belongs to another lead.', $this->manager);

    expect(figures($this->lakeview, '2026-10', $this->sam)->appointmentsPresented)->toBe(0);
    expect(figures($this->lakeview, '2026-10', $this->sam)->demoRate())->toBe(0);

    // Lead b is sent back as one that should never have been booked: its issue stops counting.
    app(SendLeadBackToContactCenter::class)->handle($leads['b'], StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED, 'Renter, not the owner.', $this->manager);

    $sam = figures($this->lakeview, '2026-10', $this->sam);

    expect([$sam->leadsIssued, $sam->issuesVoided, $sam->netLeadsIssued()])->toBe([5, 2, 3]);
});

test('whose figure it is when an appointment is moved to another salesperson', function () {
    $lead = bookableLead($this->lakeview);

    $this->travelTo('2026-10-05 14:00:00');
    $appointment = bookAppointment($lead, $this->sam, $this->manager, '2026-10-06', '10:00')->appointment;
    app(MoveAppointment::class)->handle($lead->fresh(), $appointment, $this->riley, 'Sam is out sick.', $this->manager);

    $this->travelTo('2026-10-06 14:00:00');
    runAppointment($lead->fresh(), $this->riley);
    app(RecordProposalResult::class)->handle($lead->fresh(), addProposal($lead->fresh(), $this->riley), 'sold', [], $this->riley);

    $sam = figures($this->lakeview, '2026-10', $this->sam);
    $riley = figures($this->lakeview, '2026-10', $this->riley);

    // Decision 133: the issue stays with the salesperson it was issued to; the appointment goes to the
    // salesperson it is with, and the sale to the salesperson who has the Lead when it is sold.
    expect([$sam->leadsIssued, $sam->appointmentsRun, $sam->soldCents])->toBe([1, 0, 0]);
    expect([$riley->leadsIssued, $riley->appointmentsRun, $riley->leadsWon, $riley->soldCents])->toBe([0, 1, 1, 845000]);

    // For the Location as a whole nothing is lost or counted twice.
    $everyone = figures($this->lakeview, '2026-10');

    expect([$everyone->leadsIssued, $everyone->appointmentsRun, $everyone->soldCents, $everyone->nsliCents()])->toBe([1, 1, 845000, 845000]);
});

test('one location\'s figures never include another\'s', function () {
    octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);

    $harbor = Location::factory()->create(['name' => 'Harbor']);

    expect(figures($harbor, '2026-10')->leadsIssued)->toBe(0);
    expect(figures($harbor, '2026-10')->appointmentsBooked)->toBe(0);
    expect(figures($harbor, '2026-10')->soldCents)->toBe(0);
    // And a salesperson of Lakeview has nothing at Harbor.
    expect(figures($harbor, '2026-10', $this->sam)->leadsIssued)->toBe(0);
});

test('the month is the location\'s own calendar month', function () {
    $harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $harbor)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    // 8:00 pm on October 31 at Harbor is already November 1 in UTC.
    $this->travelTo('2026-11-01 03:00:00');
    bookAppointment(bookableLead($harbor), $harborSales, $owner, '2026-11-02', '10:00');

    $october = figures($harbor, '2026-10');
    $november = figures($harbor, '2026-11');

    // Issued in October by Harbor's clock, for an appointment in November.
    expect([$october->leadsIssued, $october->appointmentsBooked])->toBe([1, 0]);
    expect([$november->leadsIssued, $november->appointmentsBooked])->toBe([0, 1]);
});
