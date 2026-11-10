<?php

use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\User;
use App\Support\Reports\SalesReport;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Sales > Reports > Sales figures, step 32b (docs/slice-32-reports-plan.md;
 * product-architecture.md, 5.9; decision 222): Sales > Performance's figures
 * over any days, by salesperson and month by month, with lost reasons and
 * objections.
 *
 * At Lakeview, in Eastern time: Sam's appointment on 8 October 2026 ran and
 * sold for $8,450. Riley's on 15 September ran with an estimate that was lost.
 */

beforeEach(function () {
    $this->travelTo('2026-09-10 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Sales']);

    $this->lost = bookableLead($this->lakeview);
    bookAppointment($this->lost, $this->riley, $this->manager, '2026-09-15', '10:00');

    $this->travelTo('2026-09-15 18:00:00');
    runAppointment($this->lost->fresh(), $this->riley);
    $lostEstimate = estimateFor($this->lost->fresh(), $this->riley);
    $this->lostProposal = presentEstimate($lostEstimate, $this->riley);
    // What the after-action report recorded: the reason the lead was lost, and the objection heard.
    Proposal::query()->whereKey($this->lostProposal->id)->update(['objection_name' => 'Price too high', 'result_at' => now()]);
    Lead::query()->whereKey($this->lost->id)->update(['lost_at' => now(), 'lost_reason_name' => 'Went with a competitor']);

    $this->travelTo('2026-10-06 14:00:00');
    $this->sold = bookableLead($this->lakeview);
    bookAppointment($this->sold, $this->sam, $this->manager, '2026-10-08', '15:00');

    $this->travelTo('2026-10-08 21:00:00');
    runAppointment($this->sold->fresh(), $this->sam);
    $estimate = estimateFor($this->sold->fresh(), $this->sam);
    $proposal = presentEstimate($estimate, $this->sam);
    app(RecordProposalResult::class)->handle($this->sold->fresh(), $proposal, 'sold', ['optionId' => $estimate->options->first()->id], $this->sam);

    $this->travelTo('2026-10-20 15:00:00');
});

test('a month reads exactly as Sales > Performance reads it', function () {
    $performance = SalesFigures::for($this->lakeview, CarbonImmutable::parse('2026-10-01', 'America/New_York'));

    Livewire::actingAs($this->manager)->test('pages::sales.reports-figures')
        ->assertOk()
        ->assertSee('This month: Oct 1, 2026 to Oct 31, 2026, at Lakeview.')
        ->assertSeeInOrder(['NSLI', '$8,450', 'Sold revenue', '$8,450', 'Close Rate', '100%', 'Demo Rate', '100%']);

    $report = SalesReport::for(collect([$this->lakeview]), [$this->lakeview->id => SalesFigures::monthAt($this->lakeview, CarbonImmutable::parse('2026-10-01', 'America/New_York'))]);

    expect(get_object_vars($report->figures()))->toEqual(get_object_vars($performance))
        ->and($performance->netSoldCents())->toBe(845000);
});

test('over several months it shows each salesperson, month by month, and who sold most each month', function () {
    Livewire::actingAs($this->manager)->test('pages::sales.reports-figures', ['range' => 'last_12_months'])
        ->assertSeeHtml('data-test="figures-person-'.$this->sam->id.'"')
        ->assertSeeHtml('data-test="figures-person-'.$this->riley->id.'"')
        ->assertSeeInOrder(['By salesperson', 'Sam Sales', 'Riley Sales', 'Total'])
        ->assertSeeHtml('data-test="figures-month-2026-09"')
        ->assertSeeHtml('data-test="figures-month-2026-10"')
        ->assertSeeInOrder(['Sold revenue by month', '1. Sam Sales'])
        ->assertSeeInOrder(['Why leads were lost', 'Went with a competitor', '1', '100%'])
        ->assertSeeInOrder(['Objections heard', 'Price too high', '1']);

    $report = SalesReport::for(collect([$this->lakeview]), [$this->lakeview->id => [CarbonImmutable::parse('2026-09-01 04:00'), CarbonImmutable::parse('2026-11-01 04:00')]]);

    expect($report->bySalesperson()->get($this->riley->id)['figures']->appointmentsRun)->toBe(1)
        ->and($report->bySalesperson()->get($this->riley->id)['figures']->netSoldCents())->toBe(0)
        ->and($report->bySalesperson()->keys()->first())->toBe($this->sam->id);
});

test('two Locations are added together, each in its own days', function () {
    $harbor = Location::factory()->create(['name' => 'Harbor', 'timezone' => 'America/Los_Angeles']);
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $harbor)->create();
    $lead = bookableLead($harbor);
    bookAppointment($lead, $harborSales, $leader, '2026-10-21', '10:00');

    $october = fn (Location $location): SalesFigures => SalesFigures::for($location, CarbonImmutable::parse('2026-10-15'));

    Livewire::actingAs($leader)->test('pages::sales.reports-figures')
        ->assertSee('at 2 locations, each in its own time')
        ->assertSeeHtml('data-test="figures-person-'.$harborSales->id.'"');

    $both = SalesReport::for(collect([$this->lakeview, $harbor]), [
        $this->lakeview->id => SalesFigures::monthAt($this->lakeview, CarbonImmutable::parse('2026-10-15')),
        $harbor->id => SalesFigures::monthAt($harbor, CarbonImmutable::parse('2026-10-15')),
    ])->figures();

    expect($both->appointmentsBooked)->toBe($october($this->lakeview)->appointmentsBooked + $october($harbor)->appointmentsBooked)
        ->and($both->netSoldCents())->toBe(845000);
});

test('each table downloads as a spreadsheet of figures and staff names, never a customer', function (string $table, string $expected) {
    $download = Livewire::actingAs($this->manager)->test('pages::sales.reports-figures', ['range' => 'last_12_months'])
        ->call('download', $table)
        ->assertFileDownloaded();

    $csv = base64_decode($download->effects['download']['content']);

    expect($csv)->toContain($expected)
        ->not->toContain((string) $this->sold->fresh()->displayName());
})->with([
    'by salesperson' => ['salespeople', '"Sam Sales",1,1,100%'],
    'month by month' => ['months', '2026-10,'],
    'sold revenue by month' => ['leaderboard', '0.00,8450.00,8450.00'],
    'lost reasons' => ['lost', '"Went with a competitor",1'],
    'objections' => ['objections', '"Price too high",1'],
]);

test('it is for those who may view reports, at their own Locations only', function () {
    $elsewhere = Location::factory()->create(['name' => 'Elsewhere']);

    $this->actingAs($this->sam)->get(route('sales.reports.figures'))->assertForbidden();

    Livewire::actingAs($this->manager)->test('pages::sales.reports-figures', ['locationId' => (string) $elsewhere->id])
        ->assertSee('at Lakeview.')
        ->assertDontSee('Elsewhere');
});

test('the Appointments and Sales figures reports are tabs of Sales > Reports', function () {
    $this->actingAs($this->manager)->get(route('sales.reports'))->assertOk()
        ->assertSeeHtml('data-test="report-tabs"')
        ->assertSee(route('sales.reports.figures'), false);
});
