<?php

use App\Actions\Locations\UpdateServiceAreaPostalCodes;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\Location;
use App\Models\ServiceAreaPostalCode;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Routing an unrouted Lead
|--------------------------------------------------------------------------
*/

test('company-wide lead staff can route an unrouted lead to a location', function (Role $role) {
    $this->freezeSecond();

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()->withRoleEverywhere($role)->create();
    $lead = Lead::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertSee('Central (not yet routed)')
        ->call('openRouting')
        ->assertSet('destinationId', null)
        ->set('destinationId', $lakeview->id)
        ->call('route')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['Who has it', 'Lakeview']);

    $routed = $lead->fresh();

    expect($routed->location->is($lakeview))->toBeTrue();
    expect($routed->status)->toBe(LeadStatus::New);
    expect($routed->first_routed_at->equalTo(now()))->toBeTrue();
    expect($routed->routed_at->equalTo(now()))->toBeTrue();
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
    'call center agent' => Role::CallCenterAgent,
]);

test('the location whose service area has the lead\'s zip code is suggested, and can be overridden', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->create(['inquiry_address' => '14 Oak St, Lakeview, GA 30301', 'address_postal_code' => '30301']);

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->assertSet('destinationId', $lakeview->id)
        ->assertSee('Suggested: Lakeview, because ZIP code 30301 is in its service area.')
        ->set('destinationId', $northside->id)
        ->call('route')
        ->assertHasNoErrors();

    expect($lead->fresh()->location->is($northside))->toBeTrue();
});

test('no location is suggested when the zip code does not point at exactly one', function (Closure $arrange) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $lead = $arrange($lakeview, $northside);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->assertSet('destinationId', null)
        ->assertDontSee('Suggested:');
})->with([
    'the lead has no address' => [fn () => Lead::factory()->create()],
    'no location lists the zip code' => [fn () => Lead::factory()->create(['inquiry_address' => '9 Elm St 30399', 'address_postal_code' => '30399'])],
    'two locations list the zip code' => [function (Location $lakeview, Location $northside) {
        ServiceAreaPostalCode::factory()->for($lakeview)->create(['postal_code' => '30301']);
        ServiceAreaPostalCode::factory()->for($northside)->create(['postal_code' => '30301']);

        return Lead::factory()->create(['inquiry_address' => '14 Oak St 30301', 'address_postal_code' => '30301']);
    }],
]);

/*
|--------------------------------------------------------------------------
| Moving a routed Lead to another location
|--------------------------------------------------------------------------
*/

test('moving a lead to another location needs a reason, and keeps when it was first routed', function () {
    $this->travelTo('2026-10-01 12:00:00');

    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->at($lakeview)->working()->create();

    $this->travelTo('2026-10-03 09:00:00');

    $page = Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->set('destinationId', $northside->id)
        ->call('route')
        ->assertHasErrors('routingReason');

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();

    $page->set('routingReason', 'The property is on the north side.')
        ->call('route')
        ->assertHasNoErrors();

    $moved = $lead->fresh();

    expect($moved->location->is($northside))->toBeTrue();
    expect($moved->status)->toBe(LeadStatus::Working);
    expect($moved->first_routed_at->toDateTimeString())->toBe('2026-10-01 12:00:00');
    expect($moved->routed_at->toDateTimeString())->toBe('2026-10-03 09:00:00');
});

test('a destination that is inactive, unchanged or made up is refused', function (Closure $destinationId) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    Location::factory()->create(['name' => 'Northside']);

    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($agent)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        ->set('destinationId', $destinationId($lakeview))
        ->set('routingReason', 'Wrong branch.')
        ->call('route')
        ->assertHasErrors('destinationId');

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();
})->with([
    'an inactive location' => [fn () => Location::factory()->inactive()->create()->id],
    'the location it is already at' => [fn (Location $lakeview) => $lakeview->id],
    'a location that does not exist' => [fn () => 999999],
    'none chosen' => [fn () => null],
]);

test('a location manager of two locations can move a lead between them, and nowhere else', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $eastside = Location::factory()->create(['name' => 'Eastside']);

    $manager = User::factory()
        ->withRoleAt(Role::LocationManager, $lakeview)
        ->withRoleAt(Role::LocationManager, $northside)
        ->create();

    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openRouting')
        // The only place they could send it is offered, so it is already chosen.
        ->assertSet('destinationId', $northside->id)
        ->set('routingReason', 'Closer to our north crew.')
        ->set('destinationId', $eastside->id)
        ->call('route')
        ->assertHasErrors('destinationId');

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();

    $page->set('destinationId', $northside->id)->call('route')->assertHasNoErrors();

    expect($lead->fresh()->location->is($northside))->toBeTrue();
});

