<?php

use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A household customer named after its primary contact.
 */
function customerNamed(string $firstName, string $lastName): Account
{
    return Account::factory()->withPrimaryContact(Contact::factory()->named($firstName, $lastName)->create())->create();
}

/**
 * A customer has no location, so these tests look at every customer the person
 * can see, whatever location they happen to be working in.
 */
function customersPage(User $user): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['location' => 'any'])->test('pages::customers.index');
}

/*
|--------------------------------------------------------------------------
| Who may open Customers
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $account = customerNamed('Maria', 'Lopez');

    $this->get(route('customers'))->assertRedirect(route('login'));
    $this->get(route('customers.show', $account))->assertRedirect(route('login'));
});

test('every role can open the customers page', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    Location::factory()->create();

    $this->actingAs($user)->get(route('customers'))->assertOk()->assertSee('Customers');
})->with(Role::cases());

test('a person with no access cannot open customers', function () {
    Location::factory()->create();

    $nobody = User::factory()->create();
    $account = customerNamed('Maria', 'Lopez');

    $this->actingAs($nobody)->get(route('customers'))->assertForbidden();
    $this->actingAs($nobody)->get(route('customers.show', $account))->assertForbidden();

    Livewire::actingAs($nobody)->test('pages::customers.index')->assertForbidden();
    Livewire::actingAs($nobody)->test('pages::customers.show', ['account' => $account])->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| A customer has no location: two ways to see one
|--------------------------------------------------------------------------
*/

test('company-wide staff see every customer, including ones with no lead', function (Role $role) {
    $lakeview = Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $routed = customerNamed('Routed', 'Rita');
    $unrouted = customerNamed('Unrouted', 'Una');
    $noLead = customerNamed('Nolead', 'Nell');

    Lead::factory()->at($lakeview)->from($routed)->create();
    Lead::factory()->from($unrouted)->create();

    customersPage($user)->assertSee(['Routed Rita', 'Unrouted Una', 'Nolead Nell']);

    foreach ([$routed, $unrouted, $noLead] as $account) {
        $this->actingAs($user)->get(route('customers.show', $account))->assertOk();
    }
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
    'finance' => Role::Finance,
]);

test('location staff see only customers with a lead they can see', function (Role $role) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()->withRoleAt($role, $lakeview)->create();

    $mine = customerNamed('Lakeview', 'Lena');
    $elsewhere = customerNamed('Northside', 'Nora');
    $unrouted = customerNamed('Unrouted', 'Una');
    $noLead = customerNamed('Nolead', 'Nell');

    Lead::factory()->at($lakeview)->from($mine)->create();
    Lead::factory()->at($northside)->from($elsewhere)->create();
    Lead::factory()->from($unrouted)->create();

    customersPage($user)
        ->assertSee('Lakeview Lena')
        ->assertDontSee(['Northside Nora', 'Unrouted Una', 'Nolead Nell']);

    $this->actingAs($user)->get(route('customers.show', $mine))->assertOk()->assertSee('Lakeview Lena');

    foreach ([$elsewhere, $unrouted, $noLead] as $hidden) {
        $this->actingAs($user)->get(route('customers.show', $hidden))->assertForbidden();

        Livewire::actingAs($user)->test('pages::customers.show', ['account' => $hidden])->assertForbidden();
    }
})->with([
    'leadership at one location' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent at one location' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('location staff who cannot see leads see no customers yet', function (Role $role) {
    $lakeview = Location::factory()->create();
    $user = User::factory()->withRoleAt($role, $lakeview)->create();

    $account = customerNamed('Lakeview', 'Lena');

    Lead::factory()->at($lakeview)->from($account)->create();

    customersPage($user)->assertDontSee('Lakeview Lena')->assertSee('No customers here');

    $this->actingAs($user)->get(route('customers.show', $account))->assertForbidden();
})->with([
    'production manager' => Role::ProductionManager,
    'finance' => Role::Finance,
]);

