<?php

use App\Actions\Locations\ChangeScorecardSetup;
use App\Enums\Role;
use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\User;
use App\Support\Performance\ExecutiveBoard;
use App\Support\Scorecards\ScorecardNumbers;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
 * Net Profit % where it belongs, step 28c (docs/slice-28-quickbooks-plan.md;
 * decision 189, questions 5 and 7; product-architecture.md, 17.2, 17.3 and
 * 18).
 *
 * It is Monday 26 October 2026. Lakeview's QuickBooks reports $40,000 of
 * income and $8,500.50 of net income for October; Harbor's $10,000 and a
 * $1,000 loss; Riverside's has not been read.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->riverside = Location::factory()->create(['name' => 'Riverside']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);

    foreach ([[$this->lakeview, 4000000, 850050], [$this->harbor, 1000000, -100000]] as [$location, $income, $net]) {
        (new LocationProfitAndLoss)->forceFill([
            'location_id' => $location->id, 'month' => '2026-10', 'income_cents' => $income, 'cost_of_goods_cents' => 0,
            'expenses_cents' => $income - $net, 'net_operating_income_cents' => $net, 'net_income_cents' => $net, 'read_at' => now(),
        ])->save();
    }
});

test('Net Profit % is the scorecard\'s fifth KPI, against the target set for the Location', function () {
    app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, '15%', $this->owner);

    $lines = collect(ScorecardNumbers::for($this->lakeview->fresh(), CarbonImmutable::parse('2026-10-19')))->keyBy('kpi');

    expect(array_keys(ScorecardNumbers::KPIS))->toBe(['sold_revenue', 'gross_profit', 'net_profit', 'nsli', 'cost_per_close'])
        ->and($lines[ScorecardNumbers::NET_PROFIT])->toBe(['kpi' => 'net_profit', 'actual' => 2125, 'target' => 1500, 'due' => null, 'onTarget' => true])
        ->and(collect(ScorecardNumbers::for($this->riverside, CarbonImmutable::parse('2026-10-19')))->firstWhere('kpi', ScorecardNumbers::NET_PROFIT)['actual'])->toBeNull()
        ->and(ScorecardNumbers::format(ScorecardNumbers::NET_PROFIT, 2125))->toBe('21.3%');

    expect(fn () => app(ChangeScorecardSetup::class)->handle($this->lakeview, $this->morgan->id, null, null, '150', $this->owner))
        ->toThrow(ValidationException::class);

    $this->actingAs($this->morgan)->get(route('scorecards.show', $this->lakeview))->assertOk()
        ->assertSeeInOrder(['Net Profit %', 'Actual 21.3%', 'target 15%', 'On target']);
});

test('the Executive Board and its TV view show each Location\'s Net Profit %, and the company\'s from those read', function () {
    $columns = collect(ExecutiveBoard::for(collect([$this->harbor, $this->lakeview, $this->riverside]), '2026-10')->columns())->keyBy('name');

    expect($columns['Lakeview']['netProfit'])->toBe(2125)
        ->and($columns['Harbor']['netProfit'])->toBe(-1000)
        ->and($columns['Riverside']['netProfit'])->toBeNull()
        ->and($columns['Company'])->booksIncome->toBe(5000000)->netIncome->toBe(750050)->netProfit->toBe(1500);

    $this->actingAs($this->leader)->get(route('company.performance', ['month' => '2026-10']))->assertOk()
        ->assertSeeInOrder(['Net profit, from QuickBooks', '-10%', '21.3%', '—', '15%']);

    $this->actingAs($this->leader)->get(route('company.performance.tv'))->assertOk()
        ->assertSeeInOrder(['Harbor', 'Net profit', '-10%', 'Lakeview', 'Net profit', '21.3%']);
});
