<?php

use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use App\Support\Addresses\AddressKey;
use App\Support\Customers\LeadCustomerMatcher;
use App\Support\Customers\MaskedCustomerMatch;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;

/**
 * A lead with a phone, an email and a typed address, at a location.
 *
 * @param  array<string, mixed>  $overrides
 */
function leadToMatch(Location $location, array $overrides = []): Lead
{
    return Lead::factory()->at($location)->create([
        'inquiry_name' => 'Maria Lopez',
        'inquiry_phone' => '(555) 201-3344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'maria@example.com',
        'inquiry_email_normalized' => 'maria@example.com',
        'inquiry_address' => '14 Oak Street, Lakeview, GA 30301',
        'address_line1' => '14 Oak Street',
        'address_postal_code' => '30301',
        'address_entry' => 'manual',
        ...$overrides,
    ]);
}

/**
 * A customer that staff at the given location can see, because it has a lead there.
 */
function customerSeenAt(Location $location, Account $account): Account
{
    Lead::factory()->at($location)->from($account)->create();

    return $account;
}

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->northside = Location::factory()->create(['name' => 'Northside']);
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
});

/*
|--------------------------------------------------------------------------
| Customers the person can already see
|--------------------------------------------------------------------------
*/

test('a customer is suggested when one of its people or places matches the lead', function (Closure $customer, string $reason) {
    $account = customerSeenAt($this->lakeview, $customer());

    $matches = app(LeadCustomerMatcher::class)->visible(leadToMatch($this->lakeview, ['inquiry_business_name' => 'Lopez Bakery']), $this->consultant);

    expect($matches)->toHaveCount(1);
    expect($matches[0]->account->is($account))->toBeTrue();
    expect($matches[0]->reasonLabel())->toBe($reason);
})->with([
    'the same phone, written another way' => [fn () => Account::factory()->withPrimaryContact(Contact::factory()->create(['phone' => '555.201.3344', 'phone_normalized' => '+15552013344']))->create(), 'same phone'],
    'the same number as an alternate phone' => [fn () => Account::factory()->withPrimaryContact(Contact::factory()->create(['alternate_phone' => '5552013344', 'alternate_phone_normalized' => '+15552013344']))->create(), 'same phone'],
    'the same email, in another case' => [fn () => Account::factory()->withPrimaryContact(Contact::factory()->create(['email' => 'MARIA@example.com', 'email_normalized' => 'maria@example.com']))->create(), 'same email'],
    'a contact who is not the primary one' => [fn () => Account::factory()->withPrimaryContact()->withContact(Contact::factory()->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']))->create(), 'same phone'],
    'a property at the same address, typed another way' => [function () {
        $account = Account::factory()->withPrimaryContact()->create();

        Property::factory()->of($account)->create(['address_line1' => '14 OAK ST.', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 OAK ST.', null, '30301')]);

        return $account;
    }, 'property at this address'],
    'the same business name, without the LLC' => [fn () => Account::factory()->commercial('The Lopez Bakery, LLC')->create(), 'same business name'],
]);

test('a customer that matches in several ways comes first, with every reason', function () {
    $both = customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create([
        'phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'maria@example.com', 'email_normalized' => 'maria@example.com',
    ]))->create());

    Property::factory()->of($both)->create(['address_line1' => '14 Oak Street', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 Oak Street', null, '30301')]);

    $one = customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact(Contact::factory()->named('Adam', 'Other')->create(['email' => 'maria@example.com', 'email_normalized' => 'maria@example.com']))->create());

    $matches = app(LeadCustomerMatcher::class)->visible(leadToMatch($this->lakeview), $this->consultant);

    expect($matches)->toHaveCount(2);
    expect($matches[0]->account->is($both))->toBeTrue();
    expect($matches[0]->reasonLabel())->toBe('same phone, same email, property at this address');
    expect($matches[1]->account->is($one))->toBeTrue();
    expect($matches[1]->reasonLabel())->toBe('same email');

    // More than one possibility: nothing is chosen for the person.
    Livewire::actingAs($this->consultant)
        ->test('pages::leads.customer', ['lead' => leadToMatch($this->lakeview)])
        ->assertSet('customerChoice', '')
        ->assertSeeInOrder(['Maria Lopez', 'Adam Other'])
        ->call('save')
        ->assertHasErrors('customerChoice');
});

test('archived customers, removed contacts and archived properties are not suggested', function () {
    $phone = ['phone' => '5552013344', 'phone_normalized' => '+15552013344'];

    customerSeenAt($this->lakeview, Account::factory()->archived()->withPrimaryContact(Contact::factory()->create($phone))->create());
    customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact()->withContact(Contact::factory()->archived()->create($phone))->create());

    $removed = customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact()->withContact(Contact::factory()->create($phone))->create());
    $removed->contactLinks()->where('is_primary', false)->update(['archived_at' => now()]);

    $sold = customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact()->create());
    Property::factory()->of($sold)->archived()->create(['address_normalized' => AddressKey::for('14 Oak Street', null, '30301')]);

    expect(app(LeadCustomerMatcher::class)->visible(leadToMatch($this->lakeview), $this->consultant))->toBe([]);
    expect(app(LeadCustomerMatcher::class)->masked(leadToMatch($this->lakeview), $this->consultant))->toBeNull();
});