test('seeing leads at one location and customers at another gives sight of nothing', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    // Works leads at Lakeview. At Northside holds only Finance, which sees customers but not leads.
    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::Finance, $northside)
        ->create();

    $northsideCustomer = customerNamed('Northside', 'Nora');

    Lead::factory()->at($northside)->from($northsideCustomer)->create();

    customersPage($user)->assertDontSee('Northside Nora');

    $this->actingAs($user)->get(route('customers.show', $northsideCustomer))->assertForbidden();
});

test('a lead at a deactivated location gives sight of nothing', function () {
    $closed = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $closed)->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();
    $account = customerNamed('Closed', 'Clara');

    Lead::factory()->at($closed)->from($account)->create();

    $closed->update(['is_active' => false]);

    customersPage($consultant->fresh())->assertDontSee('Closed Clara');

    $this->actingAs($consultant->fresh())->get(route('customers.show', $account))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Visibility follows the lead
|--------------------------------------------------------------------------
*/

test('when a customer\'s only visible lead moves away, the customer goes with it', function (Closure $move) {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = customerNamed('Maria', 'Lopez');
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    $this->actingAs($consultant)->get(route('customers.show', $account))->assertOk();

    $move($lead, $northside);

    customersPage($consultant)->assertDontSee('Maria Lopez');

    $this->actingAs($consultant)->get(route('customers.show', $account))->assertForbidden();
})->with([
    'to another location' => [fn (Lead $lead, Location $northside) => $lead->forceFill(['location_id' => $northside->id])->save()],
    'back to central' => [fn (Lead $lead) => $lead->forceFill(['location_id' => null])->save()],
    'unlinked from the customer' => [fn (Lead $lead) => $lead->forceFill(['account_id' => null])->save()],
]);

test('a customer page that is already open stops working once the customer is out of sight', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = customerNamed('Maria', 'Lopez');
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    $this->actingAs($consultant);

    $page = livewireSnapshot($this->get(route('customers.show', $account))->assertOk());

    $lead->forceFill(['location_id' => $northside->id])->save();

    sendLivewireAction($page, '$refresh')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| A shared customer never reveals another location's work
|--------------------------------------------------------------------------
*/

test('a customer shared between locations shows each viewer only their own location\'s leads', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $lakeviewConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Lena Lakeview']);
    $northsideConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create(['name' => 'Nora Northside']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $account = customerNamed('Maria', 'Lopez');

    Lead::factory()->at($lakeview)->from($account)->assignedTo($lakeviewConsultant)->create();
    Lead::factory()->at($northside)->from($account)->assignedTo($northsideConsultant)->create();
    Lead::factory()->at($northside)->from($account)->create();
    Lead::factory()->from($account)->create();

    // Lakeview sees the customer and exactly one lead: its own.
    $this->actingAs($lakeviewConsultant)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSee('Maria Lopez')
        ->assertSee('Lakeview')
        ->assertDontSee('Northside')
        ->assertDontSee('Nora Northside')
        ->assertDontSee('Unrouted');

    expect(substr_count($this->actingAs($lakeviewConsultant)->get(route('customers.show', $account))->getContent(), 'data-test="customer-lead"'))->toBe(1);

    // The list counts only what the viewer may see: 1 for Lakeview, 2 for Northside, 4 for the Owner.
    customersPage($lakeviewConsultant)->assertSeeInOrder(['Maria Lopez', 'data-test="open-leads">1</span>'], false);
    customersPage($northsideConsultant)->assertSeeInOrder(['Maria Lopez', 'data-test="open-leads">2</span>'], false);
    customersPage($owner)->assertSeeInOrder(['Maria Lopez', 'data-test="open-leads">4</span>'], false);

    expect(substr_count($this->actingAs($owner)->get(route('customers.show', $account))->getContent(), 'data-test="customer-lead"'))->toBe(4);
});

test('someone who sees customers but not leads is shown no leads and no count of them', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->create();

    $account = customerNamed('Maria', 'Lopez');

    Lead::factory()->at($lakeview)->from($account)->create();

    customersPage($finance)->assertSee('Maria Lopez')->assertDontSee('Open leads');

    $this->actingAs($finance)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSee('Maria Lopez')
        ->assertDontSee('data-test="customer-leads"', false)
        ->assertDontSee('data-test="customer-lead"', false)
        ->assertDontSee('No leads to show.');
});

/*
|--------------------------------------------------------------------------
| Search and filters work inside what the person may see
|--------------------------------------------------------------------------
*/

test('a search cannot reach a customer the person may not see', function (string $search) {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $hidden = Account::factory()
        ->withPrimaryContact(Contact::factory()->named('Hidden', 'Harriet')->create([
            'phone' => '(555) 201-3344', 'phone_normalized' => '+15552013344', 'email' => 'harriet@example.com', 'email_normalized' => 'harriet@example.com',
        ]))
        ->create();

    Lead::factory()->at($northside)->from($hidden)->create();

    customersPage($consultant)
        ->set('search', $search)
        ->assertDontSee('Hidden Harriet')
        ->assertDontSee('harriet@example.com')
        ->assertSee('No customers here');
})->with([
    'the exact name' => ['Hidden Harriet'],
    'the exact phone' => ['5552013344'],
    'the exact email' => ['harriet@example.com'],
    'a wildcard' => ['%'],
    'an underscore' => ['_'],
]);

test('the location filter cannot be pointed at a location the person does not work in', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $mine = customerNamed('Lakeview', 'Lena');
    $elsewhere = customerNamed('Northside', 'Nora');

    Lead::factory()->at($lakeview)->from($mine)->create();
    Lead::factory()->at($northside)->from($elsewhere)->create();

    Livewire::actingAs($consultant)
        ->withQueryParams(['location' => (string) $northside->id])
        ->test('pages::customers.index')
        ->assertDontSee('Northside Nora')
        ->assertSee('Lakeview Lena')
        ->set('location', (string) $northside->id)
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Customers with a lead at Northside');
});

