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

function channelNamed(string $name): LeadChannel
{
    return LeadChannel::query()->where('name', $name)->sole();
}

/*
|--------------------------------------------------------------------------
| Who may capture a Lead
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('leads.create'))->assertRedirect(route('login'));
});

test('roles that work leads can open the new lead page', function (Role $role) {
    $user = User::factory()->withRoleAt($role, Location::factory()->create())->create();

    $this->actingAs($user)->get(route('leads.create'))->assertOk()->assertSee('New lead');
})->with([
    'leadership' => Role::Leadership,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
]);

test('roles that do not work leads cannot open the new lead page', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('leads.create'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::leads.create')->assertForbidden();
})->with([
    'finance' => Role::Finance,
    'production manager' => Role::ProductionManager,
]);

/*
|--------------------------------------------------------------------------
| The minimum: a channel and one way of identifying the person
|--------------------------------------------------------------------------
*/

test('a lead can be saved with only a name, a phone number and a channel', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->freezeSecond();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', '  Maria Lopez  ')
        ->set('phone', '(555) 201-3344')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.show', Lead::query()->sole()));

    $lead = Lead::query()->sole();

    expect($lead->inquiry_name)->toBe('Maria Lopez');
    expect($lead->inquiry_phone)->toBe('(555) 201-3344');
    expect($lead->inquiry_phone_normalized)->toBe('+15552013344');
    expect($lead->inquiry_email)->toBeNull();
    expect($lead->inquiry_business_name)->toBeNull();
    expect($lead->inquiry_address)->toBeNull();
    expect($lead->inquiry_message)->toBeNull();
    expect($lead->status)->toBe(LeadStatus::New);
    expect($lead->channel->name)->toBe('Phone Call');
    expect($lead->location_id)->toBeNull();
    expect($lead->capturedBy->is($agent))->toBeTrue();
    expect($lead->received_at->equalTo(now()->startOfMinute()))->toBeTrue();
});

test('any one of a name, a phone number or an email is enough', function (string $field, string $value) {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set($field, $value)
        ->set('channelId', channelNamed('Facebook / Instagram Message')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
})->with([
    'only a name' => ['name', 'Maria Lopez'],
    'only a phone number' => ['phone', '555-201-3344'],
    'only an email' => ['email', 'maria@example.com'],
]);

test('a lead needs a name, a phone number or an email', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', '   ')
        ->set('businessName', 'Lopez Bakery')
        ->set('message', 'Wants the shopfront repainted.')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasErrors(['name' => 'Enter at least a name, a phone number or an email address.']);

    expect(Lead::query()->count())->toBe(0);
});

test('a lead needs a channel, and none is chosen for you', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('channelId', null)
        ->set('name', 'Maria Lopez')
        ->call('save')
        ->assertHasErrors(['channelId' => 'Choose how the inquiry arrived.']);

    expect(Lead::query()->count())->toBe(0);
});

test('every detail given is kept as it was typed', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('phone', '555.201.3344 x12')
        ->set('email', 'Maria.Lopez@Example.COM')
        ->set('businessName', 'Lopez Bakery')
        ->set('message', "Two storey exterior.\nWants it done before winter.")
        ->set('addressLine1', '14 Oak St, near the lake')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->inquiry_phone)->toBe('555.201.3344 x12');
    expect($lead->inquiry_phone_normalized)->toBe('+15552013344');
    expect($lead->inquiry_email)->toBe('Maria.Lopez@Example.COM');
    expect($lead->inquiry_email_normalized)->toBe('maria.lopez@example.com');
    expect($lead->inquiry_business_name)->toBe('Lopez Bakery');
    expect($lead->inquiry_message)->toBe("Two storey exterior.\nWants it done before winter.");
    expect($lead->inquiry_address)->toBe('14 Oak St, near the lake');
});

test('an odd phone number is saved as typed with a hint, not refused', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('phone', '201-3344 ask for Sam')
        ->assertSee('This does not look like a complete phone number. It will be saved exactly as typed.')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->inquiry_phone)->toBe('201-3344 ask for Sam');
    expect($lead->inquiry_phone_normalized)->toBeNull();
});

test('a recognizable phone number shows no hint', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('phone', '555 201 3344')
        ->assertDontSee('This does not look like a complete phone number');
});

