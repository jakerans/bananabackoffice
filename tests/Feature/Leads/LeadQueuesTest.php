<?php

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Where each job starts
|--------------------------------------------------------------------------
*/

test('the leads page opens on the view that suits the job', function (Closure $user, string $opensOn) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    Livewire::actingAs($user($lakeview))
        ->test('pages::leads.index')
        ->assertSee($opensOn);
})->with([
    'company-wide call center agent: unrouted' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create(), 'Received centrally and not yet sent to a location.'],
    'sales consultant: assigned to me' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(), 'Leads waiting on you'],
    'location manager: unassigned' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create(), 'Leads for Lakeview that nobody is responsible for yet.'],
    'call center agent at one location: unassigned' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create(), 'Leads for Lakeview that nobody is responsible for yet.'],
    'owner: all leads' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create(), 'Inquiries for'],
    'leadership: all leads' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create(), 'Inquiries for'],
]);

/*
|--------------------------------------------------------------------------
| Assigned to me
|--------------------------------------------------------------------------
*/

test('assigned to me lists a person\'s leads at every location they work in, oldest first', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();
    $colleague = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->assignedTo($consultant)->create(['inquiry_name' => 'Newer Nina', 'received_at' => now()->subHour()]);
    Lead::factory()->at($northside)->assignedTo($consultant)->create(['inquiry_name' => 'Older Olga', 'received_at' => now()->subDays(2)]);
    Lead::factory()->at($lakeview)->assignedTo($colleague)->create(['inquiry_name' => 'Colleague Cara']);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Unassigned Una']);

    // Working in Lakeview, yet the Northside lead is still theirs to see here.
    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Assigned to me', '(2)'])
        ->assertSeeInOrder(['Older Olga', 'Newer Nina'])
        ->assertDontSee('Colleague Cara')
        ->assertDontSee('Unassigned Una');
});

test('assigned to me never shows a lead at a location the person cannot see', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // However the lead came to carry their name, the location still decides.
    Lead::factory()->at($northside)->assignedTo($consultant)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->assignedTo($consultant)->create(['inquiry_name' => 'Unrouted Una']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Assigned to me', '(0)'])
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una')
        ->assertSee('Nothing is waiting on you right now.');
});

/*
|--------------------------------------------------------------------------
| Unassigned
|--------------------------------------------------------------------------
*/

test('the unassigned queue lists this location\'s unassigned leads, longest waiting first', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $manager = User::factory()
        ->withRoleAt(Role::LocationManager, $lakeview)
        ->withRoleAt(Role::LocationManager, $northside)
        ->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // Received recently but waiting here longest, and the other way round.
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Waiting Wanda', 'received_at' => now()->subHour(), 'routed_at' => now()->subDays(3)]);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Recent Rita', 'received_at' => now()->subDays(5), 'routed_at' => now()->subMinutes(10)]);
    Lead::factory()->at($lakeview)->assignedTo($consultant)->create(['inquiry_name' => 'Assigned Ada']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($manager)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Unassigned', '(2)'])
        ->assertSeeInOrder(['Waiting Wanda', 'Recent Rita'])
        ->assertDontSee('Assigned Ada')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una');
});

test('sales consultants have no unassigned queue to pick from', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Unassigned Una']);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'unassigned'])
        ->test('pages::leads.index')
        ->assertDontSeeHtml('data-test="unassigned-tab"')
        ->assertSee('Leads waiting on you')
        ->assertDontSee('Unassigned Una')
        ->call('show', 'unassigned')
        ->assertSee('Leads waiting on you')
        ->assertDontSee('Unassigned Una');
});

test('everyone who distributes leads has the unassigned queue', function (Closure $user) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Unassigned Una']);

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($user($lakeview))
        ->withQueryParams(['view' => 'unassigned'])
        ->test('pages::leads.index')
        ->assertSeeHtml('data-test="unassigned-tab"')
        ->assertSee('Unassigned Una');
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
]);

/*
|--------------------------------------------------------------------------
| Unrouted, and what every list shows
|--------------------------------------------------------------------------
*/

test('the unrouted queue lists the longest-waiting lead first', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->create(['inquiry_name' => 'Newer Nina', 'received_at' => now()->subHour()]);
    Lead::factory()->create(['inquiry_name' => 'Older Olga', 'received_at' => now()->subDays(2)]);

    Livewire::actingAs($agent)
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Unrouted', '(2)'])
        ->assertSeeInOrder(['Older Olga', 'Newer Nina']);
});

test('every list shows who each lead is assigned to', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    Lead::factory()->at($lakeview)->assignedTo($consultant)->create(['inquiry_name' => 'Assigned Ada']);

    Livewire::actingAs($manager)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Assigned Ada', 'Sam Sales']);
});
