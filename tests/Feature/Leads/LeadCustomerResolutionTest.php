<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ResolveLeadCustomer;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Enums\PropertyType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use App\Support\Addresses\AddressKey;
use App\Support\Customers\ContactDetails;
use App\Support\Customers\CustomerResolution;
use App\Support\Customers\InquiryName;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A lead as it would arrive: a name, a phone, an email and a typed address.
 *
 * @param  array<string, mixed>  $overrides
 */
function inquiryFrom(?Location $location = null, array $overrides = []): Lead
{
    $factory = $location === null ? Lead::factory() : Lead::factory()->at($location);

    return $factory->create([
        'inquiry_name' => 'Maria Lopez',
        'inquiry_phone' => '(555) 201-3344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'Maria@Example.com',
        'inquiry_email_normalized' => 'maria@example.com',
        'inquiry_address' => '14 Oak Street, Lakeview, GA 30301',
        'address_line1' => '14 Oak Street',
        'address_city' => 'Lakeview',
        'address_state' => 'GA',
        'address_postal_code' => '30301',
        'address_entry' => 'manual',
        'inquiry_message' => 'Wants the porch repainted.',
        ...$overrides,
    ]);
}

/**
 * Everything about a lead except its three customer links and when it was last saved.
 *
 * @return array<string, mixed>
 */
function inquiryOf(Lead $lead): array
{
    return array_diff_key($lead->fresh()->getAttributes(), array_flip(['account_id', 'contact_id', 'property_id', 'updated_at']));
}

/*
|--------------------------------------------------------------------------
| Making a customer from what the lead already says
|--------------------------------------------------------------------------
*/

test('a lead with no match becomes a new household without retyping anything', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview);
    $inquiry = inquiryOf($lead);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSee('Who is this lead from?')
        // With nothing to match, "New customer" is already chosen and filled in from the inquiry.
        ->assertSet('customerChoice', 'new')
        ->assertSet('newType', 'residential')
        ->assertSet('contactChoice', 'new')
        ->assertSet('contactFirstName', 'Maria')
        ->assertSet('contactLastName', 'Lopez')
        ->assertSet('contactPhone', '(555) 201-3344')
        ->assertSet('contactEmail', 'Maria@Example.com')
        ->assertSet('contactRoles', ['owner'])
        ->assertSet('propertyChoice', 'new')
        ->assertSet('propertyLabel', 'Home')
        ->assertSet('propertyType', 'residential')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $lead));

    $account = Account::query()->sole();
    $contact = Contact::query()->sole();
    $property = Property::query()->sole();

    expect($account->type)->toBe(AccountType::Residential);
    expect($account->display_name)->toBe('Maria Lopez');
    expect($account->contactLinks()->sole()->is_primary)->toBeTrue();
    expect($account->contactLinks()->sole()->roleLabels())->toBe(['Owner']);
    expect($contact->phone_normalized)->toBe('+15552013344');
    expect($contact->email_normalized)->toBe('maria@example.com');
    expect($property->account->is($account))->toBeTrue();
    expect($property->address)->toBe('14 Oak Street, Lakeview, GA 30301');
    expect($property->address_entry)->toBe('manual');
    expect($property->address_normalized)->toBe(AddressKey::for('14 Oak Street', null, '30301'));

    $linked = $lead->fresh();

    expect($linked->account->is($account))->toBeTrue();
    expect($linked->contact->is($contact))->toBeTrue();
    expect($linked->property->is($property))->toBeTrue();

    // The inquiry is exactly as it was received.
    expect(inquiryOf($lead))->toBe($inquiry);

    Http::assertNothingSent();
});