test('a malformed email address is refused', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('email', 'maria at example')
        ->set('channelId', channelNamed('Email')->id)
        ->call('save')
        ->assertHasErrors('email');

    expect(Lead::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Attribution
|--------------------------------------------------------------------------
*/

test('both sources start on unknown, so a lead can be saved without touching them', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('leadSourceId', LeadSource::fallback()->id)
        ->assertSet('marketingSourceId', MarketingSource::fallback()->id)
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->leadSource->name)->toBe('Unknown');
    expect($lead->marketingSource->name)->toBe('Unknown / Unattributed');
});

test('the three kinds of attribution are recorded separately when they are known', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Web Form')->id)
        ->set('leadSourceId', LeadSource::query()->where('name', 'Google Business Profile')->sole()->id)
        ->set('marketingSourceId', MarketingSource::query()->where('name', 'Yard Sign')->sole()->id)
        ->call('save')
        ->assertHasNoErrors();

    $lead = Lead::query()->sole();

    expect($lead->channel->name)->toBe('Web Form');
    expect($lead->leadSource->name)->toBe('Google Business Profile');
    expect($lead->marketingSource->name)->toBe('Yard Sign');
});

test('a deactivated option is not offered and is refused if submitted', function (string $field, Closure $option) {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $inactive = $option();
    $inactive->forceFill(['is_active' => false])->save();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertDontSee($inactive->name)
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->set($field, $inactive->id)
        ->call('save')
        ->assertHasErrors($field);

    expect(Lead::query()->count())->toBe(0);
})->with([
    'channel' => ['channelId', fn () => channelNamed('Meta Form')],
    // Names that appear in one list only, so "not offered" is about that list.
    'lead source' => ['leadSourceId', fn () => LeadSource::query()->where('name', 'Google Business Profile')->sole()],
    'marketing source' => ['marketingSourceId', fn () => MarketingSource::query()->where('name', 'Google Ads')->sole()],
]);

/*
|--------------------------------------------------------------------------
| Location: company-wide staff may leave it unrouted; location staff may not
|--------------------------------------------------------------------------
*/

test('company-wide staff start on not yet routed and may leave the lead unrouted', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    Livewire::actingAs($user)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('locationId', '')
        ->assertSee('Not yet routed')
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->isUnrouted())->toBeTrue();
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
    'call center agent' => Role::CallCenterAgent,
]);

test('company-wide staff may choose a location while creating the lead', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->set('locationId', (string) $lakeview->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->location->is($lakeview))->toBeTrue();
});

test('staff with one location capture for that location without choosing', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->create(['name' => 'Northside']);

    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('locationId', (string) $lakeview->id)
        ->assertSee('Lakeview')
        ->assertDontSee('Northside')
        ->assertDontSee('Not yet routed')
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('In Person')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->location->is($lakeview))->toBeTrue();
});

test('staff with several locations start on the one they are working in and are offered only their own', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    Location::factory()->create(['name' => 'Riverside']);

    $consultant = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();

    session([CurrentLocation::SESSION_KEY => $northside->id]);

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('locationId', (string) $northside->id)
        ->assertSee('Lakeview')
        ->assertDontSee('Riverside')
        ->assertDontSee('Not yet routed');
});