/*
|--------------------------------------------------------------------------
| Impersonation
|--------------------------------------------------------------------------
*/

test('an owner viewing backoffice as a sales consultant sees only that consultant\'s customers', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $mine = customerNamed('Lakeview', 'Lena');
    $elsewhere = customerNamed('Northside', 'Nora');
    $noLead = customerNamed('Nolead', 'Nell');

    Lead::factory()->at($lakeview)->from($mine)->create();
    Lead::factory()->at($northside)->from($elsewhere)->create();

    // As themselves, the Owner sees all three.
    $this->actingAs($owner)->get(route('customers', ['location' => 'any']))->assertSee(['Lakeview Lena', 'Northside Nora', 'Nolead Nell']);

    impersonate($owner, $consultant);

    $this->get(route('customers', ['location' => 'any']))
        ->assertOk()
        ->assertSee('Lakeview Lena')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Nolead Nell');

    $this->get(route('customers.show', $mine))->assertOk();
    $this->get(route('customers.show', $elsewhere))->assertForbidden();
    $this->get(route('customers.show', $noLead))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The location selector only sets where the filter starts
|--------------------------------------------------------------------------
*/

test('the list starts on customers with a lead at the location being worked in', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $atLakeview = customerNamed('Lakeview', 'Lena');
    $atNorthside = customerNamed('Northside', 'Nora');
    customerNamed('Nolead', 'Nell');

    Lead::factory()->at($lakeview)->from($atLakeview)->create();
    Lead::factory()->at($northside)->from($atNorthside)->create();

    session([CurrentLocation::SESSION_KEY => $lakeview->id]);

    Livewire::actingAs($owner)
        ->test('pages::customers.index')
        ->assertSet('location', (string) $lakeview->id)
        ->assertSee('Customers with a lead at Lakeview.')
        ->assertSee('Lakeview Lena')
        ->assertDontSee(['Northside Nora', 'Nolead Nell'])
        ->set('location', (string) $northside->id)
        ->assertSee('Northside Nora')
        ->assertDontSee('Lakeview Lena')
        ->set('location', 'any')
        ->assertSee('Every customer you can see.')
        ->assertSee(['Lakeview Lena', 'Northside Nora', 'Nolead Nell']);
});

test('in the all locations view the list starts on every customer', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    customerNamed('Nolead', 'Nell');

    session([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS]);

    Livewire::actingAs($owner)
        ->test('pages::customers.index')
        ->assertSet('location', 'any')
        ->assertSee('Nolead Nell');
});