test('a lead with nothing to go on suggests nothing', function () {
    customerSeenAt($this->lakeview, Account::factory()->withPrimaryContact(Contact::factory()->create(['phone' => null, 'phone_normalized' => null, 'email' => null, 'email_normalized' => null]))->create());

    $lead = Lead::factory()->at($this->lakeview)->create(['inquiry_phone' => 'call the office', 'inquiry_phone_normalized' => null]);

    expect(app(LeadCustomerMatcher::class)->visible($lead, $this->consultant))->toBe([]);
});

/*
|--------------------------------------------------------------------------
| A customer outside the person's sight
|--------------------------------------------------------------------------
*/

test('an exact phone match on a hidden customer shows only its kind and a masked number', function () {
    $hidden = customerSeenAt($this->northside, Account::factory()->withPrimaryContact(Contact::factory()->named('Hidden', 'Harriet')->create([
        'phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'harriet@secret.example', 'email_normalized' => 'harriet@secret.example',
    ]))->withContact(Contact::factory()->named('Spouse', 'Secret')->create())->create());

    Property::factory()->of($hidden)->create(['label' => 'Hideaway', 'address' => '77 Secret Lane, Northside, GA 30399', 'address_line1' => '77 Secret Lane']);

    $lead = leadToMatch($this->lakeview, ['inquiry_email' => null, 'inquiry_email_normalized' => null]);

    $page = $this->actingAs($this->consultant)->get(route('leads.customer', $lead))->assertOk();

    $page->assertSee('An existing residential customer has a phone number ending in 44.')
        ->assertSee('It is not one of the customers you can see, so nothing more is shown.');

    // Nothing else about that customer is anywhere on the page, or in what the page holds.
    foreach (['Hidden Harriet', 'Harriet', 'Spouse Secret', 'harriet@secret.example', 'Hideaway', '77 Secret Lane', 'Northside'] as $secret) {
        $page->assertDontSee($secret, false);
    }

    expect($page->getContent())->not->toContain('account:'.$hidden->id);

    // The person must choose it: it is never chosen for them.
    Livewire::actingAs($this->consultant)->test('pages::leads.customer', ['lead' => $lead])->assertSet('customerChoice', '');
});

test('an exact email match on a hidden customer is masked the same way', function () {
    customerSeenAt($this->northside, Account::factory()->commercial('Northside Secret Holdings')->withPrimaryContact(Contact::factory()->create(['email' => 'maria@example.com', 'email_normalized' => 'maria@example.com']))->create());

    $lead = leadToMatch($this->lakeview, ['inquiry_phone' => null, 'inquiry_phone_normalized' => null]);

    $this->actingAs($this->consultant)
        ->get(route('leads.customer', $lead))
        ->assertSee('An existing commercial customer has an email address like m•••@•••.com.')
        ->assertDontSee('Northside Secret Holdings');

    expect(MaskedCustomerMatch::maskEmail('maria@example.com'))->toBe('like m•••@•••.com');
    expect(MaskedCustomerMatch::maskPhone('+15552013344'))->toBe('ending in 44');
});