test('a looked-up address is carried onto the property with its map position, and no new lookup is made', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = inquiryFrom($lakeview, [
        'inquiry_address' => '14 Oak Street, Lakeview, Georgia 30301, United States',
        'address_entry' => 'lookup',
        'address_country' => 'US',
        'address_latitude' => 33.7701,
        'address_longitude' => -84.3902,
        'address_accuracy' => 'rooftop',
        'address_provider' => 'mapbox',
        'address_provider_place_id' => 'mbx-oak-14',
    ]);

    Livewire::actingAs($consultant)->test('pages::leads.customer', ['lead' => $lead])->call('save')->assertHasNoErrors();

    $property = Property::query()->sole();

    expect($property->address_entry)->toBe('lookup');
    expect((float) $property->address_latitude)->toBe(33.7701);
    expect((float) $property->address_longitude)->toBe(-84.3902);
    expect($property->address_provider_place_id)->toBe('mbx-oak-14');

    Http::assertNothingSent();
});

test('a lead that names a business starts as a commercial customer', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview, ['inquiry_name' => 'Pat Baker', 'inquiry_business_name' => 'Bakery on Main']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSet('newType', 'commercial')
        ->assertSet('newBusinessName', 'Bakery on Main')
        ->assertSet('contactRoles', ['business_contact'])
        ->assertSet('propertyLabel', 'Office')
        ->assertSet('propertyType', 'commercial')
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->sole();

    expect($account->type)->toBe(AccountType::Commercial);
    expect($account->display_name)->toBe('Bakery on Main');
    expect($account->primaryContact()->fullName())->toBe('Pat Baker');
    expect(Property::query()->sole()->type)->toBe(PropertyType::Commercial);
});

test('a business can be linked with no contact and no property yet; a household cannot go without a contact', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview, ['inquiry_business_name' => 'Bakery on Main']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->set('newType', 'residential')
        ->set('contactChoice', 'none')
        ->call('save')
        ->assertHasErrors('contactChoice')
        ->set('newType', 'commercial')
        ->set('contactChoice', 'none')
        ->set('propertyChoice', 'none')
        ->call('save')
        ->assertHasNoErrors();

    expect(Account::query()->sole()->contactLinks()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
    expect(Property::query()->count())->toBe(0);
    expect($lead->fresh()->account_id)->not->toBeNull();
    expect($lead->fresh()->contact_id)->toBeNull();
});

test('a name that looks like two people is kept whole for staff to sort out', function (string $name, ?string $first, ?string $last) {
    expect(InquiryName::split($name))->toBe(['first_name' => $first, 'last_name' => $last]);
})->with([
    ['Maria Lopez', 'Maria', 'Lopez'],
    ['Mary Ann Smith', 'Mary Ann', 'Smith'],
    ['  maria   lopez ', 'maria', 'lopez'],
    ['Cher', 'Cher', null],
    ['John & Mary Smith', 'John & Mary Smith', null],
    ['Bob and Sue Jones', 'Bob and Sue Jones', null],
    ['Smith, John', 'Smith, John', null],
    ['', null, null],
]);

