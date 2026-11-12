<?php

use App\Actions\Staff\GrantAccess;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\PayPlan;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\ServiceAreaPostalCode;
use App\Models\TaxRate;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Support\QuickBooks\QuickBooksClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

test('a new Location starts switched off, in the time zone chosen for it, and opens on its checklist', function () {
    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->set('name', 'Farmington')
        ->call('create')
        ->assertHasErrors(['newTimezone' => 'Choose its time zone.'])
        ->set('newTimezone', 'Europe/London')
        ->call('create')
        ->assertHasErrors('newTimezone')
        ->set('newTimezone', 'America/Denver')
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('settings.locations.setup', Location::query()->sole()));

    $location = Location::query()->sole();

    expect($location->is_active)->toBeFalse()
        ->and($location->isBeingSetUp())->toBeTrue()
        ->and($location->timezone)->toBe('America/Denver')
        ->and(AuditEvent::query()->where('action', AuditAction::LocationCreated)->sole()->values_after)->toEqual(['name' => 'Farmington', 'timezone' => 'America/Denver']);

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
        ->expectsOutputToContain('Still to do on Company > Locations > Farmington > Set up: a tax rate, its people.');
});

test('a Location being set up is listed on Integrations, so its QuickBooks company can be connected before it is switched on', function () {
    $farmington = Location::factory()->beingSetUp()->create(['name' => 'Farmington']);
    $closed = Location::factory()->create(['name' => 'Closed Town', 'is_active' => false]);

    $this->actingAs($this->owner)->get(route('settings.integrations'))->assertOk()
        ->assertSeeHtml('data-test="quickbooks-location-'.$farmington->id.'"')
        ->assertSee('(being set up)')
        ->assertDontSee('Closed Town');
});

test('its people are invited on the checklist, each with their role there, and that is recorded (decision 226)', function () {
    Notification::fake();
    $location = Location::factory()->beingSetUp()->create(['name' => 'Farmington']);

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->set('inviteName', 'Mona Manager')
        ->set('inviteEmail', 'Mona@Example.com')
        ->set('inviteRole', Role::LocationManager->value)
        ->call('invitePerson')
        ->assertHasNoErrors()
        ->assertSet('inviteEmail', '')
        ->set('inviteName', 'Sal Sales')
        ->set('inviteEmail', 'sal@example.com')
        ->set('inviteRole', Role::SalesConsultant->value)
        ->call('invitePerson')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="step-people" data-done="yes"')
        ->assertSee('Invitation not used yet')
        ->set('inviteName', 'Olga Owner')
        ->set('inviteEmail', 'olga@example.com')
        ->set('inviteRole', Role::Owner->value)
        ->call('invitePerson')
        ->assertHasErrors('inviteRole')
        ->set('inviteName', 'Mona Again')
        ->set('inviteEmail', 'mona@example.com')
        ->set('inviteRole', Role::SalesConsultant->value)
        ->call('invitePerson')
        ->assertHasErrors('inviteEmail');

    $mona = User::query()->where('email', 'mona@example.com')->sole();

    expect($mona->accessGrants()->sole())->role->toBe(Role::LocationManager)->location_id->toBe($location->id)
        ->and(User::query()->where('email', 'olga@example.com')->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::StaffAccessGranted)->where('location_id', $location->id)->count())->toBe(2);

    Notification::assertSentTo($mona, StaffInvitation::class);
});

test('when an invitation from the checklist cannot be sent, neither the account nor its access is kept', function () {
    useFailingMailer();
    $location = Location::factory()->beingSetUp()->create();

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->set('inviteName', 'Sal Sales')
        ->set('inviteEmail', 'sal@example.com')
        ->set('inviteRole', Role::SalesConsultant->value)
        ->call('invitePerson')
        ->assertHasErrors('inviteEmail');

    expect(User::query()->where('email', 'sal@example.com')->exists())->toBeFalse()
        ->and(AccessGrant::query()->where('location_id', $location->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::StaffAccessGranted)->exists())->toBeFalse();
});

test('someone already in Backoffice is given a role there from the checklist, once', function () {
    $location = Location::factory()->beingSetUp()->create();
    $existing = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create(['name' => 'Sam Sales']);

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertDontSeeHtml('setup-grant-candidate-'.$this->owner->id.'"')
        ->set('grantUserId', (string) $existing->id)
        ->set('grantRole', Role::SalesConsultant->value)
        ->call('grantPerson')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="setup-person-'.$existing->id.'"')
        ->set('grantUserId', (string) $existing->id)
        ->set('grantRole', Role::SalesConsultant->value)
        ->call('grantPerson')
        ->assertHasErrors(['role' => 'This person already has that access.'])
        ->set('grantUserId', (string) $this->owner->id)
        ->call('grantPerson')
        ->assertHasErrors('grantUserId');

    expect($existing->accessGrants()->where('location_id', $location->id)->count())->toBe(1);
});

