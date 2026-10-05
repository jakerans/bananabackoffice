<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Models\User;
use App\Support\Addresses\AddressLookup;
use App\Support\Addresses\MapboxAddressLookup;
use App\Support\Addresses\MapboxLookupFailed;
use App\Support\Addresses\UnavailableAddressLookup;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Switch address lookup on, as it is once a token has been entered. The token here is fictitious.
 */
function useAddressLookup(): void
{
    config(['services.mapbox.token' => 'test-token-not-real']);
}

/**
 * What Mapbox returns for one street address.
 *
 * @return array<string, mixed>
 */
function mapboxFeature(string $street = '14 Oak Street', string $city = 'Lakeview', string $state = 'GA', string $zip = '30301', string $id = 'mbx-oak-14'): array
{
    return [
        'type' => 'Feature',
        'properties' => [
            'mapbox_id' => $id,
            'feature_type' => 'address',
            'name' => $street,
            'full_address' => "{$street}, {$city}, Georgia {$zip}, United States",
            'coordinates' => ['longitude' => -84.3880, 'latitude' => 33.7490, 'accuracy' => 'rooftop'],
            'context' => [
                'address' => ['name' => $street, 'address_number' => '14', 'street_name' => 'Oak Street'],
                'postcode' => ['name' => $zip],
                'place' => ['name' => $city],
                'region' => ['name' => 'Georgia', 'region_code' => $state],
                'country' => ['name' => 'United States', 'country_code' => 'US'],
            ],
        ],
    ];
}

/**
 * Make Mapbox answer every request with the given addresses.
 *
 * @param  list<array<string, mixed>>  $features
 */
function fakeMapbox(array $features): void
{
    Http::fake(['api.mapbox.com/*' => Http::response(['type' => 'FeatureCollection', 'features' => $features])]);
}

/**
 * A new-lead form with everything but the address filled in.
 */
function leadFormFor(User $user): Testable
{
    return Livewire::actingAs($user)
        ->test('pages::leads.create')
        ->set('name', 'Maria Lopez')
        ->set('channelId', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->set('serviceIds', oneService())
        ->set('projectSizeId', projectSizeId());
}

/*
|--------------------------------------------------------------------------
| Lookup is a convenience: without it, or when it fails, the address is typed
|--------------------------------------------------------------------------
*/

test('without a provider set up, the form offers the address to be typed by hand and calls nobody', function () {
    Http::fake();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect(app(AddressLookup::class))->toBeInstanceOf(UnavailableAddressLookup::class);

    leadFormFor($agent)
        ->assertSet('addressMode', 'manual')
        ->assertSee('Street address')
        ->assertDontSee('Start typing the street address')
        ->set('addressSearch', '14 Oak Street')
        ->assertSet('addressSuggestions', []);

    Http::assertNothingSent();
});

test('an address typed by hand is stored in parts, with no coordinates invented for it', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressLine1', ' 9 New Build Way ')
        ->set('addressLine2', 'Lot 12')
        ->set('addressCity', 'Lakeview')
        ->set('addressState', 'GA')
        ->set('addressPostalCode', '30301')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->inquiry_address)->toBe('9 New Build Way, Lot 12, Lakeview, GA 30301');
    expect($lead->address_line1)->toBe('9 New Build Way');
    expect($lead->address_line2)->toBe('Lot 12');
    expect($lead->address_city)->toBe('Lakeview');
    expect($lead->address_state)->toBe('GA');
    expect($lead->address_postal_code)->toBe('30301');
    expect($lead->address_entry)->toBe('manual');
    expect($lead->address_latitude)->toBeNull();
    expect($lead->address_longitude)->toBeNull();
    expect($lead->address_provider)->toBeNull();
    expect($lead->address_provider_place_id)->toBeNull();
});

test('a lead can be saved with no address at all, or with only part of one', function (array $parts, ?string $expected) {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $form = leadFormFor($agent);

    foreach ($parts as $field => $value) {
        $form->set($field, $value);
    }

    $form->call('save')->assertHasNoErrors();

    expect(Lead::query()->sole()->inquiry_address)->toBe($expected);
})->with([
    'nothing' => [[], null],
    'only a neighborhood' => [['addressLine1' => 'somewhere near the lake'], 'somewhere near the lake'],
    'only a zip code' => [['addressPostalCode' => '30301'], '30301'],
]);

