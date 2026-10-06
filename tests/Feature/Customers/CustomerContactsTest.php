<?php

use App\Actions\Customers\LinkContactToAccount;
use App\Actions\Customers\RemoveContactFromAccount;
use App\Enums\ContactRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * A customer with a lead at a location, so that location's staff can see and change it.
 */
function customerAt(Location $location, Account $account): Account
{
    Lead::factory()->at($location)->from($account)->create();

    return $account;
}

/*
|--------------------------------------------------------------------------
| Who may change a customer's contacts
|--------------------------------------------------------------------------
*/

test('staff who work leads can add a contact to a customer they can see', function (Closure $user) {
    $lakeview = Location::factory()->create();
    $account = customerAt($lakeview, Account::factory()->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create())->create());

    Livewire::actingAs($user($lakeview))
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="add-contact"')
        ->call('addContact')
        ->set('contactFirstName', '  Adam ')
        ->set('contactLastName', 'Lopez')
        ->set('contactPhone', '(555) 201-3344')
        ->set('contactAlternatePhone', '555.777.8899 ext 4')
        ->set('contactEmail', 'Adam@Example.com')
        ->set('contactRoles', [ContactRole::SpousePartner->value, ContactRole::BillingContact->value])
        ->call('saveContact')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Maria Lopez', 'Primary', 'Adam Lopez'])
        ->assertSee('Spouse / Partner, Billing Contact');

    $contact = Contact::query()->where('first_name', 'Adam')->sole();

    expect($contact->last_name)->toBe('Lopez');
    expect($contact->phone)->toBe('(555) 201-3344');
    expect($contact->phone_normalized)->toBe('+15552013344');
    expect($contact->alternate_phone)->toBe('555.777.8899 ext 4');
    expect($contact->alternate_phone_normalized)->toBe('+15557778899');
    expect($contact->email)->toBe('Adam@Example.com');
    expect($contact->email_normalized)->toBe('adam@example.com');

    $link = $account->contactLinks()->where('contact_id', $contact->id)->sole();

    expect($link->is_primary)->toBeFalse();
    expect($link->roleLabels())->toBe(['Spouse / Partner', 'Billing Contact']);
    expect($account->fresh()->display_name)->toBe('Maria Lopez');
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('roles that only see customers cannot change them', function (Role $role) {
    $lakeview = Location::factory()->create();
    $user = User::factory()->withRoleEverywhere($role)->create();
    $account = customerAt($lakeview, Account::factory()->withPrimaryContact()->create());
    $link = $account->contactLinks()->sole();

    $this->actingAs($user)
        ->get(route('customers.show', $account))
        ->assertOk()
        ->assertDontSee('data-test="add-contact"', false)
        ->assertDontSee('data-test="edit-contact"', false)
        ->assertDontSee('data-test="add-property"', false);

    foreach ([['addContact', []], ['editContact', [$link->id]], ['saveContact', []], ['openLinkContact', []], ['linkContact', []], ['makePrimary', [$link->id]], ['removeContact', [$link->id]], ['addProperty', []], ['saveProperty', []]] as [$action, $params]) {
        Livewire::actingAs($user)->test('pages::customers.show', ['account' => $account])->call($action, ...$params)->assertForbidden();
    }

    expect(Contact::query()->count())->toBe(1);
})->with([
    'finance' => Role::Finance,
    'production manager' => Role::ProductionManager,
]);

test('a customer cannot be changed once the person can no longer see it', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($lakeview)->from($account)->create();

    $page = Livewire::actingAs($consultant)->test('pages::customers.show', ['account' => $account])->call('addContact');

    // The customer's only lead here moves away while the form is open.
    $lead->forceFill(['location_id' => $northside->id])->save();

    $page->set('contactFirstName', 'Sneaky')->set('contactRoles', [ContactRole::Other->value])->call('saveContact')->assertForbidden();

    expect(Contact::query()->where('first_name', 'Sneaky')->exists())->toBeFalse();
});

test('an archived customer cannot be changed', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Closed Business')->archived()->create();

    $this->actingAs($owner)->get(route('customers.show', $account))->assertOk()->assertDontSee('data-test="add-contact"', false);

    Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('addContact')->assertForbidden();

    expect(fn () => app(LinkContactToAccount::class)->handle($account, Contact::factory()->create(), [ContactRole::Other]))
        ->toThrow(ValidationException::class);
});

/*
|--------------------------------------------------------------------------
| Adding and editing
|--------------------------------------------------------------------------
*/

