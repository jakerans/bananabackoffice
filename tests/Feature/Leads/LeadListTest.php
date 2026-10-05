<?php

use App\Enums\Role;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Who may open the list
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('leads'))->assertRedirect(route('login'));
});

test('roles that work leads can open the list', function (Role $role) {
    $user = User::factory()->withRoleAt($role, Location::factory()->create())->create();

    $this->actingAs($user)->get(route('leads'))->assertOk()->assertSee('New lead');
})->with([
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('roles that do not work leads cannot open the list', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('leads'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::leads.index')->assertForbidden();
})->with([
    'finance' => Role::Finance,
    'production manager' => Role::ProductionManager,
]);

test('a person with no access cannot open the list', function () {
    Location::factory()->create();

    $this->actingAs(User::factory()->create())->get(route('leads'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| What is listed: location is the boundary
|--------------------------------------------------------------------------
*/

test('location staff see the leads of the location they are working in and no others', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('Inquiries for Lakeview.')
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una')
        ->assertDontSee('Unrouted');
});

test('staff with two locations see one location at a time, following the location they are working in', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);

    session([CurrentLocation::SESSION_KEY => $northside->id]);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('Northside Nora')
        ->assertDontSee('Lakeview Lena');
});

test('a role that does not work leads at the current location is refused, even with lead access elsewhere', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $northside)
        ->create();

    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);

    session([CurrentLocation::SESSION_KEY => $northside->id]);

    $this->actingAs($user)->get(route('leads'))->assertForbidden();
});

test('asking for the unrouted queue without the permission shows the view the person normally starts on', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    Livewire::actingAs($manager)
        ->withQueryParams(['view' => 'unrouted'])
        ->test('pages::leads.index')
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Unrouted Una')
        ->call('show', 'unrouted')
        ->assertDontSee('Unrouted Una');
});

test('leadership at a single location does not see unrouted leads', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $leader = User::factory()->withRoleAt(Role::Leadership, $lakeview)->create();

    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    Livewire::actingAs($leader)
        ->test('pages::leads.index')
        ->call('show', 'unrouted')
        ->assertDontSee('Unrouted Una');
});

test('company-wide staff have an unrouted queue beside the list for the location they are working in', function (Role $role) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()->withRoleEverywhere($role)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->count(2)->create(['inquiry_name' => 'Unrouted Una']);

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($user)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una')
        ->assertSeeInOrder(['Unrouted', '(2)'])
        ->call('show', 'unrouted')
        ->assertSee('Received centrally and not yet sent to a location.')
        ->assertSee('Unrouted Una')
        ->assertDontSee('Lakeview Lena');
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
    'call center agent' => Role::CallCenterAgent,
]);

test('the all locations view lists every routed lead for company-wide staff', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    Livewire::actingAs($agent)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('Inquiries for All Locations.')
        ->assertSee('Lakeview Lena')
        ->assertSee('Northside Nora')
        ->assertDontSee('Unrouted Una');
});

test('in the all locations view a single-location grant adds nothing', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    // Company-wide through Finance (no lead access), plus sales at one location.
    $user = User::factory()
        ->withRoleEverywhere(Role::Finance)
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    $this->actingAs($user)->get(route('leads'))->assertForbidden();
});

test('leads at a deactivated location are not listed', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $closed = Location::factory()->inactive()->create(['name' => 'Closed Branch']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Lakeview Lena']);
    Lead::factory()->at($closed)->create(['inquiry_name' => 'Closed Clara']);

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    Livewire::actingAs($agent)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Closed Clara');
});

/*
|--------------------------------------------------------------------------
| Order, search and status
|--------------------------------------------------------------------------
*/

test('leads are listed newest first with what was captured', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Older Olga', 'received_at' => now()->subDays(2)]);
    Lead::factory()->at($lakeview)->create([
        'inquiry_name' => 'Newer Nina',
        'inquiry_business_name' => 'Nina Bakery',
        'inquiry_phone' => '5552013344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'nina@example.com',
        'received_at' => now()->subHour(),
    ]);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => null, 'received_at' => now()->subDay()]);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSeeInOrder(['Newer Nina', 'No name given', 'Older Olga'])
        ->assertSee('Nina Bakery')
        ->assertSee('(555) 201-3344')
        ->assertSee('nina@example.com')
        ->assertSee('Phone Call')
        ->assertSee('Unknown')
        ->assertSee('Lakeview')
        ->assertSee('New');
});

test('the list can be searched by name, business, phone or email', function (string $search) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create([
        'inquiry_name' => 'Maria Lopez',
        'inquiry_business_name' => 'Lopez Bakery',
        'inquiry_phone' => '(555) 201-3344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'Maria@Example.com',
        'inquiry_email_normalized' => 'maria@example.com',
    ]);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Someone Else']);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->set('search', $search)
        ->assertSee('Maria Lopez')
        ->assertDontSee('Someone Else');
})->with([
    'part of the name, any case' => ['mARIA lo'],
    'the business' => ['bakery'],
    'the phone as people type it' => ['555-201'],
    'the last digits' => ['3344'],
    'the email' => ['maria@EXAMPLE'],
]);

test('a search cannot reach leads the person may not see', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at(Location::factory()->create())->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->set('search', 'n')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una')
        ->set('search', '%')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una');
});

test('the list can be narrowed to one status', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'New Nell']);
    Lead::factory()->at($lakeview)->working()->create(['inquiry_name' => 'Working Wanda']);

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->set('status', 'working')
        ->assertSee('Working Wanda')
        ->assertDontSee('New Nell')
        ->set('status', 'not-a-status')
        ->assertSee('Working Wanda')
        ->assertSee('New Nell');
});

test('an empty list says so plainly', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($consultant)
        ->withQueryParams(['view' => 'all'])
        ->test('pages::leads.index')
        ->assertSee('No leads here')
        ->assertSee('Inquiries appear here as soon as they are recorded.');
});
