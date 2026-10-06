<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\AddContactToAccount;
use App\Actions\Customers\ArchiveProperty;
use App\Actions\Customers\CreateAccount;
use App\Actions\Customers\LinkContactToAccount;
use App\Actions\Customers\MakePrimaryContact;
use App\Actions\Customers\RemoveContactFromAccount;
use App\Actions\Customers\RestoreContactToAccount;
use App\Actions\Customers\SaveProperty;
use App\Actions\Customers\UpdateAccountContact;
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
use App\Support\Addresses\StructuredAddress;
use App\Support\Customers\ContactDetails;
use Livewire\Livewire;

/**
 * The one audit event of a kind, for looking at what it recorded.
 */
function customerEvent(AuditAction $action): AuditEvent
{
    return AuditEvent::query()->where('action', $action)->sole();
}

function typedAddress(string $street = '14 Oak Street', string $zip = '30301'): StructuredAddress
{
    return StructuredAddress::manual($street, null, 'Lakeview', 'GA', $zip);
}

beforeEach(function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

test('creating a household records the customer, the person, the connection and the primary contact', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::customers.create')
        ->set('contactFirstName', 'Maria')
        ->set('contactLastName', 'Lopez')
        ->set('contactPhone', '(555) 201-3344')
        ->set('contactRoles', ['owner', 'billing_contact'])
        ->call('save')
        ->assertHasNoErrors();

    $account = Account::query()->sole();
    $contact = Contact::query()->sole();

    expect(AuditEvent::query()->count())->toBe(4);

    $created = customerEvent(AuditAction::AccountCreated);

    expect($created->actor->is($this->owner))->toBeTrue();
    expect($created->subject->is($account))->toBeTrue();
    // A customer has no location, so its events have none.
    expect($created->location)->toBeNull();
    expect($created->after()['type'])->toBe('residential');

    $person = customerEvent(AuditAction::ContactCreated);

    expect($person->subject->is($contact))->toBeTrue();
    expect($person->after()['first_name'])->toBe('Maria');
    expect($person->after()['phone'])->toBe('(555) 201-3344');

    $connected = customerEvent(AuditAction::ContactConnected);

    expect($connected->subject->is($account))->toBeTrue();
    expect($connected->after()['contact'])->toBe('Maria Lopez');
    expect($connected->after()['roles'])->toBe(['owner', 'billing_contact']);

    $primary = customerEvent(AuditAction::PrimaryContactChanged);

    expect($primary->subject->is($account))->toBeTrue();
    expect($primary->before()['primary_contact'])->toBeNull();
    expect($primary->after()['primary_contact'])->toBe('Maria Lopez');
    expect($primary->after()['customer_name'])->toBe('Maria Lopez');
});

test('creating a business with no contact records only the customer', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::customers.create')
        ->set('type', 'commercial')
        ->set('businessName', 'Bakery on Main')
        ->call('save')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::AccountCreated);
    expect($event->after()['business_name'])->toBe('Bakery on Main');
});

test('correcting a contact records only what changed, about the person and about the connection separately', function () {
    $maria = Contact::factory()->named('Maria', 'Lopes')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344', 'email' => 'maria@example.com', 'email_normalized' => 'maria@example.com']);
    $account = Account::factory()->withPrimaryContact($maria, [ContactRole::Owner])->create();
    $link = $account->contactLinks()->sole();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editContact', $link->id)
        ->set('contactLastName', 'Lopez')
        ->set('contactRoles', ['owner', 'billing_contact'])
        ->call('saveContact')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(2);

    $details = customerEvent(AuditAction::ContactDetailsEdited);

    expect($details->actor->is($this->owner))->toBeTrue();
    expect($details->subject->is($maria))->toBeTrue();
    expect($details->before())->toBe(['last_name' => 'Lopes']);
    expect($details->after())->toBe(['last_name' => 'Lopez']);

    $roles = customerEvent(AuditAction::ContactRolesChanged);

    expect($roles->subject->is($account))->toBeTrue();
    expect($roles->before()['roles'])->toBe(['owner']);
    expect($roles->after()['roles'])->toBe(['billing_contact', 'owner']);
    expect($roles->after()['contact'])->toBe('Maria Lopez');
});

