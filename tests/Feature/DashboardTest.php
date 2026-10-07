<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('a staff member with no grants is told no access has been assigned', function () {
    Location::factory()->create();

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('No access assigned yet')
        ->assertDontSee('Coming soon');
});

test('an owner is prompted to add the first location when none exist', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Add your first location')
        ->assertDontSee('No access assigned yet');
});

test('the dashboard separates authorized centers from external surfaces', function () {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Centers')
        ->assertSee('Sales Center')
        ->assertSee('External')
        ->assertSee('Color Studio')
        ->assertDontSee('Finance Center')
        ->assertDontSee('Marketing Center');
});

test('the location switcher lists only authorized locations', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);
    Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Lakeview')
        ->assertSee('Riverside')
        ->assertDontSee('Northside')
        ->assertDontSee('All Locations');
});

test('the location switcher offers all locations only with a company-wide grant', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('All Locations');
});

test('a deactivated location is not offered in the location switcher', function () {
    Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->inactive()->create(['name' => 'Closedville']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Closedville');
});

test('a staff member lands on a named location rather than all locations', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Working in Lakeview')
        ->assertSessionHas(CurrentLocation::SESSION_KEY, fn ($value) => $value !== CurrentLocation::ALL_LOCATIONS);
});
