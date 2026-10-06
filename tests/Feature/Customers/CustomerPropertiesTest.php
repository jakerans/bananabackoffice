<?php

use App\Actions\Customers\ArchiveProperty;
use App\Actions\Customers\SaveProperty;
use App\Enums\PropertyType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use App\Support\Addresses\AddressKey;
use App\Support\Addresses\StructuredAddress;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Switch address lookup on, with a stand-in for Mapbox. The token is fictitious.
 */
function useFakeAddressLookup(string $street = '14 Oak Street', string $zip = '30301', string $id = 'mbx-oak-14'): void
{
    config(['services.mapbox.token' => 'test-token-not-real']);

    Http::fake(['api.mapbox.com/*' => Http::response(['features' => [[
        'properties' => [
            'mapbox_id' => $id,
            'full_address' => "{$street}, Lakeview, Georgia {$zip}, United States",
            'name' => $street,
            'coordinates' => ['latitude' => 33.7701, 'longitude' => -84.3902, 'accuracy' => 'rooftop'],
            'context' => [
                'address' => ['name' => $street],
                'place' => ['name' => 'Lakeview'],
                'region' => ['name' => 'Georgia', 'region_code' => 'GA'],
                'postcode' => ['name' => $zip],
                'country' => ['name' => 'United States', 'country_code' => 'US'],
            ],
        ],
    ]]])]);
}

/**
 * A customer with a lead at a location, so that location's staff can see and change it.
 */
function customerWithLeadAt(Location $location, ?Account $account = null): Account
{
    $account ??= Account::factory()->withPrimaryContact()->create();

    Lead::factory()->at($location)->from($account)->create();

    return $account;
}

/*
|--------------------------------------------------------------------------
| Adding a property
|--------------------------------------------------------------------------
*/

test('a property can be added with its address typed by hand, and nothing is invented for it', function () {
    $lakeview = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = customerWithLeadAt($lakeview);

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        // No lookup is set up in tests, so the form starts on typing. A household's property starts as residential.
        ->assertSet('addressMode', 'manual')
        ->assertSet('propertyType', 'residential')
        ->set('propertyLabel', '  Rental ')
        ->set('addressLine1', ' 9 New Build Way ')
        ->set('addressLine2', 'Lot 12')
        ->set('addressCity', 'Lakeview')
        ->set('addressState', 'GA')
        ->set('addressPostalCode', '30301')
        ->call('saveProperty')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Rental', 'Residential', '9 New Build Way, Lot 12, Lakeview, GA 30301', 'Typed by hand']);

    $property = Property::query()->sole();

    expect($property->account->is($account))->toBeTrue();
    expect($property->label)->toBe('Rental');
    expect($property->type)->toBe(PropertyType::Residential);
    expect($property->address)->toBe('9 New Build Way, Lot 12, Lakeview, GA 30301');
    expect($property->address_line1)->toBe('9 New Build Way');
    expect($property->address_line2)->toBe('Lot 12');
    expect($property->address_postal_code)->toBe('30301');
    expect($property->address_entry)->toBe('manual');
    expect($property->address_latitude)->toBeNull();
    expect($property->address_provider)->toBeNull();
    expect($property->address_normalized)->toBe('9 new build way|lot 12|30301');

    Http::assertNothingSent();
});

test('a property can be added from address lookup, keeping its map position', function () {
    useFakeAddressLookup();

    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->assertSet('addressMode', 'search')
        // A business's property starts as commercial.
        ->assertSet('propertyType', 'commercial')
        ->set('propertyLabel', 'Shop')
        ->set('addressSearch', '14 Oak Street Lakeview')
        ->call('selectAddress', 0)
        ->assertSet('addressMode', 'selected')
        ->call('saveProperty')
        ->assertHasNoErrors()
        ->assertSee('Found by address lookup');

    $property = Property::query()->sole();

    expect($property->address)->toBe('14 Oak Street, Lakeview, Georgia 30301, United States');
    expect($property->address_line1)->toBe('14 Oak Street');
    expect($property->address_state)->toBe('GA');
    expect($property->address_entry)->toBe('lookup');
    expect((float) $property->address_latitude)->toBe(33.7701);
    expect((float) $property->address_longitude)->toBe(-84.3902);
    expect($property->address_provider)->toBe('mapbox');
    expect($property->address_provider_place_id)->toBe('mbx-oak-14');
    expect($property->type)->toBe(PropertyType::Commercial);
});

