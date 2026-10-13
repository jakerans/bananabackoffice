<?php

use App\Actions\Appointments\SendLeadBackToContactCenter;
use App\Actions\Appointments\StopCountingLeadAsIssued;
use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Project;
use App\Models\SaleEntry;
use App\Models\User;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * Whose a sale is, which month it counts in, and what a later correction does
 * (decisions 133 and 134).
 *
 * - A sale and its win belong to the salesperson who had the Lead when the
 *   proposal was accepted, in the month it was accepted.
 * - A month that has passed is not rewritten: a sale corrected later, or a Lead
 *   issued that is taken back later, comes off the month it was corrected in.
 *
 * The month is the worked one in tests/Pest.php, octoberOfSales(): October
 * 2026 at Lakeview. Lead "a" was sold by Sam Sales on October 6 for $8,200.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);
});

/**
 * The figures for a month at Lakeview, for everyone or one salesperson.
 */
function monthOf(string $month, ?User $salesperson = null): SalesFigures
{
    return SalesFigures::for(test()->lakeview, CarbonImmutable::parse($month.'-15 12:00:00', 'UTC'), $salesperson?->id);
}

test('a sale is written down once, for the salesperson who had the lead, when the proposal was accepted', function () {
    $proposal = $this->leads['a']->proposals()->sole();
    $entry = SaleEntry::query()->sole();

    expect($entry->only(['proposal_id', 'lead_id', 'project_id', 'location_id', 'salesperson_id', 'salesperson_name', 'kind', 'cents', 'is_lead_win', 'recorded_by', 'recorded_by_name', 'note']))->toBe([
        'proposal_id' => $proposal->id,
        'lead_id' => $this->leads['a']->id,
        'project_id' => Project::query()->where('proposal_id', $proposal->id)->sole()->id,
        'location_id' => $this->lakeview->id,
        'salesperson_id' => $this->sam->id,
        'salesperson_name' => 'Sam Sales',
        'kind' => SaleEntry::SALE,
        'cents' => 820000,
        'is_lead_win' => true,
        'recorded_by' => $this->sam->id,
        'recorded_by_name' => 'Sam Sales',
        'note' => null,
    ]);
    expect($entry->occurred_at->toDateTimeString())->toBe('2026-10-06 14:00:00');
});

test('a manager typing the sale in for a salesperson still gives the salesperson the win (decision 134)', function () {
    // Lead e is Riley's. The manager marks its proposal sold.
    app(RecordProposalResult::class)->handle($this->leads['e'], $this->leads['e']->proposals()->sole(), 'sold', [], $this->manager);

    $entry = SaleEntry::query()->where('lead_id', $this->leads['e']->id)->sole();

    expect($entry->only(['salesperson_id', 'salesperson_name', 'recorded_by_name']))
        ->toBe(['salesperson_id' => $this->riley->id, 'salesperson_name' => 'Riley Rep', 'recorded_by_name' => 'Morgan Manager']);

    $riley = monthOf('2026-10', $this->riley);

    expect([$riley->leadsWon, $riley->soldCents, $riley->closeRate()])->toBe([1, 300000, 100]);
});

test('the win follows whoever has the lead when it is sold, not whoever ran the first visit (decision 133)', function () {
    // Lead b was Sam's and was run by Sam on October 7. The return visit is booked with Riley, who closes it in November.
    bookAppointment($this->leads['b'], $this->riley, $this->manager, '2026-11-03', '10:00');

    expect($this->leads['b']->fresh()->assigned_user_id)->toBe($this->riley->id);

    $this->travelTo('2026-11-03 16:00:00');
    runAppointment($this->leads['b']->fresh(), $this->riley);
    app(RecordProposalResult::class)->handle($this->leads['b']->fresh(), addProposal($this->leads['b']->fresh(), $this->riley, ['name' => 'Deck', 'price' => '1800']), 'sold', [], $this->riley);

    // Sam ran the first visit: it stays in Sam's October, with no win for it.
    $sam = monthOf('2026-10', $this->sam);

    expect([$sam->leadsRun, $sam->leadsWon])->toBe([2, 1]);
    expect([monthOf('2026-11', $this->sam)->leadsWon, monthOf('2026-11', $this->sam)->soldCents])->toBe([0, 0]);

    // Riley had the Lead when it was sold, in November.
    $riley = monthOf('2026-11', $this->riley);

    expect([$riley->leadsWon, $riley->salesMade, $riley->soldCents])->toBe([1, 1, 180000]);
    expect(monthOf('2026-10', $this->riley)->leadsWon)->toBe(0);
});

