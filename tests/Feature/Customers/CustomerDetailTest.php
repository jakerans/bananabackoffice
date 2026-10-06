<?php

use App\Enums\ContactRole;
use App\Enums\PropertyType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| The customer, its people and its places
|--------------------------------------------------------------------------
*/

test('a household shows its people, primary contact first, with everything each is to it', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $maria = Contact::factory()->named('Maria', 'Lopez')->create([
        'phone' => '5552013344', 'phone_normalized' => '+15552013344',
        'alternate_phone' => '5557778899', 'alternate_phone_normalized' => '+15557778899',
        'email' => 'maria@example.com', 'email_normalized' => 'maria@example.com',
    ]);
    $adam = Contact::factory()->named('Adam', 'Lopez')->create();

    // Adam is added first and sorts first by name, yet the primary contact leads.
    $account = Account::factory()
        ->withContact($adam, [ContactRole::SpousePartner])
        ->withPrimaryContact($maria, [ContactRole::BillingContact, ContactRole::Owner])
        ->create();

    $this->actingAs($owner)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSeeInOrder(['Maria Lopez', 'Residential'])
        ->assertSeeInOrder(['Contacts', 'Maria Lopez', 'Primary', 'Owner, Billing Contact', '(555) 201-3344', '(555) 777-8899', 'maria@example.com', 'Adam Lopez', 'Spouse / Partner'])
        ->assertDontSee('Also a contact of');

    expect($account->fresh()->display_name)->toBe('Maria Lopez');
});

test('a business is shown by its name and may have no contacts or properties yet', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $this->actingAs($owner)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSeeInOrder(['Bakery on Main', 'Commercial'])
        ->assertSee('No contacts yet.')
        ->assertSee('No properties yet.')
        ->assertSee('No leads to show.');
});

test('a customer\'s properties are listed with their label, type and address', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($account)->create([
        'label' => 'Shop', 'type' => PropertyType::Commercial, 'address' => '1 Main Street, Lakeview, GA 30301', 'address_entry' => 'lookup',
    ]);
    Property::factory()->of($account)->create([
        'label' => 'Rental', 'type' => PropertyType::Residential, 'address' => '9 New Build Way, Lot 12, Lakeview, GA 30301', 'address_entry' => 'manual',
    ]);
    Property::factory()->of($account)->archived()->create(['label' => 'Sold', 'address' => '99 Sold Street, Lakeview, GA 30301']);

    $this->actingAs($owner)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSeeInOrder(['Shop', 'Commercial', '1 Main Street, Lakeview, GA 30301', 'Found by address lookup'])
        ->assertSeeInOrder(['Rental', 'Residential', '9 New Build Way, Lot 12, Lakeview, GA 30301', 'Typed by hand'])
        ->assertDontSee('99 Sold Street');
});

test('archived contacts and connections are not shown on the customer', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $account = Account::factory()
        ->commercial('Bakery on Main')
        ->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create(), [ContactRole::BusinessContact])
        ->withContact(Contact::factory()->named('Former', 'Manager')->create(), [ContactRole::PropertyManager])
        ->withContact(Contact::factory()->named('Archived', 'Person')->archived()->create())
        ->create();

    // The former manager's connection to this customer was ended; the person still exists.
    $account->contactLinks()->whereHas('contact', fn ($contact) => $contact->where('first_name', 'Former'))->update(['archived_at' => now()]);

    $this->actingAs($owner)
        ->get(route('customers.show', $account))
        ->assertSee('Pat Baker')
        ->assertDontSee('Former Manager')
        ->assertDontSee('Archived Person');
});

test('an archived customer says so', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Closed Business')->archived()->create();

    $this->actingAs($owner)->get(route('customers.show', $account))->assertOk()->assertSee('This customer is archived');
    $this->actingAs($owner)->get(route('customers.show', Account::factory()->commercial('Open Business')->create()))->assertDontSee('This customer is archived');
});

test('what is stored about a customer is shown as text, never as markup', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()
        ->commercial('<script>alert("x")</script>')
        ->withPrimaryContact(Contact::factory()->named('<b>Bold</b>', 'Name')->create())
        ->create();

    Property::factory()->of($account)->create(['label' => '<i>Label</i>', 'address' => '<u>1 Main Street</u>']);

    $this->actingAs($owner)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertSee('<script>alert("x")</script>')
        ->assertDontSee('<script>alert("x")</script>', false)
        ->assertDontSee('<b>Bold</b>', false)
        ->assertDontSee('<i>Label</i>', false)
        ->assertDontSee('<u>1 Main Street</u>', false);
});

