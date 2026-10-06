<?php

use App\Enums\ContactRole;
use App\Enums\PropertyType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
});

/**
 * The Customers page as an Owner sees it, across every location.
 */
function allCustomers(User $owner): Testable
{
    return Livewire::actingAs($owner)->withQueryParams(['location' => 'any'])->test('pages::customers.index');
}

test('customers are listed by name with their type, primary contact and properties', function () {
    $maria = Contact::factory()->named('Maria', 'Lopez')->create([
        'phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'maria@example.com', 'email_normalized' => 'maria@example.com',
    ]);

    $household = Account::factory()->withPrimaryContact($maria)->create();

    Property::factory()->of($household)->create(['address_city' => 'Lakeview']);
    Property::factory()->of($household)->create(['address_city' => 'Northside']);

    $business = Account::factory()
        ->commercial('Bakery on Main')
        ->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create(), [ContactRole::BusinessContact])
        ->create();

    Account::factory()->commercial('Zebra Storage')->create();

    allCustomers($this->owner)
        ->assertSeeInOrder(['Bakery on Main', 'Commercial', 'Pat Baker'])
        ->assertSeeInOrder(['Bakery on Main', 'Maria Lopez', 'Zebra Storage'])
        ->assertSeeInOrder(['Maria Lopez', 'Residential', '(555) 201-3344', 'maria@example.com', 'Lakeview', 'and 1 more']);
});

test('the list can be searched by what staff know about a customer', function (string $search) {
    $maria = Contact::factory()->named('Maria', 'Lopez')->create([
        'phone' => '(555) 201-3344', 'phone_normalized' => '+15552013344',
        'alternate_phone' => '555-777-8899', 'alternate_phone_normalized' => '+15557778899',
        'email' => 'Maria@Example.com', 'email_normalized' => 'maria@example.com',
    ]);
    $paula = Contact::factory()->named('Paula', 'Manager')->create();

    $account = Account::factory()
        ->commercial('Lopez Bakery')
        ->withPrimaryContact($maria, [ContactRole::Owner])
        ->withContact($paula, [ContactRole::PropertyManager])
        ->create();

    Property::factory()->of($account)->create(['address' => '14 Oak Street, Lakeview, GA 30377', 'address_line1' => '14 Oak Street', 'address_postal_code' => '30377']);

    Account::factory()->withPrimaryContact(Contact::factory()->named('Someone', 'Else')->create())->create();

    allCustomers($this->owner)
        ->set('search', $search)
        ->assertSee('Lopez Bakery')
        ->assertDontSee('Someone Else');
})->with([
    'the business name, any case' => ['lopez BAKERY'],
    'the primary contact, first and last name' => ['maria lopez'],
    'the primary contact, last name first' => ['Lopez Maria'],
    'another contact' => ['paula'],
    'the phone as people type it' => ['555-201'],
    'the last digits of the phone' => ['3344'],
    'the alternate phone' => ['777 8899'],
    'the email' => ['maria@EXAMPLE'],
    'the street' => ['14 oak'],
    'the zip code' => ['30377'],
]);

test('a household is found by its primary contact\'s name', function () {
    Account::factory()->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create())->create();
    Account::factory()->withPrimaryContact(Contact::factory()->named('Someone', 'Else')->create())->create();

    allCustomers($this->owner)->set('search', 'maria lop')->assertSee('Maria Lopez')->assertDontSee('Someone Else');
});

test('an archived contact or property does not make a customer match a search', function () {
    $account = Account::factory()->commercial('Lopez Bakery')->create();
    $former = Contact::factory()->named('Former', 'Employee')->archived()->create();

    Account::factory()->commercial('Other Business')->withContact($former)->create();
    Property::factory()->of($account)->archived()->create(['address' => '99 Sold Street, Lakeview, GA 30301']);

    allCustomers($this->owner)
        ->set('search', 'Former')
        ->assertSee('No customers here')
        ->set('search', 'Sold Street')
        ->assertSee('No customers here');
});

test('the list can be narrowed to residential or commercial customers', function () {
    Account::factory()->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create())->create();
    Account::factory()->commercial('Bakery on Main')->create();

    allCustomers($this->owner)
        ->set('type', 'commercial')
        ->assertSee('Bakery on Main')
        ->assertDontSee('Maria Lopez')
        ->set('type', 'residential')
        ->assertSee('Maria Lopez')
        ->assertDontSee('Bakery on Main')
        ->set('type', 'not-a-type')
        ->assertSee(['Maria Lopez', 'Bakery on Main']);
});

test('archived customers are hidden until they are asked for', function () {
    Account::factory()->commercial('Current Customer')->create();
    Account::factory()->commercial('Closed Business')->archived()->create();

    allCustomers($this->owner)
        ->assertSee('Current Customer')
        ->assertDontSee('Closed Business')
        ->set('archived', true)
        ->assertSeeInOrder(['Closed Business', 'Archived'])
        ->assertSee('Current Customer');
});

test('an archived property is not counted or shown on the list', function () {
    $account = Account::factory()->commercial('Lopez Bakery')->create();

    Property::factory()->of($account)->create(['address_city' => 'Lakeview', 'type' => PropertyType::Commercial]);
    Property::factory()->of($account)->archived()->create(['address_city' => 'Soldtown']);

    allCustomers($this->owner)->assertSee('Lakeview')->assertDontSee('Soldtown')->assertDontSee('more');
});

test('open leads are counted, and only open ones', function () {
    $lakeview = Location::query()->sole();
    $account = Account::factory()->commercial('Lopez Bakery')->create();

    Lead::factory()->count(2)->at($lakeview)->from($account)->create();
    Lead::factory()->at($lakeview)->from($account)->working()->create();

    allCustomers($this->owner)->assertSeeInOrder(['Lopez Bakery', 'data-test="open-leads">3</span>'], false);
});

test('an empty list says so plainly', function () {
    allCustomers($this->owner)
        ->assertSee('No customers here')
        ->assertSee('A customer appears here once a lead you can see has been linked to them.');
});

test('the list is paged', function () {
    Account::factory()->count(26)->commercial()->create();

    $page = allCustomers($this->owner);

    expect(substr_count($page->html(), 'data-test="open-leads"'))->toBe(25);
});