test('a sale corrected in a later month stays in its month and comes off the month it was corrected in', function () {
    $october = monthOf('2026-10', $this->sam);

    $this->travelTo('2026-11-04 15:00:00');
    app(CorrectProposalResult::class)->handle($this->leads['a'], $this->leads['a']->proposals()->sole(), 'The customer backed out before signing.', $this->manager);

    // October reads exactly as it did.
    expect(monthOf('2026-10', $this->sam))->toEqual($october);
    expect([$october->soldCents, $october->leadsWon, $october->closeRate(), $october->nsliCents()])->toBe([820000, 1, 50, 205000]);

    // November carries the correction, for the same salesperson.
    $november = monthOf('2026-11', $this->sam);

    expect([$november->soldCents, $november->salesMade, $november->leadsWon])->toBe([0, 0, 0]);
    expect([$november->correctedCents, $november->salesCorrected, $november->winsCorrected])->toBe([820000, 1, 1]);
    expect([$november->netSoldCents(), $november->netWins()])->toBe([-820000, -1]);
    // Nothing was issued or first run in November, so there is no rate to show.
    expect([$november->nsliCents(), $november->closeRate()])->toBe([null, null]);

    // The record: the sale as it was, and a second line taking it back.
    $entries = SaleEntry::query()->where('lead_id', $this->leads['a']->id)->orderBy('id')->get();

    expect($entries->pluck('kind')->all())->toBe([SaleEntry::SALE, SaleEntry::CORRECTION]);
    expect($entries[1]->only(['salesperson_id', 'location_id', 'project_id', 'cents', 'is_lead_win', 'recorded_by_name', 'note']))->toBe([
        'salesperson_id' => $this->sam->id,
        'location_id' => $this->lakeview->id,
        'project_id' => $entries[0]->project_id,
        'cents' => -820000,
        'is_lead_win' => true,
        'recorded_by_name' => 'Morgan Manager',
        'note' => 'The customer backed out before signing.',
    ]);
    expect($entries[1]->occurred_at->toDateTimeString())->toBe('2026-11-04 15:00:00');

    // Sold again that month, for less: November nets the difference and the win is back.
    app(RecordProposalResult::class)->handle($this->leads['a']->fresh(), $this->leads['a']->proposals()->sole(), 'sold', ['soldAmount' => '7,900'], $this->sam);

    $november = monthOf('2026-11', $this->sam);

    expect([$november->soldCents, $november->correctedCents, $november->netSoldCents()])->toBe([790000, 820000, -30000]);
    expect([$november->leadsWon, $november->winsCorrected, $november->netWins()])->toBe([1, 1, 0]);
    // The Project that was cancelled is the one brought back.
    expect(SaleEntry::query()->where('lead_id', $this->leads['a']->id)->pluck('project_id')->unique()->count())->toBe(1);
});

test('a second proposal sold later adds revenue, not a win, and correcting one of two sales leaves the win', function () {
    // Lead a was won in October. A second proposal is sold to it in November.
    $this->travelTo('2026-11-05 15:00:00');
    $deck = addProposal($this->leads['a'], $this->sam, ['name' => 'Deck', 'price' => '1800']);
    app(RecordProposalResult::class)->handle($this->leads['a']->fresh(), $deck, 'sold', [], $this->sam);

    $november = monthOf('2026-11', $this->sam);

    expect([$november->salesMade, $november->soldCents, $november->leadsWon])->toBe([1, 180000, 0]);
    expect(monthOf('2026-10', $this->sam)->leadsWon)->toBe(1);

    // The first sale is corrected in December. The Lead is still sold, so its win stands.
    $this->travelTo('2026-12-02 15:00:00');
    app(CorrectProposalResult::class)->handle($this->leads['a']->fresh(), $this->leads['a']->proposals()->where('name', 'Whole exterior')->sole(), 'Scope moved to the deck job.', $this->manager);

    $december = monthOf('2026-12', $this->sam);

    expect([$december->correctedCents, $december->salesCorrected, $december->winsCorrected, $december->netWins()])->toBe([820000, 1, 0, 0]);
});

