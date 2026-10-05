<?php

use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Who may open a Lead: decided by the Lead's own location
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('leads.show', Lead::factory()->create()))->assertRedirect(route('login'));
});

test('staff can open a lead at their own location', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Sam Sales']);

    $lead = Lead::factory()->at($lakeview)->create([
        'inquiry_name' => 'Maria Lopez',
        'inquiry_business_name' => 'Lopez Bakery',
        'inquiry_phone' => '555.201.3344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'maria@example.com',
        'inquiry_address' => '14 Oak St',
        'inquiry_message' => 'Wants the shopfront repainted.',
        'captured_by' => $consultant->id,
    ]);

    $this->actingAs($consultant)
        ->get(route('leads.show', $lead))
        ->assertOk()
        ->assertSee('Maria Lopez')
        ->assertSee('Lopez Bakery')
        ->assertSee('(555) 201-3344')
        ->assertSee('Typed as 555.201.3344')
        ->assertSee('maria@example.com')
        ->assertSee('14 Oak St')
        ->assertSee('Wants the shopfront repainted.')
        ->assertSee('Phone Call')
        ->assertSee('Unknown / Unattributed')
        ->assertSee('Lakeview')
        ->assertSee('Sam Sales')
        ->assertSee('Start working')
        ->assertSee('Correct details');
});

test('a lead at another location cannot be opened, whatever location is selected', function () {
    $lakeview = Location::factory()->create();
    $northside = Location::factory()->create();

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);

    $this->actingAs($consultant)->get(route('leads.show', $lead))->assertForbidden();

    // A tampered session value changes nothing: the Lead's own location decides.
    $this->withSession([CurrentLocation::SESSION_KEY => $northside->id])
        ->get(route('leads.show', $lead))
        ->assertForbidden();

    $this->withSession([CurrentLocation::SESSION_KEY => CurrentLocation::ALL_LOCATIONS])
        ->get(route('leads.show', $lead))
        ->assertForbidden();

    Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead])->assertForbidden();
});

test('an unrouted lead can be opened only by company-wide lead staff', function () {
    $lakeview = Location::factory()->create();
    $lead = Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    foreach ([Role::Owner, Role::Leadership, Role::CallCenterAgent] as $role) {
        $this->actingAs(User::factory()->withRoleEverywhere($role)->create())
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Not yet routed to a location');
    }

    foreach ([Role::LocationManager, Role::SalesConsultant, Role::Leadership, Role::CallCenterAgent] as $role) {
        $this->actingAs(User::factory()->withRoleAt($role, $lakeview)->create())
            ->get(route('leads.show', $lead))
            ->assertForbidden();
    }

    // Company-wide, but in roles that do not work leads.
    foreach ([Role::Finance, Role::ProductionManager, Role::LocationManager, Role::SalesConsultant] as $role) {
        $this->actingAs(User::factory()->withRoleEverywhere($role)->create())
            ->get(route('leads.show', $lead))
            ->assertForbidden();
    }
});

test('a lead that does not exist is not found', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)->get('/leads/999999')->assertNotFound();
});

test('access to an open lead is refused once the person loses the location', function (string $action) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopez']);

    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $consultant->accessGrants()->delete();
    $consultant->refresh();

    $page->set('name', 'Changed')->call($action)->assertForbidden();

    $unchanged = $lead->fresh();

    expect($unchanged->inquiry_name)->toBe('Maria Lopez');
    expect($unchanged->status)->toBe(LeadStatus::New);
})->with(['startWorking', 'edit', 'update']);

/*
|--------------------------------------------------------------------------
| Start working: explicit, and the only way to Working
|--------------------------------------------------------------------------
*/

test('start working moves a new lead to working', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Start working')
        ->call('startWorking')
        ->assertHasNoErrors()
        ->assertDontSee('Start working')
        ->assertSee('Working');

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
});

test('correcting a lead never changes its status', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopes']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->set('message', 'Added while on the phone.')
        ->set('leadSourceId', LeadSource::query()->where('name', 'Referral')->sole()->id)
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

test('opening a lead does not change its status', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $this->actingAs($consultant)->get(route('leads.show', $lead))->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::New);
});