test('suggestions appear once enough has been typed, and are requested from the server with the token', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature(), mapboxFeature('14 Oakdale Road', 'Northside', 'GA', '30305', 'mbx-oakdale-14')]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    expect(app(AddressLookup::class))->toBeInstanceOf(MapboxAddressLookup::class);

    $form = leadFormFor($agent)
        ->assertSet('addressMode', 'search')
        ->assertSee('Start typing the street address');

    // Too little typed: nothing is asked of the provider.
    $form->set('addressSearch', '14 O')->assertSet('addressSuggestions', []);

    Http::assertNothingSent();

    $form->set('addressSearch', '14 Oak')
        ->assertSee('14 Oak Street, Lakeview, Georgia 30301, United States')
        ->assertSee('14 Oakdale Road, Northside, Georgia 30305, United States');

    Http::assertSent(function (Request $request) {
        return str_starts_with($request->url(), 'https://api.mapbox.com/search/geocode/v6/forward')
            && $request['q'] === '14 Oak'
            && $request['access_token'] === 'test-token-not-real'
            && $request['autocomplete'] === 'true'
            // Suggestions are temporary results: they are shown and never kept.
            && $request['permanent'] === 'false'
            && $request['country'] === 'us'
            && $request['types'] === 'address';
    });

    Http::assertSentCount(1);
});

test('the access token is never sent to the browser', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)->get(route('leads.create'))->assertOk()->assertDontSee('test-token-not-real');

    $form = leadFormFor($agent)->set('addressSearch', '14 Oak Street')->call('selectAddress', 0);

    expect($form->html())->not->toContain('test-token-not-real');
    expect(json_encode($form->snapshot))->not->toContain('test-token-not-real');
});

test('selecting a suggestion looks it up again as a permanent result and stores the structured address', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressSearch', '14 Oak Street')
        ->call('selectAddress', 0)
        ->assertSet('addressMode', 'selected')
        ->assertSee('14 Oak Street, Lakeview, Georgia 30301, United States')
        ->assertSee('Found by address lookup')
        ->call('save')
        ->assertHasNoErrors();

    // The address that is kept came from a request allowed to be kept.
    Http::assertSent(fn (Request $request) => $request['permanent'] === 'true'
        && $request['autocomplete'] === 'false'
        && $request['q'] === '14 Oak Street, Lakeview, Georgia 30301, United States'
        && (string) $request['limit'] === '1');

    $lead = Lead::query()->sole();

    expect($lead->inquiry_address)->toBe('14 Oak Street, Lakeview, Georgia 30301, United States');
    expect($lead->address_line1)->toBe('14 Oak Street');
    expect($lead->address_city)->toBe('Lakeview');
    expect($lead->address_state)->toBe('GA');
    expect($lead->address_postal_code)->toBe('30301');
    expect($lead->address_country)->toBe('US');
    expect((float) $lead->address_latitude)->toBe(33.749);
    expect((float) $lead->address_longitude)->toBe(-84.388);
    expect($lead->address_accuracy)->toBe('rooftop');
    expect($lead->address_provider)->toBe('mapbox');
    expect($lead->address_provider_place_id)->toBe('mbx-oak-14');
    expect($lead->address_entry)->toBe('lookup');
});

test('the coordinates saved are the provider\'s, whatever the browser sends back', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $form = leadFormFor($agent)->set('addressSearch', '14 Oak Street')->call('selectAddress', 0);

    expect(fn () => $form->set('selectedAddress', ['formatted' => 'Somewhere Else', 'latitude' => 1, 'longitude' => 1, 'entry' => 'lookup']))
        ->toThrow(Exception::class);
});

test('a provider failure never stops a lead being saved', function (Closure $failure) {
    useAddressLookup();
    Exceptions::fake();

    $failure();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    // Nothing is suggested, the failure is reported, and the address can be typed.
    leadFormFor($agent)
        ->set('addressSearch', '14 Oak Street')
        ->assertSet('addressSuggestions', [])
        ->assertSee('Type the address by hand')
        ->call('enterAddressManually')
        ->assertSet('addressMode', 'manual')
        ->assertSet('addressLine1', '14 Oak Street')
        ->set('addressPostalCode', '30301')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->inquiry_address)->toBe('14 Oak Street, 30301');

    Exceptions::assertReported(MapboxLookupFailed::class);
})->with([
    'the provider is down' => [fn () => Http::fake(['api.mapbox.com/*' => Http::response('Service Unavailable', 503)])],
    'the token is refused' => [fn () => Http::fake(['api.mapbox.com/*' => Http::response(['message' => 'Not Authorized'], 401)])],
    'the provider cannot be reached' => [fn () => Http::fake(['api.mapbox.com/*' => fn () => throw new ConnectionException('Could not resolve host for https://api.mapbox.com/?access_token=test-token-not-real')])],
    'the answer is not what was expected' => [fn () => Http::fake(['api.mapbox.com/*' => Http::response('<html>not json</html>', 200)])],
]);

