<?php

use App\Actions\Marketing\RecordMarketingSpend;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\ContactAttemptDirection;
use App\Enums\Role;
use App\Models\ContactAttempt;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\User;
use App\Support\Performance\MarketingBoard;
use App\Support\Reports\MarketingReport;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Marketing > Reports, step 32c (docs/slice-32-reports-plan.md;
 * product-architecture.md, 4.6 and 21; decision 222): by source, the spend,
 * leads and what they became, month by month, and speed to lead.
 *
 * At Lakeview, in Eastern time, October 2026: $1,000 spent on Google. Two
 * Google leads came in, both first heard of on Facebook. The first was called
 * 3 minutes later and reached, booked, ran and sold for $8,450. The second
 * was called after 40 minutes and not reached.
 */

beforeEach(function () {
    $this->travelTo('2026-10-02 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->google = LeadSource::factory()->create(['name' => 'Google']);
    $this->facebook = MarketingSource::factory()->create(['name' => 'Facebook']);

    app(RecordMarketingSpend::class)->record($this->lakeview, $this->google->id, '2026-10', '1000', null, $this->owner);

    $this->reached = bookableLead($this->lakeview, attributes: ['lead_source_id' => $this->google->id, 'marketing_source_id' => $this->facebook->id, 'received_at' => now()]);
    ContactAttempt::factory()->create(['lead_id' => $this->reached->id, 'location_id' => $this->lakeview->id, 'occurred_at' => now()->addMinutes(3), 'was_connected' => true]);

    $this->unreached = bookableLead($this->lakeview, attributes: ['lead_source_id' => $this->google->id, 'marketing_source_id' => $this->facebook->id, 'received_at' => now()]);
    ContactAttempt::factory()->create(['lead_id' => $this->unreached->id, 'location_id' => $this->lakeview->id, 'occurred_at' => now()->addMinutes(40), 'was_connected' => false, 'was_answered' => false]);
    ContactAttempt::factory()->create(['lead_id' => $this->unreached->id, 'location_id' => $this->lakeview->id, 'occurred_at' => now()->addMinutes(1), 'direction' => ContactAttemptDirection::Inbound, 'was_connected' => false]);

    bookAppointment($this->reached, $this->sam, $this->manager, '2026-10-08', '15:00');

    $this->travelTo('2026-10-08 21:00:00');
    runAppointment($this->reached->fresh(), $this->sam);
    $estimate = estimateFor($this->reached->fresh(), $this->sam);
    $proposal = presentEstimate($estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->reached->fresh(), $proposal, 'sold', ['optionId' => $estimate->options->first()->id], $this->sam);

    $this->travelTo('2026-10-31 15:00:00');
});

test('a month by Lead Source reads as Marketing > Performance reads it', function () {
    Livewire::actingAs($this->manager)->test('pages::marketing.reports')
        ->assertOk()
        ->assertSee('This month: Oct 1, 2026 to Oct 31, 2026, at Lakeview.')
        ->assertSeeInOrder(['Spend', '$1,000', 'Leads', '2', 'Cost per lead', '$500', 'Booking rate', '50%', 'Appointments', '1', 'Cost per sale', '$1,000', 'Sold revenue', '$8,450', 'Return on spend', '$8.45 per $1'])
        ->assertSeeInOrder(['Google', '$1,000', '2', '$500', '50%', '1', '50%', '$1,000', '1', '100%']);

    $board = MarketingBoard::for(collect([$this->lakeview]), '2026-10')->total();
    $report = MarketingReport::for(collect([$this->lakeview]), [$this->lakeview->id => SalesFigures::monthAt($this->lakeview, CarbonImmutable::parse('2026-10-15'))])->total();

    expect([$report->spendCents, $report->leads, $report->appointments, $report->sales, $report->soldCents])
        ->toBe([$board->spendCents, $board->leads, $board->appointments, $board->sales, $board->soldCents]);
});

test('by Marketing Source it shows what customers said brought them, without spend', function () {
    Livewire::actingAs($this->manager)->test('pages::marketing.reports', ['by' => 'marketing_source'])
        ->assertSee('Spend is entered by Lead Source')
        ->assertSeeInOrder(['Marketing Source', 'Leads', 'Facebook', '2', '50%', '1']);
});

test('part of a month counts that share of its spend', function () {
    // The first 10 of October's 31 days.
    $report = MarketingReport::for(collect([$this->lakeview]), [$this->lakeview->id => [CarbonImmutable::parse('2026-10-01', 'America/New_York')->utc(), CarbonImmutable::parse('2026-10-11', 'America/New_York')->utc()]]);

    expect($report->total()->spendCents)->toBe((int) round(100000 * 10 / 31));
});

test('speed to lead is the time to the first call out, and connection rate the leads reached', function () {
    $report = MarketingReport::for(collect([$this->lakeview]), [$this->lakeview->id => SalesFigures::monthAt($this->lakeview, CarbonImmutable::parse('2026-10-15'))]);
    $speed = $report->speedToLead();

    expect($speed['bands']['Within 5 minutes'])->toBe(1)
        ->and($speed['bands']['30 minutes to 2 hours'])->toBe(1)
        ->and($speed['never'])->toBe(0)
        ->and($speed['median'])->toBe(21)
        ->and($report->total()->connectionRate())->toBe(50);

    Livewire::actingAs($this->manager)->test('pages::marketing.reports')
        ->assertSee('1 of 2 were reached (50%)');
});

test('the tables download as spreadsheets of figures', function (string $table, string $expected) {
    $download = Livewire::actingAs($this->manager)->test('pages::marketing.reports', ['range' => 'last_12_months'])
        ->call('download', $table)
        ->assertFileDownloaded();

    expect(base64_decode($download->effects['download']['content']))->toContain($expected);
})->with([
    'by source' => ['sources', 'Google,1000.00,2,500.00,50%,1,50%,1000.00,1,100%,1000.00,8450.00,8.45'],
    'month by month' => ['months', '2026-10,1000.00,2'],
    'speed to lead' => ['speed', '"Within 5 minutes",1'],
]);

test('Marketing > Reports is in the menu and for those who may view reports only', function () {
    $this->actingAs($this->manager)->get(route('marketing.performance'))->assertSee(route('marketing.reports'), false);

    $this->actingAs($this->sam)->get(route('marketing.reports'))->assertForbidden();
});
