<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Customers\ArchiveAccount;
use App\Actions\Customers\UpdateAccount;
use App\Enums\AccountType;
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
use App\Support\Customers\LeadCustomerMatcher;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
});

/*
|--------------------------------------------------------------------------
| Correcting a customer's name and kind
|--------------------------------------------------------------------------
*/

test('a business\'s name can be corrected', function () {
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();
    $account = Account::factory()->commercial('Bakery on Mian')->create();

    Lead::factory()->at($this->lakeview)->from($account)->create();

    Livewire::actingAs($consultant)
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="edit-customer"')
        ->call('editAccount')
        ->assertSet('accountType', 'commercial')
        ->assertSet('accountBusinessName', 'Bakery on Mian')
        ->set('accountBusinessName', '  Bakery on Main, LLC ')
        ->call('saveAccount')
        ->assertHasNoErrors()
        ->assertSee('Bakery on Main, LLC');

    $corrected = $account->fresh();

    expect($corrected->business_name)->toBe('Bakery on Main, LLC');
    expect($corrected->display_name)->toBe('Bakery on Main, LLC');
    expect($corrected->business_name_normalized)->toBe('bakery on main');

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::AccountEdited);
    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->subject->is($account))->toBeTrue();
    expect($event->before())->toBe(['business_name' => 'Bakery on Mian', 'customer_name' => 'Bakery on Mian']);
    expect($event->after())->toBe(['business_name' => 'Bakery on Main, LLC', 'customer_name' => 'Bakery on Main, LLC']);
});

test('a household can become a business, which needs a business name and keeps everything else', function () {
    $maria = Contact::factory()->named('Maria', 'Lopez')->create();
    $account = Account::factory()->withPrimaryContact($maria, [ContactRole::Owner])->withContact(null, [ContactRole::SpousePartner])->create();
    $property = Property::factory()->of($account)->create();
    $lead = Lead::factory()->at($this->lakeview)->from($account, $maria, $property)->create();

    $page = Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editAccount')
        ->set('accountType', 'commercial')
        ->call('saveAccount')
        ->assertHasErrors('accountBusinessName');

    expect($account->fresh()->type)->toBe(AccountType::Residential);

    $page->set('accountBusinessName', 'Lopez Rentals')->call('saveAccount')->assertHasNoErrors();

    $changed = $account->fresh();

    expect($changed->type)->toBe(AccountType::Commercial);
    expect($changed->display_name)->toBe('Lopez Rentals');
    // Nothing else was touched: the same people with the same roles, the same property, the same lead.
    expect($changed->contactLinks()->count())->toBe(2);
    expect($changed->primaryContact()->is($maria))->toBeTrue();
    expect($changed->contactLinks()->where('is_primary', true)->sole()->roleLabels())->toBe(['Owner']);
    expect($property->fresh()->account->is($account))->toBeTrue();
    expect($lead->fresh()->account->is($account))->toBeTrue();
    expect($lead->fresh()->contact->is($maria))->toBeTrue();

    $event = AuditEvent::query()->sole();

    expect($event->before()['type'])->toBe('residential');
    expect($event->after()['type'])->toBe('commercial');
    expect($event->before()['customer_name'])->toBe('Maria Lopez');
    expect($event->after()['customer_name'])->toBe('Lopez Rentals');
    expect($event->after()['business_name'])->toBe('Lopez Rentals');
});