test('a compound name on a lead makes one contact, never two', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview, ['inquiry_name' => 'John & Mary Smith']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSet('contactFirstName', 'John & Mary Smith')
        ->assertSet('contactLastName', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Contact::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Linking to a customer that already exists
|--------------------------------------------------------------------------
*/

test('an obvious match is offered with the reason, and linking makes nothing new', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $maria = Contact::factory()->named('Maria', 'Lopez')->create(['phone' => '555-201-3344', 'phone_normalized' => '+15552013344']);
    $adam = Contact::factory()->named('Adam', 'Lopez')->create();
    $account = Account::factory()->withPrimaryContact($adam)->withContact($maria, [ContactRole::SpousePartner])->create();
    $home = Property::factory()->of($account)->create(['label' => 'Home', 'address_line1' => '14 OAK ST', 'address_normalized' => AddressKey::for('14 Oak St', null, '30301')]);
    Property::factory()->of($account)->create(['label' => 'Rental']);

    // An earlier lead here is what lets this consultant see the customer.
    Lead::factory()->at($lakeview)->from($account)->create();

    $lead = inquiryFrom($lakeview);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSeeInOrder(['Adam Lopez', 'same phone, property at this address'])
        // The one match is chosen, with the person and the place that matched.
        ->assertSet('customerChoice', 'account:'.$account->id)
        ->assertSet('contactChoice', 'contact:'.$maria->id)
        ->assertSet('propertyChoice', 'property:'.$home->id)
        ->assertSee('matches the inquiry')
        ->assertSee('the address on the inquiry')
        // The customer already has a property at that address, so adding another is not offered.
        ->assertDontSeeHtml('data-test="property-new"')
        ->call('save')
        ->assertHasNoErrors();

    $linked = $lead->fresh();

    expect($linked->account->is($account))->toBeTrue();
    expect($linked->contact->is($maria))->toBeTrue();
    expect($linked->property->is($home))->toBeTrue();
    expect(Account::query()->count())->toBe(1);
    expect(Contact::query()->count())->toBe(2);
    expect(Property::query()->count())->toBe(2);
});

test('a customer can be found by searching, and given a new contact and property from the lead', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $account = Account::factory()->commercial('Bakery on Main')
        ->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create())
        ->withContact(Contact::factory()->named('Paula', 'Manager')->create())
        ->create();

    Lead::factory()->at($lakeview)->from($account)->create();
    Account::factory()->commercial('Hidden Bakery')->create();

    // Nothing on this lead matches the bakery: a new employee called from a new site.
    $lead = inquiryFrom($lakeview, ['inquiry_name' => 'Nina New']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->set('customerSearch', 'bakery')
        ->assertSee('Bakery on Main')
        // A customer this person cannot see is never found by searching.
        ->assertDontSee('Hidden Bakery')
        ->call('chooseCustomer', 'account:'.$account->id)
        // With two people there and no match, nobody is assumed.
        ->assertSet('contactChoice', '')
        ->assertSet('propertyChoice', '')
        ->call('save')
        ->assertHasErrors('contactChoice')
        ->set('contactChoice', 'new')
        ->assertSet('contactFirstName', 'Nina')
        ->set('contactRoles', ['business_contact', 'billing_contact'])
        ->call('save')
        ->assertHasErrors('propertyChoice')
        ->set('propertyChoice', 'new')
        ->set('propertyLabel', 'Second shop')
        ->call('save')
        ->assertHasNoErrors();

    $nina = Contact::query()->where('first_name', 'Nina')->sole();
    $link = $account->contactLinks()->where('contact_id', $nina->id)->sole();

    expect($link->is_primary)->toBeFalse();
    expect($link->roleLabels())->toBe(['Business Contact', 'Billing Contact']);
    expect($account->properties()->sole()->label)->toBe('Second shop');
    expect($lead->fresh()->contact->is($nina))->toBeTrue();
    expect($lead->fresh()->property->account->is($account))->toBeTrue();
});

