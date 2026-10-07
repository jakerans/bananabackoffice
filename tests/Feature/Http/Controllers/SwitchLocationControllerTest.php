<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Navigation\Shell;

test('guests are redirected to the login page', function () {
    $location = Location::factory()->create();

    $this->post(route('location.switch'), ['location' => (string) $location->id])
        ->assertRedirect(route('login'));
});

test('a staff member can switch to a location they are authorized for', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->create();

    $this->actingAs($user)
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $riverside->id);
});

test('switching to a location the staff member is not authorized for is forbidden', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $this->actingAs($user)
        ->withSession([CurrentLocation::SESSION_KEY => $lakeview->id])
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertForbidden()
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $lakeview->id);
});

test('switching to a deactivated location is forbidden', function () {
    Location::factory()->create();
    $closed = Location::factory()->inactive()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->post(route('location.switch'), ['location' => (string) $closed->id])
        ->assertForbidden();
});

test('switching to a location that does not exist is forbidden', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->post(route('location.switch'), ['location' => '999999'])
        ->assertForbidden();
});

test('switching to all locations without a company-wide grant is forbidden', function () {
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    $this->actingAs($user)
        ->withSession([CurrentLocation::SESSION_KEY => $lakeview->id])
        ->post(route('location.switch'), ['location' => CurrentLocation::ALL_LOCATIONS])
        ->assertForbidden()
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $lakeview->id);
});

test('a staff member with a company-wide grant can switch to all locations', function () {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($user)
        ->post(route('location.switch'), ['location' => CurrentLocation::ALL_LOCATIONS])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas(CurrentLocation::SESSION_KEY, CurrentLocation::ALL_LOCATIONS);
});

test('switching location keeps the staff member in a center they can still open', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $riverside)
        ->create();

    $this->actingAs($user)
        ->from(route('sales.home'))
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route('sales.home'));
});

test('switching location preserves an authorized destination around a shared route', function (
    string $previousRoute,
    string $rememberedDestination,
    string $expectedRoute,
) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->withSession([
            CurrentLocation::SESSION_KEY => $lakeview->id,
            Shell::LAST_CENTER_KEY => $rememberedDestination,
        ])
        ->from(route($previousRoute))
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route($expectedRoute))
        ->assertSessionHas(CurrentLocation::SESSION_KEY, $riverside->id)
        ->assertSessionHas(Shell::LAST_CENTER_KEY, $rememberedDestination);
})->with([
    'Marketing Leads' => ['leads', 'marketing', 'marketing.home'],
    'Sales Leads' => ['leads', 'sales', 'sales.home'],
    'Marketing Customers' => ['customers', 'marketing', 'marketing.home'],
    'Sales Customers' => ['customers', 'sales', 'sales.home'],
    'Production Customers' => ['customers', 'production', 'production.home'],
    'Finance Customers' => ['customers', 'finance', 'finance.home'],
    'Company Users and Access' => ['settings.users', 'company', 'company.home'],
    'Finance People and Access' => ['settings.users', 'finance', 'finance.home'],
]);

test('switching location maps the legacy settings context to company', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->withSession([
            CurrentLocation::SESSION_KEY => $lakeview->id,
            Shell::LAST_CENTER_KEY => 'settings',
        ])
        ->from(route('settings.users'))
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route('company.home'))
        ->assertSessionHas(Shell::LAST_CENTER_KEY, 'company');
});

test('switching location never preserves an unauthorized remembered destination', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::CallCenterAgent, $riverside)
        ->create();

    $this->actingAs($user)
        ->withSession([
            CurrentLocation::SESSION_KEY => $lakeview->id,
            Shell::LAST_CENTER_KEY => 'sales',
        ])
        ->from(route('leads'))
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route('marketing.home'))
        ->assertSessionHas(Shell::LAST_CENTER_KEY, 'marketing');
});

test('switching location returns home when the center is not available in the new location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $riverside)
        ->create();

    $this->actingAs($user)
        ->from(route('sales.home'))
        ->post(route('location.switch'), ['location' => (string) $riverside->id])
        ->assertRedirect(route('dashboard'));
});

test('switching location never redirects to an address outside the application', function () {
    $location = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->from('https://example.com/phishing')
        ->post(route('location.switch'), ['location' => (string) $location->id])
        ->assertRedirect(route('dashboard'));
});

test('a location value that is not a location or all is rejected', function (mixed $value) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->post(route('location.switch'), ['location' => $value])
        ->assertSessionHasErrors('location')
        ->assertSessionMissing(CurrentLocation::SESSION_KEY);
})->with([
    'missing' => [null],
    'wildcard' => ['*'],
    'zero' => ['0'],
    'negative' => ['-1'],
    'sql fragment' => ['1 OR 1=1'],
    'array' => [['all']],
]);