test('a business can become a household only once it has a primary contact, and is then named after them', function () {
    $account = Account::factory()->commercial('Lopez Rentals')->create();
    Property::factory()->of($account)->create();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('editAccount')
        ->set('accountType', 'residential')
        ->call('saveAccount')
        ->assertHasErrors('accountType')
        ->assertSee('A household is named after its primary contact.');

    expect($account->fresh()->type)->toBe(AccountType::Commercial);
    expect(AuditEvent::query()->count())->toBe(0);

    $withContact = Account::factory()->commercial('Lopez Rentals')->withPrimaryContact(Contact::factory()->named('Maria', 'Lopez')->create())->create();
    $property = Property::factory()->of($withContact)->create();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $withContact])
        ->call('editAccount')
        ->set('accountType', 'residential')
        ->call('saveAccount')
        ->assertHasNoErrors();

    $changed = $withContact->fresh();

    expect($changed->type)->toBe(AccountType::Residential);
    expect($changed->display_name)->toBe('Maria Lopez');
    expect($changed->business_name)->toBeNull();
    expect($changed->contactLinks()->count())->toBe(1);
    expect($property->fresh()->account->is($withContact))->toBeTrue();

    // The business name it had is kept in the history.
    expect(AuditEvent::query()->sole()->before()['business_name'])->toBe('Lopez Rentals');
});