test('a contact needs a name and at least one role', function (array $fields, string $error) {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account])->call('addContact');

    foreach ($fields as $field => $value) {
        $page->set($field, $value);
    }

    $page->call('saveContact')->assertHasErrors($error);

    expect(Contact::query()->count())->toBe(0);
})->with([
    'no name' => [['contactPhone' => '5552013344', 'contactRoles' => ['owner']], 'contactFirstName'],
    'no role' => [['contactFirstName' => 'Pat', 'contactRoles' => []], 'contactRoles'],
    'a role that does not exist' => [['contactFirstName' => 'Pat', 'contactRoles' => ['janitor']], 'contactRoles.0'],
    'a malformed email' => [['contactFirstName' => 'Pat', 'contactEmail' => 'not-an-email', 'contactRoles' => ['owner']], 'contactEmail'],
]);

test('a customer\'s first contact becomes its primary contact, and names a household', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $household = Account::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $household])
        ->call('addContact')
        // The usual role for a household's first contact is already chosen.
        ->assertSet('contactRoles', ['owner'])
        ->set('contactFirstName', 'Maria')
        ->set('contactLastName', 'Lopez')
        ->call('saveContact')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Maria Lopez', 'Primary', 'Owner']);

    expect($household->contactLinks()->sole()->is_primary)->toBeTrue();
    expect($household->fresh()->display_name)->toBe('Maria Lopez');
});

test('a new contact can be made primary as they are added', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $household = Account::factory()->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create())->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $household])
        ->call('addContact')
        ->assertSet('contactRoles', [])
        ->set('contactFirstName', 'Adam')
        ->set('contactLastName', 'Lopez')
        ->set('contactRoles', ['spouse_partner'])
        ->set('contactPrimary', true)
        ->call('saveContact')
        ->assertHasNoErrors();

    expect($household->fresh()->display_name)->toBe('Adam Lopez');
    expect($household->contactLinks()->where('is_primary', true)->count())->toBe(1);
});

test('phone and email are not unique: a second person with the same ones is simply added', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $maria = Contact::factory()->named('Maria', 'Lopez')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'family@example.com', 'email_normalized' => 'family@example.com']);
    $household = Account::factory()->withPrimaryContact($maria)->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $household])
        ->call('addContact')
        ->set('contactFirstName', 'Adam')
        ->set('contactPhone', '555-201-3344')
        ->set('contactEmail', 'family@example.com')
        ->set('contactRoles', ['spouse_partner'])
        ->call('saveContact')
        ->assertHasNoErrors();

    expect(Contact::query()->where('phone_normalized', '+15552013344')->count())->toBe(2);
    expect($household->contactLinks()->count())->toBe(2);
});

test('editing a contact changes the person and their roles at this customer', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $maria = Contact::factory()->named('Maria', 'Lopes')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);
    $household = Account::factory()->withPrimaryContact($maria, [ContactRole::Owner])->create();
    $link = $household->contactLinks()->sole();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $household])
        ->call('editContact', $link->id)
        ->assertSet('contactFirstName', 'Maria')
        ->assertSet('contactLastName', 'Lopes')
        ->assertSet('contactRoles', ['owner'])
        ->assertSee('These details belong to the person.')
        ->set('contactLastName', 'Lopez')
        ->set('contactPhone', '555-999-0000')
        ->set('contactRoles', ['owner', 'billing_contact'])
        ->call('saveContact')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Maria Lopez', 'Primary', 'Owner, Billing Contact', '(555) 999-0000']);

    expect($maria->fresh()->last_name)->toBe('Lopez');
    expect($maria->fresh()->phone_normalized)->toBe('+15559990000');
    expect($link->fresh()->roleLabels())->toBe(['Owner', 'Billing Contact']);
    // The household is named after its primary contact, so it follows the correction.
    expect($household->fresh()->display_name)->toBe('Maria Lopez');
    expect(Contact::query()->count())->toBe(1);
});

test('a role can be taken away as well as added', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Bakery on Main')->withPrimaryContact(null, [ContactRole::BusinessContact, ContactRole::BillingContact, ContactRole::PropertyManager])->create();
    $link = $account->contactLinks()->sole();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editContact', $link->id)
        ->set('contactRoles', ['property_manager'])
        ->call('saveContact')
        ->assertHasNoErrors();

    expect($link->fresh()->roleLabels())->toBe(['Property Manager']);
});

