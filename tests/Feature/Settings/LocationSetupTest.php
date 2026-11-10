<?php

use App\Actions\Staff\GrantAccess;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\ServiceAreaPostalCode;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Setting up a Location, step 37b (docs/slice-37-location-setup-plan.md;
 * decision 220): a checklist of its steps, and a new Location switched off
 * until its required steps are done.
 */

beforeEach(function () {
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
});

/**
 * Everything a Location needs before it is switched on, except what the test leaves out.
 *
 * @param  list<string>  $except
 */
function setUpRequiredSteps(Location $location, array $except = []): void
{
    if (! in_array('service_area', $except, true)) {
        ServiceAreaPostalCode::factory()->create(['location_id' => $location->id]);
    }

    $book = PriceBook::factory()->create(['location_id' => null]);

    if (! in_array('prices', $except, true)) {
        ProductionRate::factory()->create(['price_book_id' => $book->id]);
    }

    if (! in_array('tax_rate', $except, true)) {
        TaxRate::factory()->create(['price_book_id' => $book->id, 'is_active' => true]);
    }

    if (! in_array('agreement', $except, true)) {
        AgreementWording::factory()->create();
    }

    if (! in_array('people', $except, true)) {
        User::factory()->withRoleAt(Role::LocationManager, $location)->create();
        User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();
    }
}

test('a new Location starts switched off and opens on its checklist', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->set('name', 'Farmington')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.locations.setup', Location::query()->sole()));

    $location = Location::query()->sole();

    expect($location->is_active)->toBeFalse()
        ->and($location->isBeingSetUp())->toBeTrue();

    $this->actingAs($this->owner)->get(route('settings.locations.setup', $location))->assertOk()
        ->assertSee('Set up Farmington')
        ->assertSeeHtml('data-test="setup-being-set-up"')
        ->assertSeeHtml('data-test="step-service_area" data-done="no"')
        ->assertSeeHtml('data-test="step-people" data-done="no"')
        ->assertSeeHtml('data-test="step-deposit_margin" data-done="yes"')
        ->assertSee('Not ready to switch on yet');
});

test('it is switched on only once every required step is done, and that is recorded', function () {
    $location = Location::factory()->beingSetUp()->create();
    setUpRequiredSteps($location, except: ['people']);

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertSee('Still to do: its people.')
        ->call('switchOn')
        ->assertHasErrors('switchOn');

    expect($location->refresh()->is_active)->toBeFalse();

    User::factory()->withRoleAt(Role::LocationManager, $location)->create();
    User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertSee('Ready to switch on')
        ->call('switchOn')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="switch-on-panel"');

    expect($location->refresh()->is_active)->toBeTrue()
        ->and($location->opened_at)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', AuditAction::LocationSwitchedOn)->sole()->actor_id)->toBe($this->owner->id);
});

test('each required step it lacks is named', function (string $missing, string $words) {
    $location = Location::factory()->beingSetUp()->create();
    setUpRequiredSteps($location, except: [$missing]);

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertSee("Still to do: {$words}.")
        ->assertSeeHtml("data-test=\"step-{$missing}\" data-done=\"no\"");
})->with([
    'service area' => ['service_area', 'its service area'],
    'prices' => ['prices', 'its prices'],
    'tax rate' => ['tax_rate', 'a tax rate'],
    'agreement' => ['agreement', 'the agreement\'s wording'],
]);

test('the short steps are done on the checklist, through the same actions as Locations', function () {
    $location = Location::factory()->beingSetUp()->create();

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->set('postalCodes', '87401, 87402')
        ->call('saveServiceArea')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="step-service_area" data-done="yes"')
        ->set('timezone', 'America/Phoenix')
        ->call('saveTimezone')
        ->assertHasNoErrors()
        ->set('depositPercent', '40')
        ->set('targetMarginPercent', '60')
        ->call('saveDepositAndMargin')
        ->assertHasNoErrors()
        ->set('depositPercent', '140')
        ->call('saveDepositAndMargin')
        ->assertHasErrors('depositPercent');

    $location->refresh();

    expect($location->servicePostalCodes()->pluck('postal_code')->sort()->values()->all())->toBe(['87401', '87402'])
        ->and($location->timezone)->toBe('America/Phoenix')
        ->and($location->deposit_percent)->toBe(40)
        ->and($location->target_margin_percent)->toBe(60)
        ->and(AuditEvent::query()->where('location_id', $location->id)->pluck('action')->all())->toContain(
            AuditAction::LocationServiceAreaChanged,
            AuditAction::LocationTimezoneChanged,
            AuditAction::LocationDepositPolicyChanged,
            AuditAction::LocationTargetMarginChanged,
        );
});

test('people may be given access at a Location being set up, but not at one deactivated', function () {
    $settingUp = Location::factory()->beingSetUp()->create();
    $closed = Location::factory()->inactive()->create();
    $staff = User::factory()->create();

    $grant = app(GrantAccess::class)->handle($this->owner, $staff, Role::SalesConsultant, GrantScope::Location, $settingUp);

    expect($grant->location_id)->toBe($settingUp->id)
        // The grant covers nothing until the Location is switched on.
        ->and($staff->refresh()->authorizedLocations())->toBeEmpty();

    expect(fn () => app(GrantAccess::class)->handle($this->owner, $staff, Role::SalesConsultant, GrantScope::Location, $closed))
        ->toThrow(ValidationException::class);
});

test('Reactivate on Locations sends a Location being set up to its checklist instead', function () {
    $location = Location::factory()->beingSetUp()->create();

    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->assertSeeHtml('data-test="location-being-set-up-'.$location->id.'"')
        ->call('reactivate', $location->id)
        ->assertRedirect(route('settings.locations.setup', $location));

    expect($location->refresh()->is_active)->toBeFalse();
});

test('only those who manage Locations may open the checklist', function () {
    $location = Location::factory()->beingSetUp()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    $this->actingAs($manager)->get(route('settings.locations.setup', $location))->assertForbidden();
});

test('the production check names, for each Location, the required steps it still lacks', function () {
    $location = Location::factory()->beingSetUp()->create(['name' => 'Farmington']);
    setUpRequiredSteps($location, except: ['people', 'tax_rate']);

    $this->artisan('backoffice:production-check')
        ->expectsOutputToContain('Still to do on Settings > Locations > Farmington > Set up: a tax rate, its people.');
});