test('a hidden customer is never suggested on an address or a business name alone', function () {
    $byAddress = customerSeenAt($this->northside, Account::factory()->withPrimaryContact()->create());
    Property::factory()->of($byAddress)->create(['address_line1' => '14 Oak Street', 'address_postal_code' => '30301', 'address_normalized' => AddressKey::for('14 Oak Street', null, '30301')]);

    customerSeenAt($this->northside, Account::factory()->commercial('Lopez Bakery')->create());

    $lead = leadToMatch($this->lakeview, ['inquiry_phone' => null, 'inquiry_phone_normalized' => null, 'inquiry_email' => null, 'inquiry_email_normalized' => null, 'inquiry_business_name' => 'Lopez Bakery']);

    expect(app(LeadCustomerMatcher::class)->visible($lead, $this->consultant))->toBe([]);
    expect(app(LeadCustomerMatcher::class)->masked($lead, $this->consultant))->toBeNull();

    $this->actingAs($this->consultant)
        ->get(route('leads.customer', $lead))
        ->assertOk()
        ->assertDontSee('An existing')
        ->assertDontSee('data-test="masked-match"', false)
        ->assertDontSee('data-test="customer-match"', false);
});

test('nothing is shown when a masked match would give away more than one customer', function (Closure $arrange) {
    $arrange($this->lakeview, $this->northside);

    $lead = leadToMatch($this->lakeview, ['inquiry_email' => null, 'inquiry_email_normalized' => null]);

    expect(app(LeadCustomerMatcher::class)->masked($lead, $this->consultant))->toBeNull();

    $this->actingAs($this->consultant)->get(route('leads.customer', $lead))->assertDontSee('An existing')->assertDontSee('data-test="masked-match"', false);
})->with([
    'the person is a contact of two hidden customers' => [function (Location $lakeview, Location $northside) {
        $shared = Contact::factory()->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);

        customerSeenAt($northside, Account::factory()->commercial('Hidden One')->withPrimaryContact($shared)->create());
        customerSeenAt($northside, Account::factory()->commercial('Hidden Two')->withPrimaryContact($shared)->create());
    }],
    'two people at two hidden customers share the number' => [function (Location $lakeview, Location $northside) {
        customerSeenAt($northside, Account::factory()->withPrimaryContact(Contact::factory()->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']))->create());
        customerSeenAt($northside, Account::factory()->withPrimaryContact(Contact::factory()->create(['alternate_phone' => '5552013344', 'alternate_phone_normalized' => '+15552013344']))->create());
    }],
]);

test('a shared contact\'s hidden customers are never hinted at beside the one the person can see', function () {
    $paula = Contact::factory()->named('Paula', 'Manager')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);

    $visible = customerSeenAt($this->lakeview, Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula)->create());
    customerSeenAt($this->northside, Account::factory()->commercial('Northside Secret Holdings')->withContact($paula, [ContactRole::PropertyManager])->create());

    $lead = leadToMatch($this->lakeview, ['inquiry_email' => null, 'inquiry_email_normalized' => null]);

    $matches = app(LeadCustomerMatcher::class)->visible($lead, $this->consultant);

    expect($matches)->toHaveCount(1);
    expect($matches[0]->account->is($visible))->toBeTrue();
    expect(app(LeadCustomerMatcher::class)->masked($lead, $this->consultant))->toBeNull();

    $this->actingAs($this->consultant)
        ->get(route('leads.customer', $lead))
        ->assertSee('Oak Street Owners')
        ->assertDontSee('Northside Secret Holdings')
        ->assertDontSee('An existing')
        ->assertDontSee('data-test="masked-match"', false);
});

