<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Locations\UpdateServiceAreaPostalCodes;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Models\User;
use App\Support\Addresses\StructuredAddress;
use App\Support\ServiceAreas\PostalCode;
use App\Support\ServiceAreas\PostalCodeServiceAreas;
use App\Support\ServiceAreas\ServiceAreaResolver;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Livewire;

/**
 * @return list<string>
 */
function zipCodesOf(Location $location): array
{
    return $location->servicePostalCodes()->orderBy('postal_code')->pluck('postal_code')->all();
}

/*
|--------------------------------------------------------------------------
| The rule: an explicit list of ZIP codes per location, and nothing else
|--------------------------------------------------------------------------
*/

test('postal codes are compared as five-digit zip codes', function (?string $typed, ?string $expected) {
    expect(PostalCode::normalize($typed))->toBe($expected);
})->with([
    'a zip code' => ['30301', '30301'],
    'with spaces around it' => ['  30301 ', '30301'],
    'a zip plus four' => ['30301-4420', '30301'],
    'too short' => ['3030', null],
    'too long' => ['303011', null],
    'letters' => ['3O3O1', null],
    'a canadian postal code' => ['K1A 0B1', null],
    'nothing' => [null, null],
]);

test('the location that serves an address is found only from its zip code', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $resolver = app(ServiceAreaResolver::class);

    expect($resolver)->toBeInstanceOf(PostalCodeServiceAreas::class);

    $at = fn (?string $zip, ?float $latitude = null, ?float $longitude = null) => $resolver->locationFor(
        new StructuredAddress(formatted: 'anywhere', postalCode: $zip, latitude: $latitude, longitude: $longitude),
    );

    expect($at('30301')?->is($lakeview))->toBeTrue();
    expect($at('30305-1234')?->is($northside))->toBeTrue();
    expect($at('99999'))->toBeNull();
    expect($at(null))->toBeNull();
    expect($at('not a zip'))->toBeNull();

    // Coordinates play no part: an address with a position but no listed ZIP gets no answer.
    expect($at(null, 33.749, -84.388))->toBeNull();
    expect($at('99999', 33.749, -84.388))->toBeNull();
});

test('no location is given when more than one serves the zip code, or the one that does is inactive', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $closed = Location::factory()->inactive()->create(['name' => 'Closed Branch']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($closed)->create(['postal_code' => '30309']);
    ServiceAreaPostalCode::factory()->for($closed)->create(['postal_code' => '30305']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30305']);

    $at = fn (string $zip) => app(ServiceAreaResolver::class)->locationFor(new StructuredAddress(formatted: 'anywhere', postalCode: $zip));

    expect($at('30301'))->toBeNull();
    expect($at('30309'))->toBeNull();

    // An inactive location sharing a ZIP does not get in the way of the active one.
    expect($at('30305')?->is($northside))->toBeTrue();
});

test('a location cannot list the same zip code twice', function () {
    $lakeview = Location::factory()->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    expect(fn () => ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']))
        ->toThrow(UniqueConstraintViolationException::class);
});

/*
|--------------------------------------------------------------------------
| Managing a service area, with Location management
|--------------------------------------------------------------------------
*/

test('an owner can set a location\'s service area from the locations page', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->assertSee('ZIP codes served')
        ->call('editServiceArea', $lakeview->id)
        ->assertSet('postalCodes', '')
        ->set('postalCodes', "30301\n30305, 30309  30301-4420\n")
        ->call('saveServiceArea')
        ->assertHasNoErrors();

    expect(zipCodesOf($lakeview))->toBe(['30301', '30305', '30309']);
});

test('saving a service area replaces the list, and the dialog shows what is saved', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30305']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->assertSet('postalCodes', "30301\n30305")
        ->set('postalCodes', '30305 30310')
        ->call('saveServiceArea')
        ->assertHasNoErrors();

    expect(zipCodesOf($lakeview))->toBe(['30305', '30310']);
});

test('a service area can be emptied', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->set('postalCodes', '   ')
        ->call('saveServiceArea')
        ->assertHasNoErrors();

    expect(zipCodesOf($lakeview))->toBe([]);
});

test('anything that is not a five-digit zip code is refused and nothing is saved', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->set('postalCodes', '30305 3030 Lakeview')
        ->call('saveServiceArea')
        ->assertHasErrors(['postalCodes' => 'These are not five-digit ZIP codes (3030, Lakeview).']);

    expect(zipCodesOf($lakeview))->toBe(['30301']);
});

test('a zip code shared with another location is pointed out', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30301']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->assertSee('Also in another location&#039;s service area', escape: false)
        ->assertSeeInOrder(['30301', 'Northside'])
        ->assertSee('No location is suggested for these, because there is more than one answer.');
});

test('only someone who may manage locations can change a service area', function (Role $role) {
    $lakeview = Location::factory()->create();
    $user = User::factory()->withRoleEverywhere($role)->create();

    Livewire::actingAs($user)->test('pages::settings.locations')->assertForbidden();

    expect(zipCodesOf($lakeview))->toBe([]);
})->with([
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('service area actions are refused once the permission has been removed', function (string $action) {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $page = Livewire::actingAs($owner)->test('pages::settings.locations')->call('editServiceArea', $lakeview->id);

    $owner->accessGrants()->delete();
    $owner->refresh();

    $page->set('postalCodes', '30305')
        ->call($action, ...($action === 'editServiceArea' ? [$lakeview->id] : []))
        ->assertForbidden();

    expect(zipCodesOf($lakeview))->toBe(['30301']);
})->with(['editServiceArea', 'saveServiceArea']);

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

test('changing a service area records what was added and what was removed', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30305']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->set('postalCodes', '30305 30310 30312')
        ->call('saveServiceArea')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LocationServiceAreaChanged);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($lakeview))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->before())->toBe(['removed' => ['30301']]);
    expect($event->after())->toBe(['added' => ['30310', '30312']]);
});

test('saving a service area without changing it, or one that is refused, records nothing', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editServiceArea', $lakeview->id)
        ->set('postalCodes', ' 30301-9999 ')
        ->call('saveServiceArea')
        ->assertHasNoErrors()
        ->call('editServiceArea', $lakeview->id)
        ->set('postalCodes', 'nonsense')
        ->call('saveServiceArea')
        ->assertHasErrors('postalCodes');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('a service area is not changed when its audit event cannot be recorded', function () {
    $lakeview = Location::factory()->create();

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $this->mock(RecordAuditEvent::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('The audit event could not be recorded.'));

    expect(fn () => app(UpdateServiceAreaPostalCodes::class)->handle($lakeview, ['30305']))
        ->toThrow(RuntimeException::class);

    expect(zipCodesOf($lakeview))->toBe(['30301']);
});