test('a contact of another customer cannot be edited through this customer\'s page', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $mine = customerAt($lakeview, Account::factory()->withPrimaryContact()->create());
    $hidden = customerAt($northside, Account::factory()->withPrimaryContact(Contact::factory()->named('Hidden', 'Harriet')->create())->create());
    $hiddenLink = $hidden->contactLinks()->sole();

    foreach (['editContact', 'makePrimary', 'removeContact'] as $action) {
        Livewire::actingAs($consultant)->test('pages::customers.show', ['account' => $mine])->call($action, $hiddenLink->id)->assertStatus($action === 'removeContact' ? 403 : 404);
    }

    expect($hiddenLink->fresh()->isArchived())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| A shared contact: edited once, for everyone
|--------------------------------------------------------------------------
*/

test('correcting a shared contact applies wherever they are a contact, and reveals nothing about where', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $paula = Contact::factory()->named('Paula', 'Manger')->create();

    $mine = customerAt($lakeview, Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula, [ContactRole::PropertyManager])->create());
    // A household at another location, named after Paula. The consultant cannot see it.
    $hidden = customerAt($northside, Account::factory()->withPrimaryContact($paula, [ContactRole::Owner])->create());

    expect($hidden->fresh()->display_name)->toBe('Paula Manger');

    $shared = Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $mine])
        ->call('editContact', $mine->contactLinks()->sole()->id);

    // The same sentence is shown for every contact, shared or not.
    $shared->assertSee('If they are a contact of another customer, a change here applies there too.')
        ->assertDontSee('Paula Manger is also')
        ->assertDontSee('Northside');

    $shared->set('contactLastName', 'Manager')->call('saveContact')->assertHasNoErrors();

    expect($paula->fresh()->last_name)->toBe('Manager');
    // The hidden household is renamed too, because it is the same person.
    expect($hidden->fresh()->display_name)->toBe('Paula Manager');
    // Her roles at the other customer are untouched.
    expect($hidden->contactLinks()->sole()->roleLabels())->toBe(['Owner']);

    // An unshared contact's form reads exactly the same.
    $solo = customerAt($lakeview, Account::factory()->withPrimaryContact()->create());

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $solo])
        ->call('editContact', $solo->contactLinks()->sole()->id)
        ->assertSee('If they are a contact of another customer, a change here applies there too.');
});

/*
|--------------------------------------------------------------------------
| Linking someone who is already a contact elsewhere
|--------------------------------------------------------------------------
*/

test('an existing contact can be linked to another customer without being copied', function () {
    $lakeview = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $paula = Contact::factory()->named('Paula', 'Manager')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);

    $first = customerAt($lakeview, Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula, [ContactRole::PropertyManager])->create());
    $second = customerAt($lakeview, Account::factory()->commercial('Elm Street Owners')->withPrimaryContact(Contact::factory()->named('Erin', 'Elm')->create())->create());

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $second])
        ->call('openLinkContact')
        ->set('contactSearch', 'paula')
        ->assertSeeInOrder(['Paula Manager', '(555) 201-3344', 'Contact of:', 'Oak Street Owners'])
        ->call('chooseContact', $paula->id)
        ->set('contactRoles', ['property_manager', 'billing_contact'])
        ->call('linkContact')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Paula Manager', 'Property Manager, Billing Contact', 'Also a contact of:', 'Oak Street Owners']);

    expect(Contact::query()->where('first_name', 'Paula')->count())->toBe(1);
    expect(AccountContact::query()->where('contact_id', $paula->id)->count())->toBe(2);
    expect($second->contactLinks()->where('contact_id', $paula->id)->sole()->is_primary)->toBeFalse();
    // Her roles at the first customer are her roles there, not here.
    expect($first->contactLinks()->sole()->roleLabels())->toBe(['Property Manager']);
});

test('the search for an existing contact finds them by name, phone or email', function (string $search) {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $paula = Contact::factory()->named('Paula', 'Manager')->create([
        'phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'paula@example.com', 'email_normalized' => 'paula@example.com',
    ]);

    Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula)->create();
    Account::factory()->commercial('Unrelated')->withPrimaryContact(Contact::factory()->named('Someone', 'Else')->create())->create();

    $here = Account::factory()->commercial('Elm Street Owners')->create();

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $here])
        ->call('openLinkContact')
        ->set('contactSearch', $search)
        ->assertSee('Paula Manager')
        ->assertDontSee('Someone Else');
})->with(['manager paula', '201-3344', 'paula@EXAMPLE']);

