<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\SaleEntry;
use App\Models\User;
use App\Support\Performance\SalesLeaderboard;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * The Sales leaderboard, step 23c (docs/slice-23-performance-boards-plan.md;
 * decision 179, questions 7 and 8; product-architecture.md, 5.8).
 *
 * It is Friday 20 November 2026. Sam sells at Lakeview, Riley at Harbor,
 * Quinn at Lakeview. Pat is a Production Manager.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Riley Rep']);
    $this->quinn = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Quinn Closer']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
});

/**
 * A sale, or a correction when the dollars are below zero, for a salesperson on a day.
 */
function soldOn($test, User $salesperson, int $dollars, string $when, ?Location $location = null): void
{
    $project = soldProject($location ?? $test->lakeview, $salesperson);

    (new SaleEntry)->forceFill([
        'proposal_id' => $project->proposal_id, 'lead_id' => $project->lead_id, 'project_id' => $project->id, 'location_id' => $project->location_id,
        'salesperson_id' => $salesperson->id, 'salesperson_name' => $salesperson->name,
        'kind' => $dollars < 0 ? SaleEntry::CORRECTION : SaleEntry::SALE, 'cents' => $dollars * 100, 'is_lead_win' => $dollars > 0,
        'occurred_at' => $when,
    ])->save();
}

test('the standings are company-wide sold revenue by salesperson, corrections taken off, the most first', function () {
    soldOn($this, $this->sam, 8450, '2026-11-03 16:00:00');
    soldOn($this, $this->riley, 12000, '2026-11-10 16:00:00', $this->harbor);
    soldOn($this, $this->sam, -2000, '2026-11-12 16:00:00');
    soldOn($this, $this->quinn, 5000, '2026-10-30 16:00:00');

    $now = CarbonImmutable::now('America/New_York');

    expect(SalesLeaderboard::standings(SalesLeaderboard::start('month', $now), $now)->map(fn (array $row): array => [$row['name'], $row['cents']])->all())->toBe([
        ['Riley Rep', 1200000],
        ['Sam Sales', 645000],
    ])->and(SalesLeaderboard::standings(SalesLeaderboard::start('quarter', $now), $now)->pluck('name')->all())->toBe(['Riley Rep', 'Sam Sales', 'Quinn Closer']);
});

test('a finished period\'s champion has the most; a tie shares it; a month with no sales has none', function () {
    soldOn($this, $this->sam, 7000, '2026-10-05 16:00:00');
    soldOn($this, $this->quinn, 7000, '2026-10-20 16:00:00');
    soldOn($this, $this->riley, 3000, '2026-10-21 16:00:00', $this->harbor);

    expect(SalesLeaderboard::champions('month', CarbonImmutable::parse('2026-10-01', 'America/New_York'))->pluck('name')->all())->toBe(['Quinn Closer', 'Sam Sales'])
        ->and(SalesLeaderboard::champions('month', CarbonImmutable::parse('2026-09-01', 'America/New_York')))->toBeEmpty();
});

test('Sales > Leaderboard shows the standings with the defending champion and the winners, to everyone with Sales access', function () {
    soldOn($this, $this->quinn, 9000, '2026-10-15 16:00:00');
    soldOn($this, $this->sam, 8450, '2026-11-03 16:00:00');
    soldOn($this, $this->quinn, 4000, '2026-11-04 16:00:00');

    $this->actingAs($this->riley)->get(route('sales.leaderboard'))->assertOk()
        ->assertSeeInOrder(['November 2026, so far', 'Defending champion of October 2026: Quinn Closer', 'Sam Sales', '$8,450', 'Quinn Closer', 'Defending champion', '$4,000'])
        ->assertSeeInOrder(['Winners', 'October 2026', 'Quinn Closer', '$9,000']);

    Livewire::actingAs($this->sam)->test('pages::sales.leaderboard')
        ->set('period', 'quarter')
        ->assertSeeInOrder(['Q4 2026, so far', 'Quinn Closer', '$13,000', 'Sam Sales', '$8,450']);

    $this->actingAs($this->pat)->get(route('sales.leaderboard'))->assertForbidden();
});