test('its salespeople are put on their first pay plan on the checklist, by someone other than themselves', function () {
    $location = Location::factory()->beingSetUp()->create();
    $salesperson = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create(['name' => 'Sal Sales']);
    $this->owner->accessGrants()->create(['role' => Role::SalesConsultant, 'scope' => GrantScope::Location, 'location_id' => $location->id, 'granted_by' => $this->owner->id]);

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertSeeHtml('data-test="setup-pay-plan-'.$salesperson->id.'"')
        ->assertDontSeeHtml('data-test="setup-pay-plan-'.$this->owner->id.'"')
        ->assertSee('Someone else sets your own.')
        ->call('savePayPlan', $salesperson->id)
        ->assertHasErrors(['plan' => 'Choose a pay plan.'])
        ->set("payPlans.{$salesperson->id}", 'sales_straight')
        ->set("payPlansFrom.{$salesperson->id}", '2026-11-16')
        ->call('savePayPlan', $salesperson->id)
        ->assertHasNoErrors()
        ->assertSee('Straight commission: 15% of gross-margin dollars')
        ->set("payPlans.{$this->owner->id}", 'sales_straight')
        ->call('savePayPlan', $this->owner->id)
        ->assertForbidden();

    $plan = PayPlan::query()->where('user_id', $salesperson->id)->sole();

    expect($plan->plan)->toBe('sales_straight')
        ->and($plan->effective_from->toDateString())->toBe('2026-11-16')
        ->and(PayPlan::query()->where('user_id', $this->owner->id)->exists())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::PayPlanSet)->count())->toBe(1);
});

test('its scorecard owner, one of its own managers, and its targets are set on the checklist before it is switched on', function () {
    $location = Location::factory()->beingSetUp()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $location)->create(['name' => 'Mona Manager']);
    $elsewhere = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    Livewire::actingAs($this->owner)->test('pages::settings.location-setup', ['location' => $location])
        ->assertSeeHtml('setup-scorecard-candidate-'.$manager->id.'"')
        ->assertDontSeeHtml('setup-scorecard-candidate-'.$elsewhere->id.'"')
        ->set('scorecardOwnerId', (string) $elsewhere->id)
        ->call('saveScorecard')
        ->assertHasErrors('scorecardOwnerId')
        ->set('scorecardOwnerId', (string) $manager->id)
        ->set('scorecardNsliTarget', '1,250')
        ->set('scorecardCostPerCloseTarget', '400')
        ->set('scorecardNetProfitTarget', '15')
        ->call('saveScorecard')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="step-scorecard" data-done="yes"');

    $location->refresh();

    expect($location->scorecard_owner_id)->toBe($manager->id)
        ->and($location->nsli_target_cents)->toBe(125000)
        ->and(AuditEvent::query()->where('action', AuditAction::LocationScorecardChanged)->where('location_id', $location->id)->exists())->toBeTrue();
});

test('connecting QuickBooks from the checklist comes back to the checklist', function () {
    config(['services.quickbooks' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'environment' => 'sandbox']]);
    Http::preventStrayRequests();
    Http::fake([
        'oauth.platform.intuit.com/*' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3600, 'x_refresh_token_expires_in' => 8726400]),
        'sandbox-quickbooks.api.intuit.com/*' => Http::response(['CompanyInfo' => ['CompanyName' => 'Banana Painting Farmington LLC']]),
    ]);
    $location = Location::factory()->beingSetUp()->create(['name' => 'Farmington']);
    $this->actingAs($this->owner);

    $this->get(route('settings.locations.setup', $location))->assertSeeHtml('data-test="setup-quickbooks-connect"');

    $this->get(route('quickbooks.connect', ['location' => $location, 'setup' => 1]))->assertRedirectContains(QuickBooksClient::AUTHORIZE_URL);
    $this->get(route('quickbooks.callback', ['code' => 'one-time-code', 'realmId' => '9130350001', 'state' => session('quickbooks_pending.state')]))
        ->assertRedirect(route('settings.locations.setup', $location));

    $this->get(route('settings.locations.setup', $location))
        ->assertSee('Farmington is connected to Banana Painting Farmington LLC.')
        ->assertSeeHtml('data-test="step-quickbooks" data-done="yes"');
});