test('a location manager cannot move a lead out of, or into, a location they do not manage', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    // Manages Lakeview; only sells at Northside, which is not enough to receive a transfer.
    $manager = User::factory()
        ->withRoleAt(Role::LocationManager, $lakeview)
        ->withRoleAt(Role::SalesConsultant, $northside)
        ->create();

    $leaving = Lead::factory()->at($lakeview)->create();
    $arriving = Lead::factory()->at($northside)->create();

    foreach ([[$leaving, $northside], [$arriving, $lakeview]] as [$lead, $destination]) {
        Livewire::actingAs($manager)
            ->test('pages::leads.show', ['lead' => $lead])
            ->assertDontSee('Change location')
            ->call('openRouting')
            ->assertForbidden();

        Livewire::actingAs($manager)
            ->test('pages::leads.show', ['lead' => $lead])
            ->set('destinationId', $destination->id)
            ->set('routingReason', 'Trying anyway.')
            ->call('route')
            ->assertForbidden();
    }

    expect($leaving->fresh()->location->is($lakeview))->toBeTrue();
    expect($arriving->fresh()->location->is($northside))->toBeTrue();
});

test('staff who do not route cannot route or move a lead', function (Role $role) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $user = User::factory()->withRoleAt($role, $lakeview)->withRoleAt($role, $northside)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSee('Change location')
        ->call('openRouting')
        ->assertForbidden();

    Livewire::actingAs($user)
        ->test('pages::leads.show', ['lead' => $lead])
        ->set('destinationId', $northside->id)
        ->set('routingReason', 'Trying anyway.')
        ->call('route')
        ->assertForbidden();

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();
})->with([
    'sales consultant' => Role::SalesConsultant,
    // Routing is a company-wide permission: holding the role at each location is not enough.
    'call center agent at each location' => Role::CallCenterAgent,
]);

/*
|--------------------------------------------------------------------------
| Returning a Lead to central
|--------------------------------------------------------------------------
*/

test('a routed lead can be returned to central with a reason', function (Closure $user) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $lead = Lead::factory()->at($lakeview)->working()->create();

    $page = Livewire::actingAs($user($lakeview))
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReturn')
        ->call('returnToCentral')
        ->assertHasErrors('returnReason');

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();

    $page->set('returnReason', 'Outside the area we serve.')->call('returnToCentral')->assertHasNoErrors();

    $returned = $lead->fresh();

    expect($returned->isUnrouted())->toBeTrue();
    expect($returned->routed_at)->toBeNull();
    expect($returned->first_routed_at)->not->toBeNull();
    expect($returned->status)->toBe(LeadStatus::Working);
})->with([
    'owner' => [fn () => User::factory()->withRoleEverywhere(Role::Owner)->create()],
    'leadership' => [fn () => User::factory()->withRoleEverywhere(Role::Leadership)->create()],
    'company-wide call center agent' => [fn () => User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create()],
    'location manager' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create()],
    'call center agent at the location' => [fn (Location $lakeview) => User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create()],
]);

test('a location manager who returns a lead to central can no longer open it', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    Livewire::actingAs($manager)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openReturn')
        ->assertSee('You will no longer be able to open it.')
        ->set('returnReason', 'Wrong branch, and I do not know the right one.')
        ->call('returnToCentral')
        ->assertRedirect(route('leads'));

    $this->actingAs($manager)->get(route('leads.show', $lead))->assertForbidden();
});

test('a sales consultant cannot return a lead to central', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSee('Return to central')
        ->set('returnReason', 'Not mine.')
        ->call('returnToCentral')
        ->assertForbidden();

    expect($lead->fresh()->location->is($lakeview))->toBeTrue();
});

test('an unrouted lead has nowhere to be returned from', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = Lead::factory()->create();

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->assertDontSee('Return to central')
        ->set('returnReason', 'Trying anyway.')
        ->call('returnToCentral')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Out-of-date screens, and things that never move a Lead
|--------------------------------------------------------------------------
*/

test('a routing or return dialog that is out of date saves nothing', function (string $open, Closure $submit) {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);
    $eastside = Location::factory()->create(['name' => 'Eastside']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    $page = Livewire::actingAs($owner)->test('pages::leads.show', ['lead' => $lead])->call($open);

    // Someone else moves it while the dialog is open.
    $lead->fresh()->forceFill(['location_id' => $eastside->id])->save();

    $submit($page, $northside)->assertHasErrors('stale')->assertSee('This lead was just changed by someone else');

    expect($lead->fresh()->location->is($eastside))->toBeTrue();
})->with([
    'moving' => ['openRouting', fn ($page, Location $northside) => $page->set('destinationId', $northside->id)->set('routingReason', 'North side.')->call('route')],
    'returning' => ['openReturn', fn ($page) => $page->set('returnReason', 'Wrong branch.')->call('returnToCentral')],
]);

test('changing a service area does not move leads that are already routed', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $lead = Lead::factory()->at($northside)->create(['inquiry_address' => '14 Oak St 30301', 'address_postal_code' => '30301']);

    $this->actingAs(User::factory()->withRoleEverywhere(Role::Owner)->create());

    app(UpdateServiceAreaPostalCodes::class)->handle($lakeview, ['30301']);

    expect($lead->fresh()->location->is($northside))->toBeTrue();
});