test('company-wide staff see every match plainly, with nothing masked', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    customerSeenAt($this->northside, Account::factory()->withPrimaryContact(Contact::factory()->named('Hidden', 'Harriet')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']))->create());

    $lead = leadToMatch($this->lakeview);

    expect(app(LeadCustomerMatcher::class)->masked($lead, $owner))->toBeNull();

    $this->actingAs($owner)
        ->get(route('leads.customer', $lead))
        ->assertSeeInOrder(['Hidden Harriet', 'same phone'])
        ->assertDontSee('An existing residential customer');
});

/*
|--------------------------------------------------------------------------
| Linking to a masked match
|--------------------------------------------------------------------------
*/

test('location staff can link a lead to a masked match, and then see the customer but never the other location\'s leads', function () {
    $harriet = Contact::factory()->named('Hidden', 'Harriet')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);
    $hidden = Account::factory()->withPrimaryContact($harriet)->create();
    $northsideConsultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->northside)->create(['name' => 'Nora Northside']);

    Property::factory()->of($hidden)->create(['label' => 'Hideaway', 'address' => '77 Secret Lane, Faraway, GA 30399', 'address_line1' => '77 Secret Lane']);
    Lead::factory()->at($this->northside)->from($hidden)->assignedTo($northsideConsultant)->create();

    $lead = leadToMatch($this->lakeview, ['inquiry_email' => null, 'inquiry_email_normalized' => null]);

    // Before linking, the customer is out of reach.
    $this->actingAs($this->consultant)->get(route('customers.show', $hidden))->assertForbidden();

    Livewire::actingAs($this->consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->call('chooseCustomer', 'masked')
        ->assertSee('The lead will be linked to that customer and to the person whose details matched.')
        ->assertDontSee('Hidden Harriet')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', $lead));

    $linked = $lead->fresh();

    expect($linked->account->is($hidden))->toBeTrue();
    expect($linked->contact->is($harriet))->toBeTrue();
    expect($linked->property_id)->toBeNull();
    expect(Account::query()->count())->toBe(1);

    // The link is audited as one made outside the person's sight.
    $event = AuditEvent::query()->where('action', AuditAction::LeadCustomerLinked)->sole();

    expect($event->actor->is($this->consultant))->toBeTrue();
    expect($event->subject->is($lead))->toBeTrue();
    expect($event->after()['matched_outside_view'])->toBeTrue();
    expect($event->after()['customer_created'])->toBeFalse();

    // Their own lead now legitimately shows them the shared customer, its people and its places...
    $customerPage = $this->actingAs($this->consultant)->get(route('customers.show', $hidden))->assertOk();

    $customerPage->assertSee('Hidden Harriet')->assertSee('77 Secret Lane');

    // ...and exactly one lead: theirs. Nothing of Northside's.
    expect(substr_count($customerPage->getContent(), 'data-test="customer-lead"'))->toBe(1);

    $customerPage->assertDontSee('Nora Northside')->assertDontSee('Northside');
});

test('a masked link cannot be made when there is no masked match', function () {
    $hidden = customerSeenAt($this->northside, Account::factory()->withPrimaryContact()->create());

    $lead = leadToMatch($this->lakeview);

    Livewire::actingAs($this->consultant)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->set('customerChoice', 'masked')
        ->call('save')
        ->assertForbidden();

    expect($lead->fresh()->account_id)->toBeNull();
    expect($this->consultant->can('view', $hidden))->toBeFalse();
});

test('a masked match is recorded with the real owner when it is linked while impersonating', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Jacob Ransom']);
    $this->consultant->forceFill(['name' => 'Roseanne Smith'])->save();

    customerSeenAt($this->northside, Account::factory()->withPrimaryContact(Contact::factory()->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']))->create());

    $lead = leadToMatch($this->lakeview, ['inquiry_email' => null, 'inquiry_email_normalized' => null]);

    impersonate($owner, $this->consultant);

    // As the consultant, the Owner sees the masked match, not the customer.
    $page = $this->get(route('leads.customer', $lead))->assertOk()->assertSee('An existing residential customer has a phone number ending in 44.');

    sendLivewireAction(livewireSnapshot($page), 'save', updates: ['customerChoice' => 'masked'])->assertOk();

    $event = AuditEvent::query()->where('action', AuditAction::LeadCustomerLinked)->sole();

    expect($event->madeBy())->toBe('Jacob Ransom, impersonating Roseanne Smith');
    expect($event->after()['matched_outside_view'])->toBeTrue();
});

test('only so many masked matches are shown to one person in an hour', function () {
    foreach (range(1, 21) as $number) {
        $phone = '+1555300'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);

        customerSeenAt($this->northside, Account::factory()->withPrimaryContact(Contact::factory()->create(['phone' => $phone, 'phone_normalized' => $phone]))->create());
    }

    $shown = 0;

    foreach (range(1, 21) as $number) {
        $phone = '+1555300'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
        $lead = leadToMatch($this->lakeview, ['inquiry_phone' => $phone, 'inquiry_phone_normalized' => $phone, 'inquiry_email' => null, 'inquiry_email_normalized' => null]);

        $matcher = app(LeadCustomerMatcher::class);

        if ($matcher->masked($lead, $this->consultant) !== null) {
            $shown++;

            // Seeing the same match again, as a page redraws, does not use up another.
            expect($matcher->masked($lead, $this->consultant))->not->toBeNull();
        }
    }

    expect($shown)->toBe(20);

    RateLimiter::clear('masked-customer-match:'.$this->consultant->id);
});
