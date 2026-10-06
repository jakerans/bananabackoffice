<?php

use App\Enums\ContactRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\AccountContactRole;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavigationItem;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Connect a contact to an account directly, as the database sees it.
 */
function connectContact(Account $account, Contact $contact, bool $primary = false): AccountContact
{
    $link = new AccountContact;

    $link->forceFill(['account_id' => $account->id, 'contact_id' => $contact->id, 'is_primary' => $primary])->save();

    return $link;
}

/*
|--------------------------------------------------------------------------
| What the database itself refuses
|--------------------------------------------------------------------------
*/

test('the database refuses a second primary contact for one customer', function () {
    $account = Account::factory()->withPrimaryContact()->create();

    expect(fn () => connectContact($account, Contact::factory()->create(), primary: true))
        ->toThrow(UniqueConstraintViolationException::class);

    // Further contacts who are not primary are fine, and so is a primary on another customer.
    connectContact($account, Contact::factory()->create());
    connectContact(Account::factory()->create(), Contact::factory()->create(), primary: true);

    expect($account->contactLinks()->count())->toBe(2);
});

test('the database refuses to connect the same person to a customer twice', function () {
    $contact = Contact::factory()->create();
    $account = Account::factory()->withContact($contact)->create();

    expect(fn () => connectContact($account, $contact))->toThrow(UniqueConstraintViolationException::class);
});

test('a person may hold several roles at a customer, but each only once', function () {
    $contact = Contact::factory()->create();
    $account = Account::factory()->withPrimaryContact($contact, [ContactRole::Owner, ContactRole::BillingContact])->create();

    $link = $account->contactLinks()->sole();

    expect($link->roleLabels())->toBe(['Owner', 'Billing Contact']);

    expect(fn () => (new AccountContactRole)->forceFill(['account_contact_id' => $link->id, 'role' => ContactRole::Owner])->save())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('one person can be connected to several customers with different roles on each', function () {
    $paula = Contact::factory()->named('Paula', 'Manager')->create();

    $first = Account::factory()->commercial('Oak Street Owners')->withContact($paula, [ContactRole::PropertyManager])->create();
    $second = Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($paula, [ContactRole::Owner, ContactRole::BillingContact])->create();

    expect(Contact::query()->count())->toBe(1);
    expect($first->contactLinks()->sole()->roleLabels())->toBe(['Property Manager']);
    expect($second->contactLinks()->sole()->roleLabels())->toBe(['Owner', 'Billing Contact']);
    expect($second->contactLinks()->sole()->is_primary)->toBeTrue();
    expect($first->contactLinks()->sole()->is_primary)->toBeFalse();
});

test('a phone number or email may be shared by several contacts', function () {
    Contact::factory()->count(2)->create([
        'email' => 'family@example.com', 'email_normalized' => 'family@example.com',
        'phone' => '5552013344', 'phone_normalized' => '+15552013344',
    ]);

    expect(Contact::query()->where('email_normalized', 'family@example.com')->count())->toBe(2);
});

test('customer records cannot be deleted, and a record in use cannot be removed from under a lead', function () {
    $contact = Contact::factory()->create();
    $account = Account::factory()->withPrimaryContact($contact)->create();
    $property = Property::factory()->of($account)->create();
    $link = $account->contactLinks()->sole();

    Lead::factory()->from($account, $contact, $property)->create();

    foreach ([$account, $contact, $property, $link] as $record) {
        expect(fn () => $record->delete())->toThrow(LogicException::class);
    }

    // Even past the application, the database keeps what a lead points at.
    expect(fn () => Account::query()->toBase()->where('id', $account->id)->delete())->toThrow(QueryException::class);
    expect(fn () => Contact::query()->toBase()->where('id', $contact->id)->delete())->toThrow(QueryException::class);
    expect(fn () => Property::query()->toBase()->where('id', $property->id)->delete())->toThrow(QueryException::class);

    expect(Account::query()->count())->toBe(1);
    expect(Contact::query()->count())->toBe(1);
    expect(Property::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| What a customer is called
|--------------------------------------------------------------------------
*/

test('a household is named after its primary contact and a business after itself', function () {
    $maria = Contact::factory()->named('Maria', 'Lopez')->create();

    $household = Account::factory()->withContact(Contact::factory()->named('Adam', 'Lopez')->create())->withPrimaryContact($maria)->create();
    $business = Account::factory()->commercial('Bakery on Main')->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create())->create();
    $unnamed = Account::factory()->create();

    $unnamed->refreshDisplayName();

    expect($household->fresh()->display_name)->toBe('Maria Lopez');
    expect($business->fresh()->display_name)->toBe('Bakery on Main');
    expect($unnamed->fresh()->display_name)->toBe('Unnamed customer');
});

test('the name of a household follows a change to who its primary contact is', function () {
    $maria = Contact::factory()->named('Maria', 'Lopez')->create();
    $adam = Contact::factory()->named('Adam', 'Lopez')->create();

    $household = Account::factory()->withPrimaryContact($maria)->withContact($adam)->create();

    $household->contactLinks()->where('contact_id', $maria->id)->update(['is_primary' => false]);
    $household->contactLinks()->where('contact_id', $adam->id)->update(['is_primary' => true]);

    $household->refreshDisplayName();

    expect($household->fresh()->display_name)->toBe('Adam Lopez');
});

test('a contact with only one name is shown by it', function () {
    expect(Contact::factory()->named('Maria')->make()->fullName())->toBe('Maria');
    expect(Contact::factory()->make(['first_name' => null, 'last_name' => 'Lopez'])->fullName())->toBe('Lopez');
    expect(Contact::factory()->make(['first_name' => null, 'last_name' => null])->fullName())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Customers in the menus
|--------------------------------------------------------------------------
*/

test('customers is one page with one address, shown in four centers', function () {
    $navigation = app(Navigation::class);

    expect(collect($navigation->centersForRoute('customers'))->pluck('id')->all())->toBe(['marketing', 'sales', 'production', 'finance']);
    expect(collect($navigation->centersForRoute('customers.show'))->pluck('id')->all())->toBe(['marketing', 'sales', 'production', 'finance']);

    $entries = collect($navigation->centers())
        ->flatMap(fn (NavigationItem $center) => $center->flatten())
        ->filter(fn (NavigationItem $item) => $item->id === 'shared.customers');

    expect($entries)->toHaveCount(4);
    expect($entries->pluck('route')->unique()->all())->toBe(['customers']);
    expect($entries->every(fn (NavigationItem $item) => $item->isLive()))->toBeTrue();
});

test('customers is a working link inside a center the person can open', function (Role $role, string $center) {
    $user = User::factory()->withRoleAt($role, Location::factory()->create())->create();

    $this->actingAs($user)
        ->get(route('customers'))
        ->assertOk()
        ->assertSee($center)
        ->assertSee('href="'.route('customers').'"', false);
})->with([
    'sales consultant, in sales' => [Role::SalesConsultant, 'Sales Center'],
    'call center agent, in marketing' => [Role::CallCenterAgent, 'Marketing Center'],
    'production manager, in production' => [Role::ProductionManager, 'Production Center'],
    'finance, in finance' => [Role::Finance, 'Finance Center'],
]);
