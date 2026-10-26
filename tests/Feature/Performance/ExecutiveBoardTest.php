<?php

use App\Actions\Locations\ChangeSalesTarget;
use App\Enums\Role;
use App\Models\Location;
use App\Models\SaleEntry;
use App\Models\User;
use App\Support\Performance\ExecutiveBoard;

/*
 * Company > Executive Board, step 25c
 * (docs/slice-25-marketing-and-executive-boards-plan.md; decision 183,
 * questions 6 to 8; product-architecture.md, 18).
 *
 * It is Monday 26 October 2026. Lakeview's target is $10,000 a month and Sam
 * sold $8,450 there this month; Harbor has no target and sold $3,000.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Riley Rep']);

    app(ChangeSalesTarget::class)->handle($this->lakeview, '10,000', '2026-10', $this->owner);

    foreach ([[$this->sam, $this->lakeview, 8450], [$this->riley, $this->harbor, 3000]] as [$salesperson, $location, $dollars]) {
        $project = soldProject($location, $salesperson);
        (new SaleEntry)->forceFill([
            'proposal_id' => $project->proposal_id, 'lead_id' => $project->lead_id, 'project_id' => $project->id, 'location_id' => $location->id,
            'salesperson_id' => $salesperson->id, 'salesperson_name' => $salesperson->name, 'kind' => SaleEntry::SALE, 'cents' => $dollars * 100, 'is_lead_win' => true,
            'occurred_at' => '2026-10-20 16:00:00',
        ])->save();
    }
});

test('each Location sits beside the others and the company, sold revenue judged against its target so far', function () {
    $columns = collect(ExecutiveBoard::for(collect([$this->harbor, $this->lakeview]), '2026-10')->columns())->keyBy('name');

    // 26 of 31 days of a $10,000 target is $8,387; $8,450 is on pace.
    expect($columns['Lakeview']['sold'])->toBe(845000)
        ->and($columns['Lakeview']['pace'])->toBe(ExecutiveBoard::ON_PACE)
        ->and($columns['Harbor']['pace'])->toBe(ExecutiveBoard::NO_TARGET)
        ->and($columns['Company']['sold'])->toBe(1145000)
        ->and($columns['Company']['target'])->toBe(1000000);

    // A finished month needs all of its target.
    $this->travelTo('2026-11-02 15:00:00');
    expect(collect(ExecutiveBoard::for(collect([$this->lakeview]), '2026-10')->columns())->sole()['pace'])->toBe(ExecutiveBoard::BEHIND);
});

test('the Executive Board shows the Locations, the company and the leaders, to Company Overview access only', function () {
    $this->actingAs($this->leader)->get(route('company.performance'))->assertOk()
        ->assertSeeInOrder(['October 2026', 'Harbor', 'Lakeview', 'Company'])
        ->assertSeeInOrder(['Sold revenue', '$3,000', 'No target', '$8,450', 'of $10,000', 'On pace', '$11,450'])
        ->assertSeeInOrder(['Leaderboard, October 2026', 'Sam Sales', '$8,450', 'Riley Rep', '$3,000']);

    $this->actingAs($this->owner)->get(route('company.performance'))->assertOk();
    $this->actingAs($this->manager)->get(route('company.performance'))->assertForbidden();
});