test('saving a customer unchanged records nothing', function () {
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Livewire::actingAs($this->owner)->test('pages::customers.show', ['account' => $account])->call('editAccount')->call('saveAccount')->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('only someone who may change the customer can edit it', function () {
    $finance = User::factory()->withRoleEverywhere(Role::Finance)->create();
    $elsewhere = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Lead::factory()->at($this->lakeview)->from($account)->create();

    $this->actingAs($finance)->get(route('customers.show', $account))->assertOk()->assertDontSee('data-test="edit-customer"', false);

    foreach (['editAccount', 'saveAccount'] as $action) {
        Livewire::actingAs($finance)->test('pages::customers.show', ['account' => $account])->set('accountType', 'commercial')->set('accountBusinessName', 'Changed')->call($action)->assertForbidden();
    }

    Livewire::actingAs($elsewhere)->test('pages::customers.show', ['account' => $account])->assertForbidden();

    expect($account->fresh()->business_name)->toBe('Bakery on Main');
});

test('a customer change is not kept when its audit event cannot be recorded', function () {
    $account = Account::factory()->commercial('Bakery on Main')->create();

    $this->mock(RecordAuditEvent::class)->shouldReceive('handle')->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(UpdateAccount::class)->handle($account, AccountType::Commercial, 'Renamed'))->toThrow(RuntimeException::class);
    expect(fn () => app(ArchiveAccount::class)->archive($account->fresh(), $this->owner))->toThrow(RuntimeException::class);

    expect($account->fresh()->business_name)->toBe('Bakery on Main');
    expect($account->fresh()->isArchived())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Archiving and restoring a customer: a company-wide management action
|--------------------------------------------------------------------------
*/

test('owner and leadership with an all-locations grant can archive and restore a customer', function (Role $role) {
    $this->freezeSecond();

    $manager = User::factory()->withRoleEverywhere($role)->create();
    $account = Account::factory()->commercial('Closed Business')->withPrimaryContact()->create();
    $property = Property::factory()->of($account)->create();

    $page = Livewire::actingAs($manager)
        ->test('pages::customers.show', ['account' => $account])
        ->assertSeeHtml('data-test="archive-customer"')
        ->assertDontSeeHtml('data-test="restore-customer"')
        ->call('archiveAccount')
        ->assertHasNoErrors()
        ->assertSee('This customer is archived')
        ->assertSeeHtml('data-test="restore-customer"')
        // An archived customer is not changed until it is restored.
        ->assertDontSeeHtml('data-test="archive-customer"')
        ->assertDontSeeHtml('data-test="add-contact"')
        ->assertDontSeeHtml('data-test="new-lead-for-customer"');

    $archived = $account->fresh();

    expect($archived->isArchived())->toBeTrue();
    expect($archived->archived_at->equalTo(now()))->toBeTrue();
    expect($archived->archived_by)->toBe($manager->id);
    // Its people and places are kept exactly as they were.
    expect($archived->contactLinks()->notArchived()->count())->toBe(1);
    expect($property->fresh()->isArchived())->toBeFalse();

    $page->call('restoreAccount')->assertHasNoErrors()->assertDontSee('This customer is archived')->assertSeeHtml('data-test="add-contact"');

    expect($account->fresh()->isArchived())->toBeFalse();
    expect($account->fresh()->archived_by)->toBeNull();

    expect(AuditEvent::query()->where('action', AuditAction::AccountArchived)->sole()->actor->is($manager))->toBeTrue();
    expect(AuditEvent::query()->where('action', AuditAction::AccountRestored)->sole()->subject->is($account))->toBeTrue();
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
]);

test('nobody without that all-locations grant can archive or restore a customer', function (Closure $user) {
    $user = $user($this->lakeview);

    $account = Account::factory()->commercial('Bakery on Main')->create();
    $archived = Account::factory()->commercial('Closed Business')->archived()->create();

    Lead::factory()->at($this->lakeview)->from($account)->create();
    Lead::factory()->at($this->lakeview)->from($archived)->create();

    Livewire::actingAs($user)
        ->test('pages::customers.show', ['account' => $account])
        ->assertDontSeeHtml('data-test="archive-customer"')
        ->call('archiveAccount')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::customers.show', ['account' => $archived])
        ->assertDontSeeHtml('data-test="restore-customer"')
        ->call('restoreAccount')
        ->assertForbidden();

    expect($account->fresh()->isArchived())->toBeFalse();
    expect($archived->fresh()->isArchived())->toBeTrue();
})->with([
    'leadership at one location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::Leadership, $lakeview)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'location manager everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::LocationManager)->create()],
    'call center agent everywhere' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'sales consultant' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('a customer with an active lead cannot be archived', function () {
    $northside = Location::factory()->create();
    $account = Account::factory()->commercial('Bakery on Main')->create();

    Lead::factory()->at($this->lakeview)->from($account)->create();
    Lead::factory()->at($northside)->from($account)->working()->create();
    Lead::factory()->from($account)->create();

    Livewire::actingAs($this->owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('archiveAccount')
        ->assertHasErrors('account')
        ->assertSee('This customer has 3 active leads, so cannot be archived yet.');

    expect(fn () => app(ArchiveAccount::class)->archive($account->fresh(), $this->owner))->toThrow(ValidationException::class);

    expect($account->fresh()->isArchived())->toBeFalse();
    expect(AuditEvent::query()->count())->toBe(0);
});

test('an archived customer is hidden from the list, from suggestions and from choices until it is restored', function () {
    $contact = Contact::factory()->named('Pat', 'Baker')->create(['phone' => '5552013344', 'phone_normalized' => '+15552013344']);
    $account = Account::factory()->commercial('Closed Business')->withPrimaryContact($contact)->create();

    app(ArchiveAccount::class)->archive($account, $this->owner);

    // Not in the list, unless archived customers are asked for.
    Livewire::actingAs($this->owner)
        ->withQueryParams(['location' => 'any'])
        ->test('pages::customers.index')
        ->assertDontSee('Closed Business')
        ->set('archived', true)
        ->assertSee('Closed Business');

    // Not suggested for a lead with the same phone, and not found by searching from a lead.
    $lead = Lead::factory()->at($this->lakeview)->create(['inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);

    expect(app(LeadCustomerMatcher::class)->visible($lead, $this->owner))->toBe([]);

    Livewire::actingAs($this->owner)
        ->test('pages::leads.customer', ['lead' => $lead])
        ->set('customerSearch', 'Closed')
        ->assertSee('No customer you can see matches that.')
        ->call('chooseCustomer', 'account:'.$account->id)
        ->assertSet('customerChoice', '');

    // Its page is still there for those who can see it, saying what it is.
    $this->actingAs($this->owner)->get(route('customers.show', $account))->assertOk()->assertSee('This customer is archived');

    app(ArchiveAccount::class)->restore($account->fresh());

    expect(app(LeadCustomerMatcher::class)->visible($lead, $this->owner))->toHaveCount(1);
});