test('a failure reported to the log never contains the access token', function () {
    useAddressLookup();
    Exceptions::fake();

    Http::fake(['api.mapbox.com/*' => fn () => throw new ConnectionException('cURL error for https://api.mapbox.com/?access_token=test-token-not-real')]);

    expect(app(AddressLookup::class)->suggest('14 Oak Street'))->toBe([]);

    Exceptions::assertReported(fn (MapboxLookupFailed $exception) => ! str_contains($exception->getMessage(), 'test-token-not-real'));
});

test('when a selected address cannot be confirmed the form switches to typing it, keeping what was chosen', function () {
    useAddressLookup();
    Exceptions::fake();

    Http::fakeSequence('api.mapbox.com/*')
        ->push(['features' => [mapboxFeature()]])
        ->push('Service Unavailable', 503);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressSearch', '14 Oak Street')
        ->call('selectAddress', 0)
        ->assertSet('addressMode', 'manual')
        ->assertSet('addressLine1', '14 Oak Street, Lakeview, Georgia 30301, United States')
        ->assertSee('That address could not be confirmed, so type it here. The lead can still be saved.')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->address_entry)->toBe('manual');
    expect($lead->address_latitude)->toBeNull();
});

test('a suggestion that was not offered cannot be selected', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressSearch', '14 Oak Street')
        ->call('selectAddress', 7)
        ->assertSet('addressMode', 'search')
        ->assertSet('selectedAddress', null);
});

test('a selected address can be changed back to searching or to typing', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressSearch', '14 Oak Street')
        ->call('selectAddress', 0)
        ->call('searchForAddress')
        ->assertSet('addressMode', 'search')
        ->assertSet('selectedAddress', null)
        ->assertSet('addressSearch', '')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->inquiry_address)->toBeNull();
});

test('lookups are limited per person so typing cannot run up unlimited requests', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature()]);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $form = leadFormFor($agent);

    foreach (range(1, 65) as $attempt) {
        $form->set('addressSearch', "14 Oak Street {$attempt}");
    }

    Http::assertSentCount(60);

    // Past the limit nothing is suggested, and the address can still be typed.
    $form->assertSet('addressSuggestions', [])->assertSee('Type the address by hand');
});

/*
|--------------------------------------------------------------------------
| Location suggestion: from the service area's ZIP codes, never by distance
|--------------------------------------------------------------------------
*/

test('a looked-up address in one location\'s service area selects that location', function () {
    useAddressLookup();
    fakeMapbox([mapboxFeature(zip: '30301')]);

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->assertSet('locationId', '')
        ->set('addressSearch', '14 Oak Street')
        ->call('selectAddress', 0)
        ->assertSet('locationId', (string) $lakeview->id)
        ->assertSee('Lakeview serves 30301. Change it if that is not right.')
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->location->is($lakeview))->toBeTrue();
    expect(AuditEvent::query()->sole()->after()['suggested_location'])->toBe('Lakeview');
    expect(AuditEvent::query()->sole()->after()['postal_code'])->toBe('30301');
});

test('a zip code typed by hand suggests a location in the same way', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressLine1', '9 New Build Way')
        ->set('addressPostalCode', '30301-4420')
        ->assertSet('locationId', (string) $lakeview->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->location->is($lakeview))->toBeTrue();
});

test('with no service area for the zip code nothing is guessed and the lead stays unrouted', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressLine1', '5 Far Away Lane')
        ->set('addressPostalCode', '99999')
        ->assertSet('locationId', '')
        ->assertSee('No location&#039;s service area includes 99999, so none is suggested.', escape: false)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->isUnrouted())->toBeTrue();
    expect(AuditEvent::query()->sole()->after()['suggested_location'])->toBeNull();
});

test('a zip code claimed by two locations suggests neither', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressPostalCode', '30301')
        ->assertSet('locationId', '')
        ->assertSet('suggestedLocationId', null);
});

test('a deactivated location is never suggested', function () {
    $closed = Location::factory()->inactive()->create(['name' => 'Closed Branch']);

    ServiceAreaPostalCode::factory()->for($closed)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressPostalCode', '30301')
        ->assertSet('locationId', '')
        ->assertSet('suggestedLocationId', null);
});

test('the suggested location can be overridden by someone allowed to, and the choice is respected', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressPostalCode', '30301')
        ->assertSet('locationId', (string) $lakeview->id)
        // The person chooses another location by hand...
        ->set('locationId', (string) $northside->id)
        ->assertSee('30301 is normally served by Lakeview.')
        // ...and changing the address afterwards does not undo their choice.
        ->set('addressPostalCode', '30305')
        ->set('addressPostalCode', '30301')
        ->assertSet('locationId', (string) $northside->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->location->is($northside))->toBeTrue();
    expect(AuditEvent::query()->sole()->after()['suggested_location'])->toBe('Lakeview');
    expect(AuditEvent::query()->sole()->after()['location'])->toBe('Northside');
});