test('when the lookup fails, the property is still saved from what is typed', function () {
    config(['services.mapbox.token' => 'test-token-not-real']);

    Http::fake(['api.mapbox.com/*' => Http::response('', 500)]);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Location::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->set('addressSearch', '14 Oak Street Lakeview')
        ->assertSet('addressSuggestions', [])
        ->call('enterAddressManually')
        ->assertSet('addressLine1', '14 Oak Street Lakeview')
        ->set('addressLine1', '14 Oak Street')
        ->set('addressPostalCode', '30301')
        ->call('saveProperty')
        ->assertHasNoErrors();

    expect(Property::query()->sole()->address_entry)->toBe('manual');
});

test('a property needs a street address and a type', function (array $fields, string $error) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('addProperty');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('saveProperty')->assertHasErrors($error);

    expect(Property::query()->count())->toBe(0);
})->with([
    'nothing typed' => [[], 'addressLine1'],
    'a city and zip but no street' => [['addressCity' => 'Lakeview', 'addressPostalCode' => '30301'], 'addressLine1'],
    'a type that does not exist' => [['addressLine1' => '14 Oak Street', 'propertyType' => 'castle'], 'propertyType'],
]);

test('a label is optional, and the quick picks are only a starting point', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->assertSeeInOrder(['Home', 'Rental', 'Office', 'Shop', 'Other'])
        ->set('addressLine1', '14 Oak Street')
        ->call('saveProperty')
        ->assertHasNoErrors();

    expect(Property::query()->sole()->label)->toBeNull();

    $page->call('addProperty')->set('propertyLabel', 'Lake cabin')->set('addressLine1', '2 Shore Road')->call('saveProperty')->assertHasNoErrors();

    expect(Property::query()->where('label', 'Lake cabin')->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The same address
|--------------------------------------------------------------------------
*/

test('a customer is asked before being given a second property at the same address', function (string $typedStreet) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($account)->create([
        'label' => 'Shop', 'address' => '14 Oak Street, Lakeview, GA 30301', 'address_line1' => '14 Oak Street', 'address_postal_code' => '30301',
        'address_normalized' => AddressKey::for('14 Oak Street', null, '30301'),
    ]);

    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->set('addressLine1', $typedStreet)
        ->set('addressPostalCode', '30301-1234')
        ->call('saveProperty')
        ->assertHasErrors('sameAddress')
        ->assertSee('This customer already has a property at this address (Shop — 14 Oak St).');

    expect(Property::query()->count())->toBe(1);

    // It really is a separate place, so the person says so and it is saved.
    $page->call('savePropertyAnyway')->assertHasNoErrors();

    expect(Property::query()->count())->toBe(2);
})->with([
    'typed the same way' => ['14 Oak Street'],
    'abbreviated, another case, with punctuation' => ['14 OAK ST.'],
]);

test('a different unit, or a different zip code, is not the same address', function (array $fields) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($account)->create([
        'address_line1' => '14 Oak Street', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 Oak Street', null, '30301'),
    ]);

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('addProperty');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('saveProperty')->assertHasNoErrors();

    expect(Property::query()->count())->toBe(2);
})->with([
    'another unit' => [['addressLine1' => '14 Oak Street', 'addressLine2' => 'Unit B', 'addressPostalCode' => '30301']],
    'another zip code' => [['addressLine1' => '14 Oak Street', 'addressPostalCode' => '30399']],
    'another number' => [['addressLine1' => '16 Oak Street', 'addressPostalCode' => '30301']],
]);

test('saving anyway only works after the question has actually been asked', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($account)->create([
        'address_line1' => '14 Oak Street', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 Oak Street', null, '30301'),
    ]);

    // Skipping straight to "save anyway" is treated as an ordinary save, and is questioned.
    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->set('addressLine1', '14 Oak Street')
        ->set('addressPostalCode', '30301')
        ->call('savePropertyAnyway')
        ->assertHasErrors('sameAddress');

    expect(Property::query()->count())->toBe(1);
});

test('the same address under a different customer is ordinary and is not questioned', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $landlord = Account::factory()->commercial('Oak Street Owners')->create();
    $tenant = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($landlord)->create([
        'address_line1' => '14 Oak Street', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 Oak Street', null, '30301'),
    ]);

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $tenant])
        ->call('addProperty')
        ->set('addressLine1', '14 Oak Street')
        ->set('addressPostalCode', '30301')
        ->call('saveProperty')
        ->assertHasNoErrors()
        ->assertDontSee('Oak Street Owners');

    expect($tenant->properties()->count())->toBe(1);
});