/*
|--------------------------------------------------------------------------
| Leads on the customer page
|--------------------------------------------------------------------------
*/

test('a customer\'s leads are listed newest first with where they are and who has them', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    $account = Account::factory()->commercial('Bakery on Main')->create();
    $otherAccount = Account::factory()->commercial('Other Business')->create();

    Lead::factory()->at($lakeview)->from($account)->assignedTo($consultant)->working()->create(['received_at' => now()->subDays(3)]);
    Lead::factory()->from($account)->create(['received_at' => now()->subHour()]);
    Lead::factory()->at($lakeview)->from($otherAccount)->create();

    $response = $this->actingAs($owner)->get(route('customers.show', $account))->assertOk();

    $response->assertSeeInOrder(['Leads', 'Unrouted', 'Unassigned', 'New', 'Lakeview', 'Sam Sales', 'Working']);

    expect(substr_count($response->getContent(), 'data-test="customer-lead"'))->toBe(2);
});

/*
|--------------------------------------------------------------------------
| A person who is a contact of several customers
|--------------------------------------------------------------------------
*/

test('a shared contact shows only the other customers the viewer may see, and no hint of the rest', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    // One property manager, three owners.
    $paula = Contact::factory()->named('Paula', 'Manager')->create();

    $here = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula, [ContactRole::PropertyManager])->create();
    $alsoMine = Account::factory()->commercial('Elm Street Owners')->withContact($paula, [ContactRole::PropertyManager, ContactRole::BillingContact])->create();
    $hidden = Account::factory()->commercial('Northside Secret Holdings')->withContact($paula, [ContactRole::Tenant])->create();
    $archived = Account::factory()->commercial('Archived Owners')->archived()->withContact($paula)->create();

    Lead::factory()->at($lakeview)->from($here)->create();
    Lead::factory()->at($lakeview)->from($alsoMine)->create();
    Lead::factory()->at($northside)->from($hidden)->create();
    Lead::factory()->at($lakeview)->from($archived)->create();

    // The consultant can see two of Paula's customers.
    $this->actingAs($consultant)
        ->get(route('customers.show', $here))
        ->assertOk()
        ->assertSeeInOrder(['Paula Manager', 'Also a contact of:', 'Elm Street Owners', '(Property Manager, Billing Contact)'])
        ->assertDontSee('Northside Secret Holdings')
        ->assertDontSee('Archived Owners')
        // Paula is a Tenant only at the hidden customer. (The word alone is also a choice on the contact form.)
        ->assertDontSee('(Tenant)')
        ->assertDontSee('other customers')
        ->assertDontSee('and 1 more');

    // The Owner sees all of them that are current.
    $this->actingAs($owner)
        ->get(route('customers.show', $here))
        ->assertSee('Elm Street Owners')
        ->assertSee('Northside Secret Holdings')
        ->assertDontSee('Archived Owners');
});

test('a shared contact with no other customer the viewer may see looks like any other contact', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $paula = Contact::factory()->named('Paula', 'Manager')->create();
    $solo = Contact::factory()->named('Solo', 'Person')->create();

    $shared = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula)->create();
    $notShared = Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($solo)->create();
    $hidden = Account::factory()->commercial('Northside Secret Holdings')->withContact($paula)->create();

    Lead::factory()->at($lakeview)->from($shared)->create();
    Lead::factory()->at($lakeview)->from($notShared)->create();
    Lead::factory()->at($northside)->from($hidden)->create();

    $sharedPage = $this->actingAs($consultant)->get(route('customers.show', $shared))->assertOk()->assertDontSee('Also a contact of');
    $notSharedPage = $this->actingAs($consultant)->get(route('customers.show', $notShared))->assertOk()->assertDontSee('Also a contact of');

    // Nothing in the contact's entry differs between a shared person and an unshared one.
    expect(substr_count($sharedPage->getContent(), 'data-test="contact-other-customers"'))->toBe(0);
    expect(substr_count($notSharedPage->getContent(), 'data-test="contact-other-customers"'))->toBe(0);
    expect(AccountContact::query()->where('contact_id', $paula->id)->count())->toBe(2);
});