test('only people the viewer already knows of can be found or linked', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $hiddenPerson = Contact::factory()->named('Hidden', 'Harriet')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'harriet@example.com', 'email_normalized' => 'harriet@example.com']);
    $archivedPerson = Contact::factory()->named('Archived', 'Arthur')->archived()->create();
    $alreadyHere = Contact::factory()->named('Already', 'Here')->create();

    customerAt($northside, Account::factory()->commercial('Northside Secret Holdings')->withPrimaryContact($hiddenPerson)->create());
    customerAt($lakeview, Account::factory()->commercial('Old Customer')->withContact($archivedPerson)->create());

    $mine = customerAt($lakeview, Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($alreadyHere)->create());

    $page = Livewire::actingAs($consultant)->test('pages::customers.show', ['account' => $mine])->call('openLinkContact');

    foreach (['Hidden Harriet', '5552013344', 'harriet@example.com', 'Archived', 'Already', '%', '__'] as $search) {
        $page->set('contactSearch', $search)
            ->assertDontSee('Hidden Harriet')
            ->assertDontSee('Northside Secret Holdings')
            ->assertDontSee('Archived Arthur')
            ->assertDontSeeHtml('data-test="contact-matches"');
    }

    // Naming the hidden person directly is refused exactly as if they did not exist.
    $page->call('chooseContact', $hiddenPerson->id)->assertNotFound();

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $mine])
        ->call('chooseContact', 999999)
        ->assertNotFound();

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $mine])
        ->set('contactRoles', ['other'])
        ->call('linkContact')
        ->assertNotFound();

    expect($mine->contactLinks()->count())->toBe(1);
});

test('the same person cannot be connected to a customer twice', function () {
    Location::factory()->create();

    $paula = Contact::factory()->create();
    $account = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($paula)->create();

    expect(fn () => app(LinkContactToAccount::class)->handle($account, $paula, [ContactRole::Other]))->toThrow(ValidationException::class);

    expect($account->contactLinks()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Primary contact
|--------------------------------------------------------------------------
*/

test('the primary contact can be changed, and a household is renamed with it', function () {
    $lakeview = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $maria = Contact::factory()->named('Maria', 'Lopez')->create();
    $adam = Contact::factory()->named('Adam', 'Lopez')->create();

    $household = customerAt($lakeview, Account::factory()->withPrimaryContact($maria)->withContact($adam, [ContactRole::SpousePartner])->create());
    $adamLink = $household->contactLinks()->where('contact_id', $adam->id)->sole();

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $household])
        ->call('makePrimary', $adamLink->id)
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Adam Lopez', 'Primary', 'Maria Lopez']);

    expect($household->fresh()->display_name)->toBe('Adam Lopez');
    expect($household->contactLinks()->where('is_primary', true)->sole()->contact_id)->toBe($adam->id);
    // Being primary is not a role: his role is unchanged.
    expect($adamLink->fresh()->roleLabels())->toBe(['Spouse / Partner']);
});

/*
|--------------------------------------------------------------------------
| Removing and restoring: a company-wide management action
|--------------------------------------------------------------------------
*/

test('owner and leadership with an all-locations grant can remove a contact from a customer and restore them', function (Role $role) {
    Location::factory()->create();

    $manager = User::factory()->withRoleEverywhere($role)->create();
    $paula = Contact::factory()->named('Paula', 'Manager')->create();

    $account = Account::factory()->commercial('Oak Street Owners')
        ->withPrimaryContact(Contact::factory()->named('Olive', 'Oak')->create())
        ->withContact($paula, [ContactRole::PropertyManager, ContactRole::BillingContact])
        ->create();
    // She is also a contact elsewhere, so removing her here does not archive the person.
    $other = Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($paula)->create();

    $link = $account->contactLinks()->where('contact_id', $paula->id)->sole();

    $page = Livewire::actingAs($manager)
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="remove-contact"')
        ->call('removeContact', $link->id)
        ->assertHasNoErrors()
        ->assertDontSee('Paula Manager');

    expect($link->fresh()->isArchived())->toBeTrue();
    expect($link->fresh()->archived_by)->toBe($manager->id);
    expect($paula->fresh()->isArchived())->toBeFalse();
    expect($other->contactLinks()->sole()->isArchived())->toBeFalse();

    $page->set('showRemoved', true)
        ->assertSeeInOrder(['Removed from this customer', 'Paula Manager', '(Property Manager, Billing Contact)'])
        ->call('restoreContact', $link->id)
        ->assertHasNoErrors();

    expect($link->fresh()->isArchived())->toBeFalse();
    expect($link->fresh()->roleLabels())->toBe(['Property Manager', 'Billing Contact']);
    expect(AccountContact::query()->where('account_id', $account->id)->count())->toBe(2);
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
]);

test('nobody without that all-locations grant can remove or restore a contact', function (Closure $user) {
    $lakeview = Location::factory()->create();

    $user = $user($lakeview);
    $account = customerAt($lakeview, Account::factory()->commercial('Oak Street Owners')
        ->withPrimaryContact(Contact::factory()->named('Olive', 'Oak')->create())
        ->withContact(Contact::factory()->named('Paula', 'Manager')->create())
        ->withContact(Contact::factory()->named('Removed', 'Earlier')->create())
        ->create());

    $link = $account->contactLinks()->whereHas('contact', fn ($contact) => $contact->where('first_name', 'Paula'))->sole();
    $removed = $account->contactLinks()->whereHas('contact', fn ($contact) => $contact->where('first_name', 'Removed'))->sole();

    AccountContact::query()->whereKey($removed->id)->update(['archived_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::customers.show', ['account' => $account])
        ->assertDontSeeHtml('data-test="remove-contact"')
        ->assertDontSeeHtml('data-test="show-removed"')
        // Asking to see removed people shows nothing to someone who cannot restore them.
        ->set('showRemoved', true)
        ->assertDontSee('Removed Earlier')
        ->call('removeContact', $link->id)
        ->assertForbidden();

    Livewire::actingAs($user)->test('pages::customers.show', ['account' => $account])->call('restoreContact', $removed->id)->assertForbidden();

    expect($link->fresh()->isArchived())->toBeFalse();
    expect($removed->fresh()->isArchived())->toBeTrue();
})->with([
    'leadership at one location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::Leadership, $lakeview)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'location manager everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::LocationManager)->create()],
    'call center agent everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('removing a person\'s last connection archives the person, and restoring brings them back', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $pat = Contact::factory()->named('Pat', 'Baker')->create();
    $account = Account::factory()->commercial('Bakery on Main')->withPrimaryContact($pat)->create();
    $link = $account->contactLinks()->sole();

    // A business may be left with no contact at all.
    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('removeContact', $link->id)
        ->assertHasNoErrors()
        ->assertSee('No contacts yet.');

    expect($pat->fresh()->isArchived())->toBeTrue();
    expect($link->fresh()->is_primary)->toBeFalse();
    expect($account->fresh()->display_name)->toBe('Bakery on Main');

    $page->set('showRemoved', true)->call('restoreContact', $link->id)->assertHasNoErrors()->assertSeeInOrder(['Pat Baker', 'Primary']);

    expect($pat->fresh()->isArchived())->toBeFalse();
    // With nobody else there, the restored contact is the primary contact again.
    expect($link->fresh()->is_primary)->toBeTrue();
});