test('saving a contact unchanged records nothing', function () {
    $account = Account::factory()->withPrimaryContact(null, [ContactRole::Owner, ContactRole::BillingContact])->create();
    $link = $account->contactLinks()->sole();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editContact', $link->id)
        ->call('saveContact')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('linking, changing the primary contact, removing and restoring are each recorded', function () {
    $paula = Contact::factory()->named('Paula', 'Manager')->create();
    $olive = Contact::factory()->named('Olive', 'Oak')->create();

    Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($paula)->create();
    $account = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($olive)->create();

    $page = Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('openLinkContact')
        ->call('chooseContact', $paula->id)
        ->set('contactRoles', ['property_manager'])
        ->call('linkContact')
        ->assertHasNoErrors();

    $link = $account->contactLinks()->where('contact_id', $paula->id)->sole();
    $oliveLink = $account->contactLinks()->where('contact_id', $olive->id)->sole();

    $connected = customerEvent(AuditAction::ContactConnected);

    expect($connected->subject->is($account))->toBeTrue();
    expect($connected->after()['contact_id'])->toBe($paula->id);
    expect($connected->after()['roles'])->toBe(['property_manager']);

    $page->call('makePrimary', $link->id)->assertHasNoErrors();

    $primary = customerEvent(AuditAction::PrimaryContactChanged);

    expect($primary->before()['primary_contact'])->toBe('Olive Oak');
    expect($primary->after()['primary_contact'])->toBe('Paula Manager');
    // A business keeps its name whoever is primary.
    expect($primary->after()['customer_name'])->toBe('Oak Street Owners');

    $page->call('removeContact', $oliveLink->id)->assertHasNoErrors();

    $removed = customerEvent(AuditAction::ContactDisconnected);

    expect($removed->actor->is($this->owner))->toBeTrue();
    expect($removed->before()['contact'])->toBe('Olive Oak');
    // It was her only connection, so the person was archived with it.
    expect($removed->after())->toBe(['contact_archived' => true]);

    $page->set('showRemoved', true)->call('restoreContact', $oliveLink->id)->assertHasNoErrors();

    $restored = customerEvent(AuditAction::ContactReconnected);

    expect($restored->subject->is($account))->toBeTrue();
    expect($restored->after()['contact'])->toBe('Olive Oak');
});

test('adding, correcting, archiving and restoring a property are each recorded', function () {
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $page = Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('addProperty')
        ->set('propertyLabel', 'Shop')
        ->set('addressLine1', '14 Oak Street')
        ->set('addressPostalCode', '30301')
        ->call('saveProperty')
        ->assertHasNoErrors();

    $property = Property::query()->sole();
    $created = customerEvent(AuditAction::PropertyCreated);

    expect($created->actor->is($this->owner))->toBeTrue();
    expect($created->subject->is($property))->toBeTrue();
    expect($created->location)->toBeNull();
    expect($created->after()['account_id'])->toBe($account->id);
    expect($created->after()['address'])->toBe('14 Oak Street, 30301');
    expect($created->after()['entry'])->toBe('manual');

    // Only the label is corrected, so only the label is recorded.
    $page->call('editProperty', $property->id)->set('propertyLabel', 'Main shop')->call('saveProperty')->assertHasNoErrors();

    $edited = customerEvent(AuditAction::PropertyEdited);

    expect($edited->before())->toBe(['label' => 'Shop']);
    expect($edited->after())->toBe(['label' => 'Main shop']);

    // Saving it unchanged records nothing more.
    $page->call('editProperty', $property->id)->call('saveProperty')->assertHasNoErrors();

    expect(AuditEvent::query()->where('action', AuditAction::PropertyEdited)->count())->toBe(1);

    $page->call('archiveProperty', $property->id)->assertHasNoErrors();

    expect(customerEvent(AuditAction::PropertyArchived)->before()['address'])->toBe('14 Oak Street, 30301');

    $page->set('showRemoved', true)->call('restoreProperty', $property->id)->assertHasNoErrors();

    expect(customerEvent(AuditAction::PropertyRestored)->subject->is($property))->toBeTrue();
});

test('correcting a property\'s address records the address before and after', function () {
    $account = Account::factory()->commercial('Bakery on Main')->create();
    $property = Property::factory()->of($account)->create(['label' => 'Shop', 'address' => '14 Oak Street, Lakeview, GA 30301', 'address_line1' => '14 Oak Street']);

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editProperty', $property->id)
        ->set('addressLine1', '16 Oak Street')
        ->call('saveProperty')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::PropertyEdited);
    expect($event->before())->toBe(['address' => '14 Oak Street, Lakeview, GA 30301']);
    expect($event->after())->toBe(['address' => '16 Oak Street, Lakeview, GA 30301']);
});

test('refused changes record nothing', function () {
    $lakeview = Location::query()->sole();
    $paula = Contact::factory()->named('Paula', 'Manager')->create();
    $account = Account::factory()->withPrimaryContact($paula)->create();
    $property = Property::factory()->of($account)->create();
    $link = $account->contactLinks()->sole();

    Lead::factory()->at($lakeview)->from($account, $paula, $property)->create();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        // The last contact of a household, and a property on an active lead.
        ->call('removeContact', $link->id)
        ->call('archiveProperty', $property->id)
        // A contact with no name, and a property with no street.
        ->call('addContact')->set('contactRoles', ['other'])->call('saveContact')
        ->call('addProperty')->call('saveProperty');

    expect(AuditEvent::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Impersonation
|--------------------------------------------------------------------------
*/

test('customer changes made while impersonating record the staff identity and the real owner', function () {
    $lakeview = Location::query()->sole();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $account = Account::factory()->withPrimaryContact()->create();

    Lead::factory()->at($lakeview)->from($account)->create();

    impersonate($this->owner, $consultant);

    $page = livewireSnapshot($this->get(route('customers.show', $account))->assertOk());

    sendLivewireAction($page, 'saveContact', updates: ['contactFirstName' => 'Adam', 'contactRoles' => ['spouse_partner']])->assertOk();
    sendLivewireAction($page, 'saveProperty', updates: ['propertyType' => 'residential', 'addressMode' => 'manual', 'addressLine1' => '14 Oak Street'])->assertOk();

    // As a Sales Consultant, removing a contact is refused: the Owner's reach is not added.
    sendLivewireAction($page, 'removeContact', [$account->contactLinks()->where('is_primary', true)->sole()->id])->assertForbidden();

    $events = AuditEvent::query()->whereIn('action', [AuditAction::ContactCreated, AuditAction::ContactConnected, AuditAction::PropertyCreated])->get();

    expect($events)->toHaveCount(3);

    foreach ($events as $event) {
        expect($event->actor->is($consultant))->toBeTrue();
        expect($event->impersonator->is($this->owner))->toBeTrue();
        expect($event->madeBy())->toBe('Olive Owner, impersonating Roseanne Smith');
    }

    // Screens show nothing of it.
    $this->get(route('customers.show', $account))->assertOk()->assertSee('Adam')->assertDontSee('Olive Owner, impersonating');
});

/*
|--------------------------------------------------------------------------
| A change and its audit event are saved together or not at all
|--------------------------------------------------------------------------
*/

test('no customer change is kept when its audit event cannot be recorded', function (Closure $change) {
    $paula = Contact::factory()->named('Paula', 'Manager')->create();
    $olive = Contact::factory()->named('Olive', 'Oak')->create();
    $spare = Contact::factory()->named('Spare', 'Person')->create();

    Account::factory()->commercial('Elm Street Owners')->withPrimaryContact($spare)->create();

    $account = Account::factory()->commercial('Oak Street Owners')->withPrimaryContact($olive)->withContact($paula, [ContactRole::PropertyManager])->create();
    $property = Property::factory()->of($account)->create(['label' => 'Shop']);
    $archived = Property::factory()->of($account)->archived()->create();
    $removed = Account::factory()->commercial('Third Customer')->withPrimaryContact()->withContact($spare)->create()->contactLinks()->where('contact_id', $spare->id)->sole();

    $removed->forceFill(['archived_at' => now()])->save();

    $snapshot = fn (): array => [
        Account::query()->orderBy('id')->get()->map->getAttributes()->all(),
        Contact::query()->orderBy('id')->get()->map->getAttributes()->all(),
        DB::table('account_contacts')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('account_contact_roles')->orderBy('id')->pluck('role', 'id')->all(),
        Property::query()->orderBy('id')->get()->map->getAttributes()->all(),
    ];

    $before = $snapshot();

    $this->mock(RecordAuditEvent::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => $change($account, $paula, $olive, $spare, $property, $archived, $removed, $this->owner))->toThrow(RuntimeException::class);

    expect($snapshot())->toBe($before);
})->with([
    'creating a customer' => [fn () => app(CreateAccount::class)->handle(AccountType::Commercial, 'New Business', ContactDetails::fromInput(['first_name' => 'New']), [ContactRole::Other])],
    'adding a contact' => [fn (Account $account) => app(AddContactToAccount::class)->handle($account, ContactDetails::fromInput(['first_name' => 'New']), [ContactRole::Other])],
    'linking a contact' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare) => app(LinkContactToAccount::class)->handle($account, $spare, [ContactRole::Other])],
    'correcting a contact' => [fn (Account $account, Contact $paula) => app(UpdateAccountContact::class)->handle($account->contactLinks()->where('contact_id', $paula->id)->sole(), ContactDetails::fromInput(['first_name' => 'Changed']), [ContactRole::PropertyManager])],
    'changing a contact\'s roles' => [fn (Account $account, Contact $paula) => app(UpdateAccountContact::class)->handle($account->contactLinks()->where('contact_id', $paula->id)->sole(), ContactDetails::ofContact($paula), [ContactRole::Tenant])],
    'changing the primary contact' => [fn (Account $account, Contact $paula) => app(MakePrimaryContact::class)->handle($account->contactLinks()->where('contact_id', $paula->id)->sole())],
    'removing a contact' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare, Property $property, Property $archived, $removed, User $owner) => app(RemoveContactFromAccount::class)->handle($account->contactLinks()->where('contact_id', $paula->id)->sole(), $owner)],
    'restoring a contact' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare, Property $property, Property $archived, $removed) => app(RestoreContactToAccount::class)->handle($removed)],
    'adding a property' => [fn (Account $account) => app(SaveProperty::class)->add($account, 'Office', PropertyType::Commercial, typedAddress('99 New Street'))],
    'correcting a property' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare, Property $property) => app(SaveProperty::class)->update($property, 'Renamed', PropertyType::Commercial, StructuredAddress::ofProperty($property))],
    'archiving a property' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare, Property $property, Property $archived, $removed, User $owner) => app(ArchiveProperty::class)->archive($property, $owner)],
    'restoring a property' => [fn (Account $account, Contact $paula, Contact $olive, Contact $spare, Property $property, Property $archived) => app(ArchiveProperty::class)->restore($archived)],
]);