test('a lead issued that is taken back in a later month comes off that month, not the one it was issued in', function () {
    $october = monthOf('2026-10', $this->sam);

    // Lead b, issued on October 5, is sent back in November as one that should never have been booked.
    $this->travelTo('2026-11-02 15:00:00');
    app(SendLeadBackToContactCenter::class)->handle($this->leads['b'], StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED, 'Renter, not the owner.', $this->manager);

    expect(monthOf('2026-10', $this->sam))->toEqual($october);
    expect([$october->leadsIssued, $october->issuesVoided, $october->netLeadsIssued()])->toBe([5, 1, 4]);

    $november = monthOf('2026-11', $this->sam);

    expect([$november->leadsIssued, $november->issuesVoided, $november->netLeadsIssued(), $november->nsliCents()])->toBe([0, 1, -1, null]);
});

test('the performance page shows a later month\'s corrections as what they are', function () {
    $this->travelTo('2026-11-04 15:00:00');
    app(CorrectProposalResult::class)->handle($this->leads['a'], $this->leads['a']->proposals()->sole(), 'The customer backed out before signing.', $this->manager);
    app(SendLeadBackToContactCenter::class)->handle($this->leads['b'], StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED, 'Renter, not the owner.', $this->manager);

    $page = Livewire::actingAs($this->manager)->test('pages::sales.performance');
    $line = fn (string $figure): string => (string) str($page->html())->after('data-test="figure-'.$figure.'"')->before('class="rounded-xl')->after('data-test="figure-detail"')->after('>')->before('</div>')->squish();
    $value = fn (string $figure): string => (string) str($page->html())->after('data-test="figure-'.$figure.'"')->after('data-test="figure-value"')->after('>')->before('<')->trim();

    $page->assertSee('Everyone at Lakeview, November 2026');

    expect([$value('sold-revenue'), $line('sold-revenue')])->toBe(['-$8,200', 'Nothing was sold this month. $8,200 was taken back by 1 correction this month.']);
    expect([$value('close-rate'), $line('close-rate')])->toBe(['—', 'No lead had its first appointment run this month. 1 win was taken back by a correction this month.']);
    expect([$value('leads-issued'), $line('leads-issued')])->toBe(['-1', '0 handed to Sales this month, less 1 taken back this month.']);
    expect([$value('nsli'), $line('nsli')])->toBe(['—', 'No leads were issued this month.']);

    // October still reads as it did when it ended.
    $page->set('month', '2026-10');

    expect([$value('sold-revenue'), $line('sold-revenue')])->toBe(['$8,200', 'From 1 sale this month.']);
    expect([$value('close-rate'), $line('close-rate')])->toBe(['33%', '1 lead won this month, against 3 leads first run this month.']);
    expect([$value('leads-issued'), $line('leads-issued')])->toBe(['5', '6 handed to Sales this month, less 1 taken back this month.']);
});

test('a sale entry is history: it cannot be changed or deleted', function () {
    $entry = SaleEntry::query()->sole();

    expect(fn () => $entry->forceFill(['cents' => 1])->save())->toThrow(LogicException::class, 'A sale entry is history and cannot be edited.');
    expect(fn () => $entry->delete())->toThrow(LogicException::class, 'A sale entry cannot be deleted.');
    expect(SaleEntry::query()->sole()->cents)->toBe(820000);
});

test('sales made before the record existed are written into it once, the first for each lead as its win', function () {
    $this->travelTo('2026-10-14 15:00:00');
    $deck = addProposal($this->leads['a'], $this->sam, ['name' => 'Deck', 'price' => '1800']);
    app(RecordProposalResult::class)->handle($this->leads['a']->fresh(), $deck, 'sold', [], $this->manager);

    $asRecorded = SaleEntry::query()->orderBy('id')->get()->map->only(['proposal_id', 'lead_id', 'project_id', 'location_id', 'salesperson_id', 'salesperson_name', 'kind', 'cents', 'is_lead_win', 'recorded_by', 'recorded_by_name'])->all();
    $october = monthOf('2026-10');

    // As it was before this step: sold proposals and their Projects, and no record of the sales.
    DB::table('sale_entries')->delete();

    $step = require database_path('migrations/2026_10_13_153613_create_sale_entries_table.php');
    $step->up();

    $filled = SaleEntry::query()->orderBy('id')->get();

    expect($filled->map->only(array_keys($asRecorded[0]))->all())->toBe($asRecorded);
    expect($filled->pluck('is_lead_win')->all())->toBe([true, false]);
    expect($filled->map(fn (SaleEntry $entry): string => $entry->occurred_at->toDateTimeString())->all())->toBe(['2026-10-06 14:00:00', '2026-10-14 15:00:00']);
    expect(monthOf('2026-10'))->toEqual($october);

    // Run again, it writes nothing more.
    $step->up();

    expect(SaleEntry::query()->count())->toBe(2);
});
