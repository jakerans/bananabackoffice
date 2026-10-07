<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;

dataset('primary section routes', [
    'company performance' => ['company.performance'],
    'marketing performance board' => ['marketing.performance'],
    'sales performance board' => ['sales.performance'],
    'production performance board' => ['production.performance'],
    'finance performance board' => ['finance.performance'],
    'color projects' => ['color_studio.projects'],
]);

test('guests are redirected to the login page from every primary section', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with('primary section routes');

test('an owner can open every primary section', function (string $route) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route($route))->assertOk();
})->with('primary section routes');

test('a staff member with no grants is forbidden from every primary section', function (string $route) {
    Location::factory()->create();

    $user = User::factory()->create();

    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with('primary section routes');

test('a call center agent is forbidden from primary sections outside marketing', function (string $route) {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)->get(route($route))->assertForbidden();
})->with([
    'company performance' => ['company.performance'],
    'sales performance board' => ['sales.performance'],
    'production performance board' => ['production.performance'],
    'finance performance board' => ['finance.performance'],
    'color projects' => ['color_studio.projects'],
]);

test('leadership is forbidden from the finance performance board', function () {
    Location::factory()->create();

    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)->get(route('finance.performance'))->assertForbidden();
});

test('a section is forbidden in a location where the staff member lacks its permission', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $riverside)
        ->create();

    $this->actingAs($user)
        ->withSession([CurrentLocation::SESSION_KEY => $lakeview->id])
        ->get(route('sales.performance'))
        ->assertOk();

    $this->withSession([CurrentLocation::SESSION_KEY => $riverside->id])
        ->get(route('sales.performance'))
        ->assertForbidden();
});

test('an unbuilt primary section says it is coming soon and shows its center and location', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('marketing.performance'))
        ->assertOk()
        ->assertSee('Performance')
        ->assertSee('Coming soon')
        ->assertSee('Marketing Center')
        ->assertSee('Working in Lakeview');
});

test('the second navigation row lists the sections of the current center in order', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('sales.performance'))
        ->assertOk()
        ->assertSeeInOrder([
            'aria-label="Sales Center"',
            'Performance',
            'Workspace',
            'Calendar',
            'Commissions',
            'Leaderboard',
            'Customers',
            'Reports',
        ], escape: false);
});

test('the second navigation row has no overview item', function () {
    $location = Location::factory()->create();

    // A sales consultant cannot open Company Overview, so the word appears nowhere else on their page.
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();

    $this->actingAs($consultant)
        ->get(route('sales.performance'))
        ->assertOk()
        ->assertDontSee('Overview');
});

test('the current section is marked as the current page', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('sales.performance'))
        ->assertOk()
        ->assertSee('aria-current="page"', escape: false);
});

test('unbuilt sections are shown without a link', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('sales.performance'))
        ->assertOk()
        ->assertSee('Calendar')
        ->assertSee('aria-disabled="true"', escape: false)
        ->assertDontSee('/sales/calendar')
        ->assertDontSee('/sales/workspace');
});

test('the center switcher does not offer centers the staff member cannot open', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('marketing.performance'))
        ->assertOk()
        ->assertDontSee('Finance Center')
        ->assertDontSee('Sales Center')
        ->assertDontSee('Settings')
        ->assertDontSee('Color Studio')
        ->assertDontSee('External');
});

test('the center switcher separates color studio as an external surface', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('company.performance'))
        ->assertOk()
        ->assertSeeInOrder([
            'Company',
            'Marketing Center',
            'Sales Center',
            'Production Center',
            'Finance Center',
            'External',
            'Color Studio',
        ]);
});

test('color studio keeps its existing navigation as an external destination', function () {
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    $this->actingAs($consultant)
        ->get(route('color_studio.projects'))
        ->assertOk()
        ->assertSee('data-test="center-switcher"', escape: false)
        ->assertSee('Color Studio')
        ->assertSee('aria-label="Color Studio"', escape: false)
        ->assertSee('Color Projects')
        ->assertSee('External');
});
