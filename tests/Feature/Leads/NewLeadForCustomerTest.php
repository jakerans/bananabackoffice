<?php

use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\Property;
use App\Models\ServiceAreaPostalCode;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * The new lead form, opened from a customer's page.
 */
function newLeadFormFor(User $user, Account $account): Testable
{
    return Livewire::actingAs($user)->withQueryParams(['customer' => $account->id])->test('pages::leads.create');
}

beforeEach(function () {
    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->consultant = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create();

    $this->maria = Contact::factory()->named('Maria', 'Lopez')->create(['phone' => '(555) 201-3344', 'phone_normalized' => '+15552013344', 'email' => 'maria@example.com', 'email_normalized' => 'maria@example.com']);
    $this->adam = Contact::factory()->named('Adam', 'Lopez')->create(['phone' => '(555) 777-8899', 'phone_normalized' => '+15557778899', 'email' => null, 'email_normalized' => null]);

    $this->account = Account::factory()->withPrimaryContact($this->maria)->withContact($this->adam, [ContactRole::SpousePartner])->create();
    $this->home = Property::factory()->of($this->account)->create(['label' => 'Home', 'address' => '14 Oak Street, Lakeview, GA 30301', 'address_line1' => '14 Oak Street', 'address_postal_code' => '30301']);

    // The earlier lead that lets this consultant see the customer.
    Lead::factory()->at($this->lakeview)->from($this->account)->create();
});

test('a customer\'s page offers a new lead, which starts from what is already known', function () {
    $this->actingAs($this->consultant)
        ->get(route('customers.show', $this->account))
        ->assertOk()
        ->assertSee('href="'.route('leads.create', ['customer' => $this->account->id]).'"', false);

    newLeadFormFor($this->consultant, $this->account)
        ->assertSee('For Maria Lopez')
        // The primary contact and the one property are chosen, and the inquiry starts from them.
        ->assertSet('customerContactId', $this->maria->id)
        ->assertSet('customerPropertyId', $this->home->id)
        ->assertSet('name', 'Maria Lopez')
        ->assertSet('phone', '(555) 201-3344')
        ->assertSet('email', 'maria@example.com')
        ->assertSet('addressMode', 'manual')
        ->assertSet('addressLine1', '14 Oak Street')
        ->assertSet('addressPostalCode', '30301');
});

test('the lead is recorded as usual and is linked to the customer, person and property as it is saved', function () {
    $before = Lead::query()->count();

    newLeadFormFor($this->consultant, $this->account)
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->set('message', 'Wants the fence done this time.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe($before + 1);

    $lead = Lead::query()->latest('id')->first();

    expect($lead->account->is($this->account))->toBeTrue();
    expect($lead->contact->is($this->maria))->toBeTrue();
    expect($lead->property->is($this->home))->toBeTrue();
    // The inquiry is still its own record of what was received.
    expect($lead->inquiry_name)->toBe('Maria Lopez');
    expect($lead->inquiry_phone_normalized)->toBe('+15552013344');
    expect($lead->inquiry_address)->toBe('14 Oak Street, Lakeview, GA 30301');
    expect($lead->inquiry_message)->toBe('Wants the fence done this time.');
    expect($lead->location->is($this->lakeview))->toBeTrue();

    $event = AuditEvent::query()->where('action', AuditAction::LeadReceived)->sole();

    expect($event->subject->is($lead))->toBeTrue();
    expect($event->after()['customer'])->toBe('Maria Lopez');

    Http::assertNothingSent();
});

test('choosing another contact starts the inquiry from them, and it can still be typed over', function () {
    newLeadFormFor($this->consultant, $this->account)
        ->set('customerContactId', $this->adam->id)
        ->assertSet('name', 'Adam Lopez')
        ->assertSet('phone', '(555) 777-8899')
        ->assertSet('email', '')
        // This time he called from a different number.
        ->set('phone', '555-000-1111')
        ->set('customerPropertyId', null)
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->latest('id')->first();

    expect($lead->contact->is($this->adam))->toBeTrue();
    expect($lead->property_id)->toBeNull();
    expect($lead->inquiry_phone)->toBe('555-000-1111');
    // The contact's own number is not changed by what was typed on the lead.
    expect($this->adam->fresh()->phone)->toBe('(555) 777-8899');
});

test('the property\'s address suggests the location that serves it, like any other address', function () {
    $northside = Location::factory()->create(['name' => 'Northside']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30301']);

    newLeadFormFor($agent, $this->account)
        ->assertSet('customerPropertyId', $this->home->id)
        ->assertSet('locationId', (string) $northside->id);

    // The property itself still has no location.
    expect(array_keys($this->home->fresh()->getAttributes()))->not->toContain('location_id');
});

test('a lead cannot be started for a customer the person cannot see', function (Closure $account) {
    $northside = Location::factory()->create();
    $hidden = $account($northside);

    Livewire::actingAs($this->consultant)->withQueryParams(['customer' => $hidden->id])->test('pages::leads.create')->assertForbidden();

    $this->actingAs($this->consultant)->get(route('leads.create', ['customer' => $hidden->id]))->assertForbidden();
})->with([
    'a customer at another location' => [function (Location $northside) {
        $hidden = Account::factory()->withPrimaryContact()->create();

        Lead::factory()->at($northside)->from($hidden)->create();

        return $hidden;
    }],
    'a customer with no lead' => [fn () => Account::factory()->commercial('No Lead Yet')->create()],
    'an archived customer' => [fn () => Account::factory()->commercial('Archived')->archived()->create()],
]);

test('a customer that does not exist is refused the same way', function () {
    Livewire::actingAs($this->consultant)->withQueryParams(['customer' => 999999])->test('pages::leads.create')->assertForbidden();
});

test('a contact or property of another customer cannot be attached through the form', function () {
    $other = Account::factory()->commercial('Other Business')->withPrimaryContact()->create();
    $otherProperty = Property::factory()->of($other)->create();

    Lead::factory()->at($this->lakeview)->from($other)->create();

    newLeadFormFor($this->consultant, $this->account)
        ->set('customerContactId', $other->primaryContact()->id)
        // Someone who is not this customer's contact is simply not chosen.
        ->assertSet('customerContactId', null)
        ->set('customerPropertyId', $otherProperty->id)
        ->assertSet('customerPropertyId', null)
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->latest('id')->first();

    expect($lead->account->is($this->account))->toBeTrue();
    expect($lead->contact_id)->toBeNull();
    expect($lead->property_id)->toBeNull();
});

test('the form refuses to save for a customer that went out of sight while it was open', function () {
    $northside = Location::factory()->create();

    $page = newLeadFormFor($this->consultant, $this->account)
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id);

    Lead::query()->update(['location_id' => $northside->id]);

    $before = Lead::query()->count();

    $page->call('save')->assertForbidden();

    expect(Lead::query()->count())->toBe($before);
});

test('the ordinary new lead form is unchanged when it is not opened from a customer', function () {
    Livewire::actingAs($this->consultant)
        ->test('pages::leads.create')
        ->assertDontSeeHtml('data-test="lead-for-customer"')
        ->assertSet('customerId', null)
        ->assertSet('name', '')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Walk In')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->latest('id')->first()->account_id)->toBeNull();
});

test('the customer cannot be set on the form from the browser', function () {
    $page = Livewire::actingAs($this->consultant)->test('pages::leads.create');

    expect(fn () => $page->set('customerId', $this->account->id))->toThrow(Exception::class);
});
