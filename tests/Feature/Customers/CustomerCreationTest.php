<?php

use App\Actions\Customers\CreateAccount;
use App\Enums\AccountType;
use App\Enums\ContactRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use App\Support\Customers\ContactDetails;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Who may create a customer that has no lead
|--------------------------------------------------------------------------
*/

test('company-wide staff who manage customers can open the new customer page', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('customers'))->assertOk()->assertSee('New customer');
    $this->actingAs($user)->get(route('customers.create'))->assertOk()->assertSee('Kind of customer');
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('location staff cannot create a customer without a lead', function (Role $role) {
    $lakeview = Location::factory()->create();
    $user = User::factory()->withRoleAt($role, $lakeview)->create();

    $this->actingAs($user)->get(route('customers'))->assertOk()->assertDontSee('New customer');
    $this->actingAs($user)->get(route('customers.create'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::customers.create')->assertForbidden();

    expect(Account::query()->count())->toBe(0);
})->with([
    'leadership at one location' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent at one location' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('company-wide roles that only see customers cannot create one', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('customers'))->assertOk()->assertDontSee('New customer');
    $this->actingAs($user)->get(route('customers.create'))->assertForbidden();
})->with([
    'finance' => Role::Finance,
    'production manager' => Role::ProductionManager,
]);

test('a new customer page that is already open stops working once the person loses the company-wide grant', function () {
    $lakeview = Location::factory()->create();
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $page = Livewire::actingAs($agent)->test('pages::customers.create');

    $agent->accessGrants()->delete();
    $agent->refresh();

    $page->set('type', 'commercial')->set('businessName', 'Sneaky Business')->call('save')->assertForbidden();

    expect(Account::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| A household
|--------------------------------------------------------------------------
*/

test('a household is created with its primary contact and named after them', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.create')
        ->assertSet('type', 'residential')
        ->assertSet('contactRoles', ['owner'])
        ->set('contactFirstName', ' Maria ')
        ->set('contactLastName', 'Lopez')
        ->set('contactPhone', '(555) 201-3344')
        ->set('contactEmail', 'Maria@Example.com')
        ->set('contactRoles', ['owner', 'billing_contact'])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('customers.show', Account::query()->sole()));

    $account = Account::query()->sole();

    expect($account->type)->toBe(AccountType::Residential);
    expect($account->business_name)->toBeNull();
    expect($account->display_name)->toBe('Maria Lopez');

    $link = $account->contactLinks()->sole();

    expect($link->is_primary)->toBeTrue();
    expect($link->roleLabels())->toBe(['Owner', 'Billing Contact']);
    expect($link->contact->phone_normalized)->toBe('+15552013344');
    expect($link->contact->email_normalized)->toBe('maria@example.com');

    // It has no lead, so it is the company-wide staff's to see, and theirs alone.
    $this->actingAs($owner)->get(route('customers.show', $account))->assertOk()->assertSee('Maria Lopez');
});

test('a household cannot be created without its primary contact', function (array $fields, string $error) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.create');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('save')->assertHasErrors($error);

    expect(Account::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
})->with([
    'nothing about the person' => [[], 'contactFirstName'],
    'a phone but no name' => [['contactPhone' => '5552013344'], 'contactFirstName'],
    'a name but no role' => [['contactFirstName' => 'Maria', 'contactRoles' => []], 'contactRoles'],
    'a malformed email' => [['contactFirstName' => 'Maria', 'contactEmail' => 'not-an-email'], 'contactEmail'],
]);

test('a compound name is kept as one person, exactly as typed', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.create')
        ->set('contactFirstName', 'John & Mary')
        ->set('contactLastName', 'Smith')
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->count())->toBe(1);
    expect(Account::query()->sole()->display_name)->toBe('John & Mary Smith');
});

/*
|--------------------------------------------------------------------------
| A business
|--------------------------------------------------------------------------
*/

test('a business can be created with no contact yet', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::customers.create')
        ->set('type', 'commercial')
        // The usual role for a business's first contact replaces the household one.
        ->assertSet('contactRoles', ['business_contact'])
        ->set('businessName', '  Bakery on Main ')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->sole();

    expect($account->type)->toBe(AccountType::Commercial);
    expect($account->business_name)->toBe('Bakery on Main');
    expect($account->display_name)->toBe('Bakery on Main');
    expect($account->contactLinks()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
});

test('a business can be created with its first contact, who becomes primary', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.create')
        ->set('type', 'commercial')
        ->set('businessName', 'Bakery on Main')
        ->set('contactFirstName', 'Pat')
        ->set('contactLastName', 'Baker')
        ->set('contactRoles', ['business_contact', 'property_manager'])
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->sole();
    $link = $account->contactLinks()->sole();

    // A business keeps its own name, whoever its primary contact is.
    expect($account->display_name)->toBe('Bakery on Main');
    expect($link->is_primary)->toBeTrue();
    expect($link->roleLabels())->toBe(['Property Manager', 'Business Contact']);
});

test('a business needs its name, and a contact given for it needs a name', function (array $fields, string $error) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.create')->set('type', 'commercial');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('save')->assertHasErrors($error);

    expect(Account::query()->count())->toBe(0);
})->with([
    'no business name' => [['businessName' => '   '], 'businessName'],
    'a contact phone with no name' => [['businessName' => 'Bakery on Main', 'contactPhone' => '5552013344'], 'contactFirstName'],
    'a kind that does not exist' => [['type' => 'government', 'businessName' => 'City Hall'], 'type'],
]);

test('a business name typed for a household is not kept', function () {
    Location::factory()->create();

    $account = app(CreateAccount::class)->handle(AccountType::Residential, 'Should Not Be Kept', ContactDetails::fromInput(['first_name' => 'Maria']), [ContactRole::Owner]);

    expect($account->business_name)->toBeNull();
    expect($account->display_name)->toBe('Maria');
});

test('pressing save twice creates one customer', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.create')
        ->set('type', 'commercial')
        ->set('businessName', 'Bakery on Main')
        ->call('save')
        ->call('save');

    expect(Account::query()->count())->toBe(1);
});

test('a form that was refused can be corrected and saved', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.create')
        ->set('type', 'commercial')
        ->call('save')
        ->assertHasErrors('businessName')
        ->set('businessName', 'Bakery on Main')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->count())->toBe(1);
});

test('a refused customer leaves nothing behind', function () {
    expect(fn () => app(CreateAccount::class)->handle(AccountType::Commercial, 'Bakery on Main', ContactDetails::fromInput(['phone' => '5552013344']), []))
        ->toThrow(ValidationException::class);

    expect(Account::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Seen by company-wide staff alone, until it has a lead
|--------------------------------------------------------------------------
*/

test('a customer created directly is invisible to location staff until it has a lead they can see', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $this->actingAs($owner);

    $account = app(CreateAccount::class)->handle(AccountType::Commercial, 'Bakery on Main', null);

    $this->actingAs($consultant)->get(route('customers.show', $account))->assertForbidden();

    Lead::factory()->at($lakeview)->from($account)->create();

    $this->actingAs($consultant)->get(route('customers.show', $account))->assertOk();
});