test('a suggestion follows the address until the person chooses a location themselves', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    leadFormFor($agent)
        ->set('addressPostalCode', '30301')
        ->assertSet('locationId', (string) $lakeview->id)
        ->set('addressPostalCode', '30305')
        ->assertSet('locationId', (string) $northside->id)
        // An address with no service area takes the suggestion back off.
        ->set('addressPostalCode', '99999')
        ->assertSet('locationId', '');
});

test('a suggestion never moves a lead to a location the person may not capture for', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    leadFormFor($consultant)
        ->assertSet('locationId', (string) $lakeview->id)
        ->set('addressLine1', '5 Other Side Road')
        ->set('addressPostalCode', '30305')
        // Their own location stays selected; they are only told where it is normally served.
        ->assertSet('locationId', (string) $lakeview->id)
        ->assertSee('30305 is normally served by Northside.')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->location->is($lakeview))->toBeTrue();
});

test('a tampered location is still refused when an address suggested it', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    leadFormFor($consultant)
        ->set('addressPostalCode', '30305')
        ->set('locationId', (string) $northside->id)
        ->call('save')
        ->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The address on an existing lead
|--------------------------------------------------------------------------
*/

test('the lead page shows the address and how it was entered', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $typed = Lead::factory()->at($lakeview)->create(['inquiry_address' => '9 New Build Way, 30301', 'address_line1' => '9 New Build Way', 'address_postal_code' => '30301', 'address_entry' => 'manual']);
    $found = Lead::factory()->at($lakeview)->create(['inquiry_address' => '14 Oak Street, Lakeview, Georgia 30301', 'address_entry' => 'lookup', 'address_latitude' => 33.749, 'address_longitude' => -84.388]);

    $this->actingAs($consultant)->get(route('leads.show', $typed))
        ->assertSee('9 New Build Way, 30301')
        ->assertSee('Typed by hand, so it has no map position');

    $this->actingAs($consultant)->get(route('leads.show', $found))
        ->assertSee('14 Oak Street, Lakeview, Georgia 30301')
        ->assertSee('Found by address lookup');
});

test('correcting a lead can change its address, and an unchanged address is left exactly as it was', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $lead = Lead::factory()->at($lakeview)->create([
        'inquiry_name' => 'Maria Lopes',
        'inquiry_address' => '14 Oak Street, Lakeview, Georgia 30301, United States',
        'address_line1' => '14 Oak Street',
        'address_city' => 'Lakeview',
        'address_state' => 'GA',
        'address_postal_code' => '30301',
        'address_country' => 'US',
        'address_latitude' => 33.749,
        'address_longitude' => -84.388,
        'address_accuracy' => 'rooftop',
        'address_provider' => 'mapbox',
        'address_provider_place_id' => 'mbx-oak-14',
        'address_entry' => 'lookup',
    ]);

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    // An unrelated correction keeps the looked-up address, coordinates included.
    $page->call('edit')
        ->assertSet('addressMode', 'selected')
        ->set('name', 'Maria Lopez')
        ->call('update')
        ->assertHasNoErrors();

    $lead->refresh();

    expect($lead->address_provider_place_id)->toBe('mbx-oak-14');
    expect((float) $lead->address_latitude)->toBe(33.749);
    expect(AuditEvent::query()->sole()->before())->toBe(['name' => 'Maria Lopes']);

    // Replacing it by hand clears the coordinates: they belonged to the other address.
    $page->call('edit')
        ->call('enterAddressManually')
        ->set('addressLine1', '9 New Build Way')
        ->set('addressCity', '')
        ->set('addressPostalCode', '30305')
        ->call('update')
        ->assertHasNoErrors();

    $lead->refresh();

    expect($lead->inquiry_address)->toBe('9 New Build Way, 30305');
    expect($lead->address_entry)->toBe('manual');
    expect($lead->address_latitude)->toBeNull();
    expect($lead->address_provider_place_id)->toBeNull();

    $event = AuditEvent::query()->latest('id')->firstOrFail();

    expect($event->before())->toBe(['address' => '14 Oak Street, Lakeview, Georgia 30301, United States']);
    expect($event->after())->toBe(['address' => '9 New Build Way, 30305']);
});

test('correcting a lead\'s address does not move it to another location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('addressLine1', '5 Other Side Road')
        ->set('addressPostalCode', '30305')
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();
});