test('someone already known is connected to the new customer instead of being entered again', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // Paula manages Oak Street, and now calls about a different owner's property.
    $paula = Contact::factory()->named('Paula', 'Manager')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);
    $oak = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula, [ContactRole::PropertyManager])->create();

    Lead::factory()->at($lakeview)->from($oak)->create();

    $lead = inquiryFrom($lakeview, ['inquiry_name' => 'Paula Manager', 'inquiry_business_name' => 'Elm Street Owners', 'inquiry_email' => null, 'inquiry_email_normalized' => null]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSee('Oak Street Owners')
        ->call('chooseCustomer', 'new')
        // She is offered as the same person, and chosen, because exactly one person matches.
        ->assertSet('contactChoice', 'link:'.$paula->id)
        ->assertSee('Already a contact of:')
        ->set('contactRoles', ['property_manager'])
        ->call('save')
        ->assertHasNoErrors();

    $elm = Account::query()->where('business_name', 'Elm Street Owners')->sole();

    expect(Contact::query()->count())->toBe(1);
    expect($elm->primaryContact()->is($paula))->toBeTrue();
    expect($paula->accountLinks()->count())->toBe(2);
    expect($lead->fresh()->account->is($elm))->toBeTrue();
    expect($lead->fresh()->contact->is($paula))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Who may resolve a lead, and what they may reach
|--------------------------------------------------------------------------
*/

test('everyone who works leads at the location can resolve a lead there', function (Closure $user) {
    $lakeview = Location::factory()->create();
    $lead = inquiryFrom($lakeview);

    $user = $user($lakeview);

    $this->actingAs($user)->get(route('leads.show', $lead))->assertOk()->assertSee('Not linked to a customer yet.')->assertSee('Resolve customer');
    $this->actingAs($user)->get(route('leads.customer', $lead))->assertOk()->assertSee('Who is this lead from?');
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('a lead cannot be resolved by someone who cannot work it', function (Closure $user) {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();
    $lead = inquiryFrom($lakeview);

    $user = $user($northside);

    $this->actingAs($user)->get(route('leads.customer', $lead))->assertForbidden();

    Livewire::actingAs($user)->test('pages::leads.customer', ['lead' => $lead])->assertForbidden();

    expect(Account::query()->count())->toBe(0);
})->with([
    'a sales consultant at another location' => [fn (Location $northside) => User::factory()->withRoleAt(Role::SalesConsultant, $northside)->create()],
    'finance' => [fn () => User::factory()->withRoleEverywhere(Role::Finance)->create()],
    'a production manager' => [fn () => User::factory()->withRoleEverywhere(Role::ProductionManager)->create()],
]);

test('an unrouted lead is resolved by company-wide staff', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = inquiryFrom();

    Livewire::actingAs($agent)->test('pages::leads.customer', ['lead' => $lead])->call('save')->assertHasNoErrors();

    expect($lead->fresh()->account)->not->toBeNull();
});

test('the page stops working once the person can no longer work the lead', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview);

    $page = Livewire::actingAs($consultant)->test('pages::leads.customer', ['lead' => $lead]);

    $lead->forceFill(['location_id' => $northside->id])->save();

    $page->call('save')->assertForbidden();

    expect(Account::query()->count())->toBe(0);
});

test('a customer the person cannot see cannot be chosen, whatever the browser sends', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $hidden = Account::factory()->commercial('Northside Secret Holdings')->withPrimaryContact()->create();
    $archived = Account::factory()->commercial('Archived Business')->archived()->create();

    Lead::factory()->at($northside)->from($hidden)->create();
    Lead::factory()->at($lakeview)->from($archived)->create();

    $lead = inquiryFrom($lakeview);

    foreach ([$hidden, $archived] as $account) {
        // Choosing it does nothing...
        Livewire::actingAs($consultant)
            ->test('pages::leads.customer', ['lead' => $lead])
            ->call('chooseCustomer', 'account:'.$account->id)
            ->assertSet('customerChoice', '')
            ->assertDontSee($account->display_name);

        // ...and naming it directly is refused.
        Livewire::actingAs($consultant)
            ->test('pages::leads.customer', ['lead' => $lead])
            ->set('customerChoice', 'account:'.$account->id)
            ->set('contactChoice', 'none')
            ->set('propertyChoice', 'none')
            ->call('save')
            ->assertNotFound();
    }

    expect($lead->fresh()->account_id)->toBeNull();
});

test('a contact or property of another customer cannot be attached to the lead', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $mine = Account::factory()->commercial('Bakery on Main')->withPrimaryContact()->create();
    $other = Account::factory()->commercial('Other Business')->withPrimaryContact()->create();
    $otherProperty = Property::factory()->of($other)->create();

    Lead::factory()->at($lakeview)->from($mine)->create();
    Lead::factory()->at($lakeview)->from($other)->create();

    $lead = inquiryFrom($lakeview);

    foreach ([['contactChoice' => 'contact:'.$other->primaryContact()->id, 'propertyChoice' => 'none'], ['contactChoice' => 'contact:'.$mine->primaryContact()->id, 'propertyChoice' => 'property:'.$otherProperty->id]] as $choices) {
        Livewire::actingAs($consultant)
            ->test('pages::leads.customer', ['lead' => $lead])
            ->call('chooseCustomer', 'account:'.$mine->id)
            ->set('contactChoice', $choices['contactChoice'])
            ->set('propertyChoice', $choices['propertyChoice'])
            ->call('save')
            ->assertNotFound();
    }

    // The action itself refuses a property that is not the customer's.
    expect(fn () => app(ResolveLeadCustomer::class)->handle($lead, new CustomerResolution(account: $mine, contact: $mine->primaryContact(), property: $otherProperty)))
        ->toThrow(ValidationException::class);

    expect($lead->fresh()->account_id)->toBeNull();
});

