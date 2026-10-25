<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\ManageWarrantyCases;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\ProductionBonusCredit;
use App\Models\Project;
use App\Models\ProjectJobCostingStep;
use App\Models\User;
use App\Support\Performance\ProductionBoard;
use Livewire\Livewire;

/*
 * Production > Performance, step 23a (docs/slice-23-performance-boards-plan.md;
 * decision 179; product-architecture.md, 6.1).
 *
 * It is Friday 20 November 2026 at Lakeview. Pat and Pam are its Production
 * Managers; Morgan manages it; Sam sells there. Harbor is another Location.
 */

beforeEach(function () {
    $this->travelTo('2026-11-20 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->pam = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pam Production']);
});

/**
 * A job financially closed for a Production Manager this month, in dollars.
 */
function boardClosed($test, User $manager, int $revenueDollars, int $marginDollars, ?Location $location = null): Project
{
    $project = soldProject($location ?? $test->lakeview, $test->sam, ['production_manager_id' => $manager->id, 'production_manager_name' => $manager->name]);
    $step = new ProjectJobCostingStep;
    $step->forceFill(['project_id' => $project->id, 'kind' => 'closed', 'revenue_cents' => $revenueDollars * 100, 'payouts_cents' => 0, 'costs_cents' => 0, 'margin_cents' => $marginDollars * 100])->save();
    (new ProductionBonusCredit)->forceFill(['user_id' => $manager->id, 'user_name' => $manager->name, 'project_id' => $project->id, 'project_job_costing_step_id' => $step->id, 'month' => '2026-11', 'revenue_cents' => $revenueDollars * 100, 'margin_cents' => $marginDollars * 100])->save();

    return $project;
}

test('the month\'s completed revenue and gross margin come from the jobs financially closed in it', function () {
    boardClosed($this, $this->pat, 60000, 36000);
    boardClosed($this, $this->pam, 40000, 20000);
    boardClosed($this, $this->pat, 99999, 1, $this->harbor);

    expect(ProductionBoard::for([$this->lakeview->id], '2026-11')->completed())->toBe(['jobs' => 2, 'revenue' => 10000000, 'margin' => 5600000, 'percent' => 56.0])
        ->and(ProductionBoard::for([$this->lakeview->id], '2026-10')->completed()['jobs'])->toBe(0);
});

test('each Production Manager\'s month shows whether it is on track for the Production Bonus, never the amount', function () {
    boardClosed($this, $this->pat, 80000, 48000);
    boardClosed($this, $this->pam, 90000, 40000);
    $riley = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Riley Production']);
    boardClosed($this, $riley, 20000, 12000);

    expect(ProductionBoard::for([$this->lakeview->id], '2026-11')->productionManagers()->map(fn (array $row): array => [$row['name'], $row['status']])->all())->toBe([
        ['Pam Production', 'at_risk'],
        ['Pat Production', 'on_track'],
        ['Riley Production', 'below_floor'],
    ]);
});

test('a job is at risk when its work runs over or it has an open warranty case', function () {
    $project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($project, $this->sam);
    app(TakeProject::class)->handle($project, $this->pat);
    app(RecordWelcomeCall::class)->handle($project, '2026-11-02', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($project, '2026-11-09', '2026-11-20', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    $package->forceFill(['scheduled_first_day' => '2026-11-09', 'scheduled_last_day' => '2026-11-13', 'work_started_on' => '2026-11-09'])->save();
    app(ManageWarrantyCases::class)->open($project->fresh(), 'Drips on the porch.', 'The customer', $this->sam);

    $board = ProductionBoard::for([$this->lakeview->id], '2026-11');

    expect($board->onTime()['runningOver'])->toBe(1)
        ->and($board->warranty())->toBe(['opened' => 1, 'open' => 1])
        ->and($board->atRisk()->sole()['reasons'])->toBe(['Work running past its last day', 'Open warranty case']);
});

test('Production > Performance shows the board to Production and managers, for a Location or all of theirs', function () {
    boardClosed($this, $this->pat, 60000, 36000);

    $this->actingAs($this->pat)->get(route('production.performance'))->assertOk()
        ->assertSee('Completed revenue, November 2026')
        ->assertSee('$60,000')
        ->assertSeeInOrder(['Production Managers, November 2026', 'Pat Production', '$36,000', '60%', 'Below the floor'])
        ->assertDontSee('Bonus amount');

    $this->actingAs($this->manager)->get(route('production.performance'))->assertOk();
    $this->actingAs($this->sam)->get(route('production.performance'))->assertForbidden();

    $both = User::factory()->withRoleEverywhere(Role::Owner)->create();
    Livewire::actingAs($both)->test('pages::production.performance')
        ->assertSee('All my Locations')
        ->set('locationId', $this->harbor->id)
        ->assertSee('0 jobs financially closed');
});