test('two lookups of the same place are recognized even if they read differently', function () {
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Property::factory()->of($account)->create(['address_provider_place_id' => 'mbx-oak-14', 'address_line1' => '14 Oak St', 'address_normalized' => 'something else']);

    $again = new StructuredAddress(formatted: '14 Oak Street, Lakeview', line1: '14 Oak Street', providerPlaceId: 'mbx-oak-14', entry: StructuredAddress::LOOKUP);

    expect(SaveProperty::sameAddressAt($account, $again))->not->toBeNull();
    expect(fn () => app(SaveProperty::class)->add($account, 'Shop', PropertyType::Commercial, $again))->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Editing a property
|--------------------------------------------------------------------------
*/

test('a property\'s label, type and address can be corrected', function () {
    $lakeview = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = customerWithLeadAt($lakeview);
    $property = Property::factory()->of($account)->create(['label' => 'Home', 'address_line1' => '14 Oak Street', 'address_postal_code' => '30301']);

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editProperty', $property->id)
        ->assertSet('propertyLabel', 'Home')
        ->assertSet('propertyType', 'residential')
        ->assertSet('addressMode', 'manual')
        ->assertSet('addressLine1', '14 Oak Street')
        ->set('propertyLabel', 'Rental')
        ->set('propertyType', 'commercial')
        ->set('addressLine1', '16 Oak Street')
        ->call('saveProperty')
        ->assertHasNoErrors();

    $corrected = $property->fresh();

    expect($corrected->label)->toBe('Rental');
    expect($corrected->type)->toBe(PropertyType::Commercial);
    expect($corrected->address_line1)->toBe('16 Oak Street');
    expect($corrected->address_normalized)->toBe('16 oak st||30301');
    expect(Property::query()->count())->toBe(1);
});

test('correcting only the label of a looked-up property keeps its map position', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $property = Property::factory()->of($account)->create([
        'label' => 'Shop', 'address' => '14 Oak Street, Lakeview, Georgia 30301, United States', 'address_entry' => 'lookup',
        'address_latitude' => 33.7701, 'address_longitude' => -84.3902, 'address_provider' => 'mapbox', 'address_provider_place_id' => 'mbx-oak-14',
    ]);

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editProperty', $property->id)
        ->assertSet('addressMode', 'selected')
        ->set('propertyLabel', 'Main shop')
        ->call('saveProperty')
        ->assertHasNoErrors();

    $corrected = $property->fresh();

    expect($corrected->label)->toBe('Main shop');
    expect((float) $corrected->address_latitude)->toBe(33.7701);
    expect($corrected->address_provider_place_id)->toBe('mbx-oak-14');

    Http::assertNothingSent();
});

test('coordinates cannot be put on a property from the browser', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('addProperty');

    // The selected address is held where the browser cannot change it.
    expect(fn () => $page->set('selectedAddress', ['formatted' => 'Anywhere', 'latitude' => 1.0, 'longitude' => 2.0, 'entry' => 'lookup']))->toThrow(Exception::class);

    expect(Property::query()->count())->toBe(0);
});

test('a property of another customer cannot be reached through this customer\'s page', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $mine = customerWithLeadAt($lakeview);
    $hidden = customerWithLeadAt($northside);
    $hiddenProperty = Property::factory()->of($hidden)->create(['label' => 'Secret', 'address_line1' => '1 Hidden Lane']);

    Livewire::actingAs($consultant)->test('pages::customers.show', ['account' => $mine])->call('editProperty', $hiddenProperty->id)->assertNotFound();

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $mine])
        ->assertDontSee('1 Hidden Lane')
        ->assertDontSee('Secret');

    expect($hiddenProperty->fresh()->label)->toBe('Secret');
});

test('a property never stores, shows or depends on a company location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview Branch']);
    $northside = Location::factory()->create(['name' => 'Northside Branch']);

    // A consultant at Lakeview adds a property far outside any service area.
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = customerWithLeadAt($lakeview);

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->set('addressLine1', '500 Faraway Road')
        ->set('addressPostalCode', '99999')
        ->call('saveProperty')
        ->assertHasNoErrors();

    expect(array_keys(Property::query()->sole()->getAttributes()))->not->toContain('location_id');

    // Staff at another location still cannot see the customer because of where the property is.
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create();

    $this->actingAs($elsewhere)->get(route('customers.show', $account))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Archiving and restoring: a company-wide management action