test('a page that is out of date saves nothing', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = inquiryFrom($lakeview);

    $page = Livewire::actingAs($consultant)->test('pages::leads.customer', ['lead' => $lead]);

    // Someone else links the lead while this page is open.
    $elsewhere = Account::factory()->commercial('Linked By Someone Else')->create();
    $lead->forceFill(['account_id' => $elsewhere->id])->save();

    $page->call('save')->assertHasErrors('customerChoice');

    expect($lead->fresh()->account->is($elsewhere))->toBeTrue();
    expect(Account::query()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| All or nothing
|--------------------------------------------------------------------------
*/

test('if any part of a resolution is refused, nothing is left behind', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    // A lead with no address cannot have a property made from it.
    $lead = inquiryFrom($lakeview, ['inquiry_address' => null, 'address_line1' => null, 'address_city' => null, 'address_state' => null, 'address_postal_code' => null, 'address_entry' => null]);

    expect(fn () => app(ResolveLeadCustomer::class)->handle($lead, new CustomerResolution(
        newType: AccountType::Residential,
        newContact: ContactDetails::fromInput(['first_name' => 'Maria']),
        roles: [ContactRole::Owner],
        createProperty: true,
    )))->toThrow(ValidationException::class);

    // A person with no name is refused after the customer would have been made.
    expect(fn () => app(ResolveLeadCustomer::class)->handle($lead, new CustomerResolution(
        newType: AccountType::Commercial,
        newBusinessName: 'Bakery on Main',
        newContact: ContactDetails::fromInput(['phone' => '5552013344']),
        roles: [ContactRole::Owner],
    )))->toThrow(ValidationException::class);

    expect(Account::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
    expect(Property::query()->count())->toBe(0);
    expect($lead->fresh()->account_id)->toBeNull();
    expect(AuditEvent::query()->count())->toBe(0);
});

test('nothing is kept when the audit event cannot be recorded', function () {
    $lakeview = Location::factory()->create();
    $lead = inquiryFrom($lakeview);

    $this->mock(RecordAuditEvent::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(ResolveLeadCustomer::class)->handle($lead, new CustomerResolution(
        newType: AccountType::Residential,
        newContact: ContactDetails::fromInput(['first_name' => 'Maria']),
        roles: [ContactRole::Owner],
        createProperty: true,
    )))->toThrow(RuntimeException::class);

    expect(Account::query()->count())->toBe(0);
    expect(Contact::query()->count())->toBe(0);
    expect(Property::query()->count())->toBe(0);
    expect($lead->fresh()->account_id)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Changing and removing the link
|--------------------------------------------------------------------------
*/

test('a linked lead shows its customer, and the link can be changed or removed while the lead is active', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $maria = Contact::factory()->named('Maria', 'Lopez')->create();
    $account = Account::factory()->withPrimaryContact($maria)->create();
    $home = Property::factory()->of($account)->create(['label' => 'Home', 'address_line1' => '14 Oak Street']);
    $other = Account::factory()->commercial('Bakery on Main')->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create())->create();

    Lead::factory()->at($lakeview)->from($other)->create();

    $lead = inquiryFrom($lakeview, ['inquiry_phone' => null, 'inquiry_phone_normalized' => null, 'inquiry_email' => null, 'inquiry_email_normalized' => null, 'inquiry_address' => null, 'address_line1' => null, 'address_postal_code' => null]);
    $lead->forceFill(['account_id' => $account->id, 'contact_id' => $maria->id, 'property_id' => $home->id])->save();
    $inquiry = inquiryOf($lead);

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertOk()
        ->assertSeeInOrder(['Customer', 'Maria Lopez', 'Residential', 'Home — 14 Oak St'])
        ->assertSee('href="'.route('customers.show', $account).'"', false)
        ->assertSee('Change')
        ->assertSee('Remove link');

    // "Change" starts from what the lead is linked to now.
    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->assertSet('customerChoice', 'account:'.$account->id)
        ->assertSet('contactChoice', 'contact:'.$maria->id)
        ->assertSet('propertyChoice', 'property:'.$home->id)
        ->call('chooseCustomer', 'account:'.$other->id)
        ->set('propertyChoice', 'none')
        ->call('save')
        ->assertHasNoErrors();

    expect($lead->fresh()->account->is($other))->toBeTrue();
    expect($lead->fresh()->contact->fullName())->toBe('Pat Baker');
    expect($lead->fresh()->property_id)->toBeNull();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('unlinkCustomer')
        ->assertHasNoErrors()
        ->assertSee('Not linked to a customer yet.');

    $unlinked = $lead->fresh();

    expect($unlinked->account_id)->toBeNull();
    expect($unlinked->contact_id)->toBeNull();
    expect($unlinked->property_id)->toBeNull();
    // Neither customer, nor the inquiry, was touched by any of it.
    expect(Account::query()->count())->toBe(2);
    expect(inquiryOf($lead))->toBe($inquiry);
});

test('someone who cannot resolve the lead cannot remove its link', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->call('unlinkCustomer')->assertForbidden();

    expect($lead->fresh()->account->is($account))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

test('linking, changing and removing a lead\'s customer are each recorded about the lead', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $other = Account::factory()->commercial('Bakery on Main')->create();

    Lead::factory()->at($lakeview)->from($other)->create();

    $lead = inquiryFrom($lakeview);

    Livewire::actingAs($consultant)->test('pages::leads.customer', ['lead' => $lead])->call('save')->assertHasNoErrors();

    $linked = AuditEvent::query()->where('action', AuditAction::LeadCustomerLinked)->sole();

    expect($linked->actor->is($consultant))->toBeTrue();
    expect($linked->subject->is($lead))->toBeTrue();
    expect($linked->location->is($lakeview))->toBeTrue();
    expect($linked->before())->toBe([]);
    expect($linked->after()['customer'])->toBe('Maria Lopez');
    expect($linked->after()['contact'])->toBe('Maria Lopez');
    expect($linked->after()['property'])->toBe('Home — 14 Oak St');
    expect($linked->after()['customer_created'])->toBeTrue();
    expect($linked->after()['matched_outside_view'])->toBeFalse();

    // Making the customer, the person and the property were recorded as themselves too.
    foreach ([AuditAction::AccountCreated, AuditAction::ContactCreated, AuditAction::ContactConnected, AuditAction::PropertyCreated] as $action) {
        expect(AuditEvent::query()->where('action', $action)->count())->toBe(1);
    }

    Livewire::actingAs($consultant)
        ->test('pages::leads.customer', ['lead' => $lead->fresh()])
        ->call('chooseCustomer', 'account:'.$other->id)
        ->set('contactChoice', 'none')
        ->set('propertyChoice', 'none')
        ->call('save')
        ->assertHasNoErrors();

    $changed = AuditEvent::query()->where('action', AuditAction::LeadCustomerLinkChanged)->sole();

    expect($changed->before()['customer'])->toBe('Maria Lopez');
    expect($changed->after()['customer'])->toBe('Bakery on Main');
    expect($changed->after()['customer_created'])->toBeFalse();

    Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead->fresh()])->call('unlinkCustomer');

    $removed = AuditEvent::query()->where('action', AuditAction::LeadCustomerUnlinked)->sole();

    expect($removed->subject->is($lead))->toBeTrue();
    expect($removed->before()['customer'])->toBe('Bakery on Main');
});