/*
|--------------------------------------------------------------------------
| Correcting details and attribution
|--------------------------------------------------------------------------
*/

test('anyone who works leads at the location can correct the inquiry details', function (Role $role) {
    $lakeview = Location::factory()->create();
    $user = User::factory()->withRoleAt($role, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopes', 'inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->assertSet('name', 'Maria Lopes')
        ->assertSet('phone', '5552013344')
        ->set('name', '  Maria Lopez ')
        ->set('phone', '555-201-9999 x4')
        ->set('email', 'Maria@Example.com')
        ->set('businessName', 'Lopez Bakery')
        ->set('addressLine1', '14 Oak St')
        ->set('message', 'Shopfront.')
        ->call('update')
        ->assertHasNoErrors();

    $corrected = $lead->fresh();

    expect($corrected->inquiry_name)->toBe('Maria Lopez');
    expect($corrected->inquiry_phone)->toBe('555-201-9999 x4');
    expect($corrected->inquiry_phone_normalized)->toBe('+15552019999');
    expect($corrected->inquiry_email)->toBe('Maria@Example.com');
    expect($corrected->inquiry_email_normalized)->toBe('maria@example.com');
    expect($corrected->inquiry_business_name)->toBe('Lopez Bakery');
    expect($corrected->inquiry_address)->toBe('14 Oak St');
    expect($corrected->inquiry_message)->toBe('Shopfront.');
})->with([
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('a correction cannot remove every way of identifying the person', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Lopez']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', '')
        ->set('phone', '')
        ->set('email', '')
        ->call('update')
        ->assertHasErrors(['name' => 'Enter at least a name, a phone number or an email address.']);

    expect($lead->fresh()->inquiry_name)->toBe('Maria Lopez');
});

test('the attribution of a lead can be changed', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('channelId', LeadChannel::query()->where('name', 'Web Form')->sole()->id)
        ->set('leadSourceId', LeadSource::query()->where('name', 'Google Business Profile')->sole()->id)
        ->set('marketingSourceId', MarketingSource::query()->where('name', 'Yard Sign')->sole()->id)
        ->call('update')
        ->assertHasNoErrors();

    $changed = $lead->fresh();

    expect($changed->channel->name)->toBe('Web Form');
    expect($changed->leadSource->name)->toBe('Google Business Profile');
    expect($changed->marketingSource->name)->toBe('Yard Sign');
});

test('a lead keeps an option that was deactivated after it was chosen, but cannot be moved to one', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $profile = LeadSource::query()->where('name', 'Google Business Profile')->sole();
    $website = LeadSource::query()->where('name', 'Website — Direct')->sole();

    $lead = Lead::factory()->at($lakeview)->create(['lead_source_id' => $profile->id, 'inquiry_name' => 'Maria Lopes']);

    $profile->forceFill(['is_active' => false])->save();
    $website->forceFill(['is_active' => false])->save();

    // Its own deactivated source is still shown and survives an unrelated correction.
    $page = Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead]);

    $page->assertSee('Google Business Profile')
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->call('update')
        ->assertHasNoErrors();

    expect($lead->fresh()->leadSource->is($profile))->toBeTrue();
    expect($lead->fresh()->inquiry_name)->toBe('Maria Lopez');

    // A different deactivated source cannot be chosen.
    $page->call('edit')
        ->set('leadSourceId', $website->id)
        ->call('update')
        ->assertHasErrors('leadSourceId');

    expect($lead->fresh()->leadSource->is($profile))->toBeTrue();
});

test('company-wide lead staff can correct an unrouted lead', function () {
    Location::factory()->create();

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->create(['inquiry_name' => 'Maria Lopes']);

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('edit')
        ->set('name', 'Maria Lopez')
        ->call('update')
        ->assertHasNoErrors()
        ->call('startWorking')
        ->assertHasNoErrors();

    expect($lead->fresh()->inquiry_name)->toBe('Maria Lopez');
    expect($lead->fresh()->status)->toBe(LeadStatus::Working);
    expect($lead->fresh()->isUnrouted())->toBeTrue();
});