|--------------------------------------------------------------------------
*/

test('owner and leadership with an all-locations grant can archive and restore a property', function (Role $role) {
    Location::factory()->create();

    $manager = User::factory()->withRoleEverywhere($role)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $property = Property::factory()->of($account)->create(['label' => 'Old shop', 'address_line1' => '14 Oak Street']);

    $page = Livewire::actingAs($manager)
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="archive-property"')
        ->call('archiveProperty', $property->id)
        ->assertHasNoErrors()
        ->assertSee('No properties yet.');

    expect($property->fresh()->isArchived())->toBeTrue();
    expect($property->fresh()->archived_by)->toBe($manager->id);

    $page->set('showRemoved', true)
        ->assertSee('Archived properties')
        ->assertSee('Old shop — 14 Oak St')
        ->call('restoreProperty', $property->id)
        ->assertHasNoErrors();

    expect($property->fresh()->isArchived())->toBeFalse();
    expect($property->fresh()->archived_by)->toBeNull();
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
]);

test('nobody without that all-locations grant can archive or restore a property', function (Closure $user) {
    $lakeview = Location::factory()->create();

    $user = $user($lakeview);
    $account = customerWithLeadAt($lakeview);
    $property = Property::factory()->of($account)->create();
    $archived = Property::factory()->of($account)->archived()->create(['label' => 'Archived earlier']);

    Livewire::actingAs($user)
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="edit-property"')
        ->assertDontSeeHtml('data-test="archive-property"')
        ->set('showRemoved', true)
        ->assertDontSee('Archived earlier')
        ->call('archiveProperty', $property->id)
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::customers.show', ['account' => $account])->call('restoreProperty', $archived->id)->assertForbidden();

    expect($property->fresh()->isArchived())->toBeFalse();
    expect($archived->fresh()->isArchived())->toBeTrue();
})->with([
    'leadership at one location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::Leadership, $lakeview)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'location manager everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::LocationManager)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('a property on an active lead cannot be archived', function () {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $property = Property::factory()->of($account)->create();

    Lead::factory()->count(2)->at($lakeview)->from($account, null, $property)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('archiveProperty', $property->id)
        ->assertHasErrors('property')
        ->assertSee('This property is on 2 active leads, so cannot be archived yet.');

    expect(fn () => app(ArchiveProperty::class)->archive($property->fresh(), $owner))->toThrow(ValidationException::class);

    expect($property->fresh()->isArchived())->toBeFalse();
});

test('an archived property cannot be edited until it is restored', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $property = Property::factory()->of($account)->archived()->create(['label' => 'Old shop']);

    Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('editProperty', $property->id)->assertNotFound();

    expect(fn () => app(SaveProperty::class)->update($property, 'New label', PropertyType::Commercial, StructuredAddress::ofProperty($property)))
        ->toThrow(ValidationException::class);

    expect($property->fresh()->label)->toBe('Old shop');
});

/*
|--------------------------------------------------------------------------
| Address matching, on its own
|--------------------------------------------------------------------------
*/

test('addresses that are the same place typed differently simplify to the same thing', function (string $line1, ?string $line2, ?string $zip, ?string $expected) {
    expect(AddressKey::for($line1, $line2, $zip))->toBe($expected);
})->with([
    'written out' => ['14 Oak Street', null, '30301', '14 oak st||30301'],
    'abbreviated with punctuation' => ['14 Oak St.', null, '30301', '14 oak st||30301'],
    'another case and spacing' => ['  14   OAK   STREET ', null, '30301-1234', '14 oak st||30301'],
    'a direction' => ['220 North Mesa Drive', null, '87402', '220 n mesa dr||87402'],
    'a unit' => ['14 Oak Street', 'Apartment 3', '30301', '14 oak st|apt 3|30301'],
    'no zip code' => ['14 Oak Street', null, null, '14 oak st||'],
    'no street' => ['', 'Unit 3', '30301', null],
]);

test('a street is shortened for lists without touching its name', function (string $line1, string $expected) {
    expect(AddressKey::shortStreet($line1))->toBe($expected);
})->with([
    ['123 Oak Street', '123 Oak St'],
    ['456 Main Street', '456 Main St'],
    ['220 North Mesa Drive', '220 N Mesa Dr'],
    ['9 New Build Way', '9 New Build Way'],
    // A street named after a street word keeps its name.
    ['Court Street', 'Court St'],
    ['1 Main St.', '1 Main St.'],
]);
