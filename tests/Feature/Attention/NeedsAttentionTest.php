<?php

use App\Enums\Role;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

/*
 * Needs Attention, step 26b (docs/slice-26-needs-attention-plan.md; decision
 * 185, questions 4 and 5): each person's own list on Home and in the header,
 * a manager's Locations, and Company > Needs Attention; feedback on each.
 *
 * Pat runs projects at Lakeview, where Morgan manages; Riley manages
 * Northside; Finley is Finance everywhere.
 */

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->northside = Location::factory()->create(['name' => 'Northside']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->riley = User::factory()->withRoleAt(Role::LocationManager, $this->northside)->create(['name' => 'Riley Manager']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);

    $this->late = attentionItem($this->pat, $this->lakeview, ['rule' => 'job_costing_late', 'subject_key' => 'project:1', 'title' => 'Job costing is late on 1 Oak St']);
    $this->over = attentionItem($this->pat, $this->lakeview, ['rule' => 'work_running_over', 'subject_key' => 'project:2', 'severity' => AttentionItem::URGENT, 'title' => 'Work is running over on 2 Elm St']);
    $this->northsideItem = attentionItem($this->riley, $this->northside, ['rule' => 'warranty_unowned', 'subject_key' => 'warranty:3', 'severity' => AttentionItem::URGENT, 'title' => 'Nobody owns the warranty on 3 Pine St']);
    $this->payroll = attentionItem($this->finley, null, ['rule' => 'pay_period_open', 'subject_key' => 'period:2026-10-a', 'title' => 'Payroll for 1 to 15 October is still open']);
    attentionItem($this->pat, $this->lakeview, ['rule' => 'handoff_missing', 'subject_key' => 'project:4', 'title' => 'An old item, now fixed', 'closed_at' => now()]);
});

test('Home shows the person their own open items, the most serious first, and the header counts them', function () {
    $this->actingAs($this->pat)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Needs your attention', 'Work is running over on 2 Elm St', 'Job costing is late on 1 Oak St'])
        ->assertDontSee('An old item, now fixed')
        ->assertDontSee('Nobody owns the warranty on 3 Pine St')
        ->assertSee('data-test="attention-count"', false)
        ->assertDontSee('data-test="attention-locations"', false);

    $this->actingAs($this->owner)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Nothing needs attention.')
        ->assertDontSee('data-test="attention-count"', false)
        ->assertSee('data-test="attention-company-link"', false);
});

test('a person marks their item useful or not, and dismisses it with a reason until it comes back', function () {
    $this->actingAs($this->pat);

    Livewire::test('pages::attention.list', ['scope' => 'mine'])
        ->call('mark', $this->late->id, AttentionItem::USEFUL)
        ->call('openDismiss', $this->over->id)
        ->set('dismissReason', str_repeat('x', AttentionItem::REASON_LENGTH + 1))
        ->call('dismiss')
        ->assertHasErrors('dismissReason')
        ->set('dismissReason', ' The crew is already on it. ')
        ->call('dismiss')
        ->assertHasNoErrors()
        ->assertSee('Job costing is late on 1 Oak St')
        ->assertDontSee('Work is running over on 2 Elm St');

    expect($this->late->fresh())->feedback->toBe(AttentionItem::USEFUL)->feedback_at->not->toBeNull()
        ->and($this->over->fresh())->feedback->toBe(AttentionItem::DISMISSED)->feedback_reason->toBe('The crew is already on it.');

    $this->get(route('dashboard'))->assertSee('data-test="attention-count"', false);
    $this->over->forceFill(['closed_at' => now()])->save();
    attentionItem($this->pat, $this->lakeview, ['rule' => 'work_running_over', 'subject_key' => 'project:2', 'severity' => AttentionItem::URGENT, 'title' => 'Work is running over on 2 Elm St, again']);

    Livewire::test('pages::attention.list', ['scope' => 'mine'])->assertSee('Work is running over on 2 Elm St, again');
});

test('nobody gives feedback on an item that is not theirs', function (string $method) {
    $this->actingAs($this->morgan);

    Livewire::test('pages::attention.list', ['scope' => 'mine'])
        ->call($method, $this->late->id, AttentionItem::USEFUL)
        ->assertNotFound();

    expect($this->late->fresh()->feedback)->toBeNull();
})->with(['mark', 'openDismiss']);

test('a manager sees every item at their Locations with its owner, and nothing elsewhere', function () {
    $this->actingAs($this->morgan)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Needs attention at your Locations')
        ->assertSee('Work is running over on 2 Elm St')
        ->assertSee('For Pat Production')
        ->assertDontSee('Nobody owns the warranty on 3 Pine St')
        ->assertDontSee('Payroll for 1 to 15 October is still open');

    $this->actingAs($this->riley);

    Livewire::test('pages::attention.list', ['scope' => 'locations'])
        ->assertSee('Nobody owns the warranty on 3 Pine St')
        ->assertDontSee('Job costing is late on 1 Oak St')
        ->assertDontSee('data-test="attention-useful"', false);
});

test('Company > Needs Attention shows the Owner every open item, and is closed to everyone else', function () {
    $this->actingAs($this->owner)->get(route('company.needs_attention'))
        ->assertOk()
        ->assertSeeInOrder(['Work is running over on 2 Elm St', 'Nobody owns the warranty on 3 Pine St', 'Job costing is late on 1 Oak St'])
        ->assertSee('Payroll for 1 to 15 October is still open')
        ->assertSee('For Finley Finance')
        ->assertDontSee('An old item, now fixed');

    $this->actingAs($this->morgan)->get(route('company.needs_attention'))->assertForbidden();
    $this->actingAs($this->pat);
    Livewire::test('pages::attention.list', ['scope' => 'company'])->assertForbidden();
});
