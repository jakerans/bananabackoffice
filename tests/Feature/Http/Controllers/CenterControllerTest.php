<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;

dataset('center routes', [
    'company overview' => ['company.home'],
    'marketing' => ['marketing.home'],
    'sales' => ['sales.home'],
    'production' => ['production.home'],
    'finance' => ['finance.home'],
    'color studio' => ['color_studio.home'],
    'settings' => ['settings.home'],
]);

test('guests are redirected to the login page from every center', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('center routes');

test('an owner can open every center', function (string $route) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route($route))->assertOk();
})->with('center routes');

test('a staff member with no grants is forbidden from every center', function (string $route) {
    Location::factory()->create();

    $user = User::factory()->create();

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with('center routes');

test('a call center agent is forbidden from centers outside marketing', function (string $route) {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)->get(route($route))->assertForbidden();
})->with([
    'company overview' => ['company.home'],
    'sales' => ['sales.home'],
    'production' => ['production.home'],
    'finance' => ['finance.home'],
    'color studio' => ['color_studio.home'],
    'settings' => ['settings.home'],
]);

test('a call center agent can open the marketing center', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('marketing.home'))
        ->assertOk()
        ->assertSee('Marketing Center')
        ->assertSee('Working in Lakeview');
});

test('leadership is forbidden from the finance center', function () {
    Location::factory()->create();

    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)->get(route('finance.home'))->assertForbidden();
});

test('a forbidden page says not authorized rather than coming soon', function () {
    Location::factory()->create();

    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)
        ->get(route('finance.home'))
        ->assertForbidden()
        ->assertSee('Not authorized')
        ->assertDontSee('Coming soon');
});

test('leadership granted at a single location is forbidden from company overview', function () {
    $location = Location::factory()->create();

    $leader = User::factory()->withRoleAt(Role::Leadership, $location)->create();

    $this->actingAs($leader)->get(route('company.home'))->assertForbidden();
});

test('a center is forbidden in a location where the staff member lacks its permission', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $riverside)
        ->create();

    $this->actingAs($user)
        ->withSession([CurrentLocation::SESSION_KEY => $lakeview->id])
        ->get(route('sales.home'))
        ->assertOk();

    $this->withSession([CurrentLocation::SESSION_KEY => $riverside->id])
        ->get(route('sales.home'))
        ->assertForbidden();
});

test('a center landing page lists unbuilt sections as coming soon without linking to them', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('sales.home'))
        ->assertOk()
        ->assertSee('Coming soon')
        ->assertSee('Estimates')
        ->assertDontSee('/sales/estimates');
});

test('the center switcher does not offer centers the staff member cannot open', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('marketing.home'))
        ->assertOk()
        ->assertDontSee('Finance Center')
        ->assertDontSee('Sales Center')
        ->assertDontSee('Settings');
});