test('location staff cannot leave a lead unrouted', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->set('locationId', '')
        ->call('save')
        ->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

test('a lead cannot be captured for a location the person does not work in', function (Closure $locationId) {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->set('locationId', $locationId())
        ->call('save')
        ->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
})->with([
    'another location' => [fn () => (string) Location::factory()->create()->id],
    'a location that does not exist' => [fn () => '999999'],
    'an inactive location' => [fn () => (string) Location::factory()->inactive()->create()->id],
]);

test('a role that cannot work leads at a location cannot capture one there, even with other access to it', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()
        ->withRoleAt(Role::SalesConsultant, $lakeview)
        ->withRoleAt(Role::ProductionManager, $northside)
        ->create();

    Livewire::actingAs($user)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertDontSee('Northside')
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->set('locationId', (string) $northside->id)
        ->call('save')
        ->assertForbidden();

    expect(Lead::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Received time
|--------------------------------------------------------------------------
*/

test('the received time is typed in the company timezone and stored in utc', function () {
    config(['app.display_timezone' => 'America/New_York']);

    $this->travelTo('2026-10-05 18:30:00');

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertSet('receivedAt', '2026-10-05T14:30')
        ->set('receivedAt', '2026-10-04T21:15')
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->sole()->received_at->toDateTimeString())->toBe('2026-10-05 01:15:00');
});

test('the received time cannot be in the future', function () {
    config(['app.display_timezone' => 'America/New_York']);

    $this->travelTo('2026-10-05 18:30:00');

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('receivedAt', '2026-10-05T16:00')
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasErrors(['receivedAt' => 'The received time cannot be in the future.']);

    expect(Lead::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Fast intake
|--------------------------------------------------------------------------
*/

test('save and add another records the lead and returns to a fresh form', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('saveAndAddAnother')
        ->assertHasNoErrors()
        ->assertRedirect(route('leads.create'));

    expect(Lead::query()->sole()->inquiry_name)->toBe('Maria Lopez');
});

test('pressing save twice records one lead', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('name', 'Maria Lopez')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->call('save')
        ->call('saveAndAddAnother');

    expect(Lead::query()->count())->toBe(1);
});

test('a form that was refused can be corrected and saved', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasErrors('name')
        ->set('name', 'Maria Lopez')
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(1);
});

test('the form starts with the name ready to type and the save button reachable', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    $this->actingAs($agent)
        ->get(route('leads.create'))
        ->assertOk()
        ->assertSeeInOrder([
            'Quick capture', 'Name', 'Phone', 'Email', 'Business name', 'How did it arrive?', 'Service(s)', 'Project size',
            'Tell us about what we&#039;re painting today', 'Property address', 'Street address', 'City', 'State', 'ZIP code',
            'Attribution and routing', 'Marketing source', 'Lead source', 'Location', 'Received',
            'Save and add another', 'Save lead',
        ], escape: false)
        ->assertSee('autofocus', escape: false)
        ->assertSee('type="tel"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Earlier inquiries: only ones the person may already see
|--------------------------------------------------------------------------
*/

test('earlier inquiries with the same phone or email are shown when the person may see them', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria Earlier', 'inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Maria By Email', 'inquiry_phone' => null, 'inquiry_phone_normalized' => null, 'inquiry_email' => 'maria@example.com', 'inquiry_email_normalized' => 'maria@example.com']);
    Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Someone Else']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->assertDontSee('Earlier inquiries with this phone or email')
        ->set('phone', '(555) 201-3344')
        ->assertSee('Earlier inquiries with this phone or email')
        ->assertSee('Maria Earlier')
        ->assertDontSee('Maria By Email')
        ->assertDontSee('Someone Else')
        ->set('email', 'MARIA@example.com')
        ->assertSee('Maria By Email');
});

test('an earlier inquiry never stops a new one being saved', function () {
    $lakeview = Location::factory()->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    Lead::factory()->at($lakeview)->create(['inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('phone', '555-201-3344')
        ->set('channelId', channelNamed('Phone Call')->id)
        ->call('save')
        ->assertHasNoErrors();

    expect(Lead::query()->count())->toBe(2);
});

test('nothing is revealed about a matching inquiry the person may not see', function (Closure $hiddenLead) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $hiddenLead()->forceFill([
        'inquiry_name' => 'Hidden Harriet',
        'inquiry_phone' => '5552013344',
        'inquiry_phone_normalized' => '+15552013344',
        'inquiry_email' => 'harriet@example.com',
        'inquiry_email_normalized' => 'harriet@example.com',
    ])->save();

    Livewire::actingAs($consultant)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('phone', '(555) 201-3344')
        ->set('email', 'harriet@example.com')
        ->assertDontSee('Earlier inquiries with this phone or email')
        ->assertDontSee('Hidden Harriet')
        ->assertDontSee('Northside')
        ->assertDontSee('Unrouted');
})->with([
    'at another location' => [fn () => Lead::factory()->at(Location::factory()->create(['name' => 'Northside']))->create()],
    'unrouted' => [fn () => Lead::factory()->create()],
]);

test('company-wide staff see matching inquiries wherever they are', function () {
    $northside = Location::factory()->create(['name' => 'Northside']);
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Lead::factory()->at($northside)->create(['inquiry_name' => 'Routed Rita', 'inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una', 'inquiry_phone' => '5552013344', 'inquiry_phone_normalized' => '+15552013344']);

    Livewire::actingAs($agent)
        ->test('pages::leads.create')
        ->set('projectSizeId', projectSizeId())
        ->set('serviceIds', oneService())
        ->set('phone', '555 201 3344')
        ->assertSee('Routed Rita')
        ->assertSee('Unrouted Una');
});
