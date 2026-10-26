<?php

use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\SaleEntry;
use App\Models\User;

/*
 * TV Mode, step 27c (docs/slice-27-leadership-scorecards-plan.md; decision
 * 187, question 8; product-architecture.md, 17.2).
 *
 * It is Monday 26 October 2026. Sam sold $8,450 at Lakeview this month.
 * Pat has an Urgent item at Lakeview; Sam has one of his own.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $project = soldProject($this->lakeview, $this->sam);
    (new SaleEntry)->forceFill([
        'proposal_id' => $project->proposal_id, 'lead_id' => $project->lead_id, 'project_id' => $project->id, 'location_id' => $this->lakeview->id,
        'salesperson_id' => $this->sam->id, 'salesperson_name' => $this->sam->name, 'kind' => SaleEntry::SALE, 'cents' => 845000, 'is_lead_win' => true,
        'occurred_at' => '2026-10-20 16:00:00',
    ])->save();

    attentionItem($this->pat, $this->lakeview, ['rule' => 'work_running_over', 'subject_key' => 'project:'.$project->id, 'severity' => AttentionItem::URGENT, 'title' => 'Work is running over on 1 Oak St']);
    attentionItem($this->sam, $this->lakeview, ['rule' => 'handoff_missing', 'subject_key' => 'project:'.$project->id, 'title' => 'The handoff for 1 Oak St is not done']);
});

test('the Executive Board enters TV Mode: large, without menus, refreshing itself, with the top Needs Attention items', function () {
    $this->actingAs($this->leader)->get(route('company.performance'))->assertOk()->assertSee('Enter TV Mode');

    $this->actingAs($this->leader)->get(route('company.performance.tv'))
        ->assertOk()
        ->assertSee('wire:poll.300s', false)
        ->assertSeeInOrder(['Executive Board', 'October 2026', 'Exit TV Mode', 'Lakeview', '$8,450'])
        ->assertSeeInOrder(['Needs Attention', 'Urgent', 'Work is running over on 1 Oak St'])
        ->assertSeeInOrder(['Leaderboard, October 2026', 'Sam Sales', '$8,450'])
        ->assertDontSee('data-test="center-switcher"', false);

    $this->actingAs($this->morgan)->get(route('company.performance.tv'))->assertForbidden();
});

test('the leaderboard enters TV Mode, showing only the Needs Attention items the person may see', function () {
    $this->actingAs($this->sam)->get(route('sales.leaderboard'))->assertOk()->assertSee('Enter TV Mode');

    $this->actingAs($this->sam)->get(route('sales.leaderboard.tv'))
        ->assertOk()
        ->assertSee('wire:poll.300s', false)
        ->assertSeeInOrder(['Leaderboard', 'October 2026', 'Sam Sales', '$8,450'])
        ->assertSee('The handoff for 1 Oak St is not done')
        ->assertDontSee('Work is running over on 1 Oak St');

    $this->actingAs($this->pat)->get(route('sales.leaderboard.tv'))->assertForbidden();
});
