<?php

use App\Actions\Compensation\ManagePay;
use App\Actions\Projects\RecordPayments;
use App\Enums\Role;
use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Support\Projects\JobCosting;
use App\Support\Reports\FinanceReport;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Finance > Reports, step 32d (docs/slice-32-reports-plan.md;
 * product-architecture.md, 9.7; decision 222): profit and loss by Location,
 * AR aging, cash collected, job costing on closed jobs, and pay owed.
 *
 * It is Friday 20 November 2026. Finley is Finance for every Location; Fran
 * is Finance at Lakeview only. QuickBooks has been read for Lakeview's and
 * Harbor's October and November.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);

    foreach ([[$this->lakeview, '2026-10', 5_000_000, 2_500_000, 1_500_000], [$this->lakeview, '2026-11', 4_000_000, 2_000_000, 1_000_000], [$this->harbor, '2026-11', 3_000_000, 1_800_000, 900_000]] as [$location, $month, $income, $cost, $expenses]) {
        LocationProfitAndLoss::query()->forceCreate([
            'location_id' => $location->id,
            'month' => $month,
            'income_cents' => $income,
            'cost_of_goods_cents' => $cost,
            'expenses_cents' => $expenses,
            'net_operating_income_cents' => $income - $cost - $expenses,
            'net_income_cents' => $income - $cost - $expenses,
            'read_at' => now(),
        ]);
    }
});

test('profit and loss is each Location side by side, from QuickBooks, for the months the days touch', function () {
    Livewire::actingAs($this->finley)->test('pages::finance.reports')
        ->assertOk()
        ->assertSee('This month: Nov 1, 2026 to Nov 30, 2026, at 2 locations, in the company&#039;s time.', false)
        ->assertSeeInOrder(['Profit and loss', 'Harbor', 'Lakeview', 'Total'])
        ->assertSeeInOrder(['Income', '$30,000', '$40,000', '$70,000'])
        ->assertSeeInOrder(['Gross profit', '$12,000', '$20,000', '$32,000'])
        ->assertSeeInOrder(['Net income', '$3,000', '$10,000', '$13,000'])
        ->assertSeeInOrder(['Net profit %', '10%', '25%', '18.6%']);

    Livewire::actingAs($this->finley)->test('pages::finance.reports', ['range' => 'last_12_months'])
        ->assertSeeInOrder(['Income', '$30,000', '$90,000'])
        ->assertSeeHtml('data-test="pl-missing"');
});

test('cash collected, aging and closed jobs read as Finance > Performance and job costing read them', function () {
    $project = soldProject($this->lakeview, $this->sam);
    $record = app(RecordPayments::class);
    $final = $record->recordInvoice($project, 'final', 'F-1', '1,000', '2026-11-02', $this->finley);
    $record->recordPayment($project, $final->id, '300', 'card', '2026-11-03', 'ch_1', null, $this->finley);

    // Finance approved its final gross margin: $8,450 revenue and $4,650 cost, over the job's budget.
    ProjectJobCostingStep::query()->forceCreate(['project_id' => $project->id, 'kind' => ProjectJobCostingStep::KIND_APPROVED, 'revenue_cents' => 845000, 'payouts_cents' => 425000, 'costs_cents' => 40000, 'margin_cents' => 380000, 'recorded_by' => $this->finley->id, 'recorded_by_name' => $this->finley->name]);

    $report = FinanceReport::for(collect([$this->lakeview]), CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    $budget = JobCosting::for($project->fresh())->targetCostCents();

    expect($report->cashByMonth())->toBe(['2026-11' => ['cents' => 30000, 'payments' => 1]])
        ->and($report->aging()->get($this->lakeview->id))->toMatchArray(['late' => 70000, 'total' => 70000, 'jobs' => 1])
        ->and($report->jobCosts()->get($this->lakeview->id))->toBe(['location' => 'Lakeview', 'jobs' => 1, 'revenue' => 845000, 'budget' => $budget, 'cost' => 465000, 'profit' => 380000, 'variance' => $budget - 465000, 'overBudget' => 1]);

    Livewire::actingAs($this->fran)->test('pages::finance.reports')
        ->assertSeeInOrder(['Job costing on closed jobs', 'Lakeview', '1', '$8,450', '$4,650', '$3,800', '45%', '($1,798.13)', '1'])
        ->assertSeeInOrder(['Cash collected', 'Nov 2026', '1', '$300']);
});

test('pay owed is shown, by person, only to those who manage pay', function () {
    app(ManagePay::class)->adjust($this->sam, 'add', '250', null, 'Spiff.', $this->finley);

    Livewire::actingAs($this->finley)->test('pages::finance.reports')
        ->assertSeeInOrder(['Pay owed, not yet paid', 'Sam Sales', '$250']);

    Livewire::actingAs($this->fran)->test('pages::finance.reports')
        ->assertDontSee('Pay owed, not yet paid')
        ->call('download', 'pay')
        ->assertForbidden();
});

test('each table downloads as a spreadsheet of totals and staff names', function (string $table, string $expected) {
    $download = Livewire::actingAs($this->finley)->test('pages::finance.reports')
        ->call('download', $table)
        ->assertFileDownloaded();

    expect(base64_decode($download->effects['download']['content']))->toContain($expected);
})->with([
    'profit and loss' => ['pl', 'Income,30000.00,40000.00,70000.00'],
    'aging' => ['aging', 'Lakeview,0.00,0.00,0.00,0.00,0'],
    'cash' => ['cash', '2026-11,0.00,0'],
    'job costing' => ['jobs', 'Harbor,0,0.00'],
]);

test('Finance sees Finance\'s reports and no other Center\'s; Sales and Marketing reports stay theirs', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create();

    $this->actingAs($this->finley)->get(route('finance.performance'))->assertSee(route('finance.reports'), false);
    $this->actingAs($this->finley)->get(route('finance.reports'))->assertOk();
    $this->actingAs($this->finley)->get(route('sales.reports'))->assertForbidden();
    $this->actingAs($this->finley)->get(route('marketing.reports'))->assertForbidden();

    $this->actingAs($manager)->get(route('finance.reports'))->assertForbidden();
    $this->actingAs($manager)->get(route('sales.reports'))->assertOk();
});