test('a contact cannot be removed when the customer would be left in a state it may not be in', function (Closure $arrange, string $message) {
    $lakeview = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    [$account, $link] = $arrange($lakeview);

    Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('removeContact', $link->id)
        ->assertHasErrors('contact')
        ->assertSee($message);

    expect(fn () => app(RemoveContactFromAccount::class)->handle($link->fresh(), $owner))->toThrow(ValidationException::class);

    expect($link->fresh()->isArchived())->toBeFalse();
    expect($link->contact->fresh()->isArchived())->toBeFalse();
})->with([
    'the primary contact, while there are others' => [function () {
        $account = Account::factory()->commercial('Bakery on Main')->withPrimaryContact(Contact::factory()->named('Pat', 'Baker')->create())->withContact()->create();

        return [$account, $account->contactLinks()->where('is_primary', true)->sole()];
    }, 'Pat Baker is the primary contact. Make someone else primary first.'],
    'the last contact of a household' => [function () {
        $account = Account::factory()->withPrimaryContact()->create();

        return [$account, $account->contactLinks()->sole()];
    }, 'A household always has at least one contact.'],
    'the contact on an active lead' => [function (Location $lakeview) {
        $paula = Contact::factory()->named('Paula', 'Manager')->create();
        $account = Account::factory()->commercial('Bakery on Main')->withPrimaryContact()->withContact($paula)->create();

        Lead::factory()->at($lakeview)->from($account, $paula)->create();

        return [$account, $account->contactLinks()->where('contact_id', $paula->id)->sole()];
    }, 'Paula Manager is the contact on 1 active lead for this customer'],
]);
