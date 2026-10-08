<?php

use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected to the login page', function () {
    $this->get(route('settings.locations'))->assertRedirect(route('login'));
});

test('an owner can view the locations page', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('settings.locations'))
        ->assertOk()
        ->assertSee('Lakeview');
});

test('staff without the manage locations permission are forbidden', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('settings.locations'))->assertForbidden();
})->with([
    'leadership' => Role::Leadership,
    'finance' => Role::Finance,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
]);

test('the locations component cannot be mounted without permission', function () {
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();

    Livewire::actingAs($manager)
        ->test('pages::settings.locations')
        ->assertForbidden();
});

test('an owner can add a location', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', '  Lakeview  ')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('name', '');

    $location = Location::query()->sole();

    expect($location->name)->toBe('Lakeview');
    expect($location->is_active)->toBeTrue();
});

test('a location name is required', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', '   ')
        ->call('create')
        ->assertHasErrors(['name' => 'The name field is required.']);

    expect(Location::query()->count())->toBe(0);
});

test('a location name must be unique', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', 'Lakeview')
        ->call('create')
        ->assertHasErrors(['name' => 'The name has already been taken.']);

    expect(Location::query()->count())->toBe(1);
});

test('a location name may not be longer than 100 characters', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', str_repeat('a', 101))
        ->call('create')
        ->assertHasErrors(['name' => 'The name field must not be greater than 100 characters.']);

    expect(Location::query()->count())->toBe(0);
});

test('an owner can rename a location', function () {
    $location = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('edit', $location->id)
        ->assertSet('editingName', 'Lakeview')
        ->set('editingName', 'Lakeview North')
        ->call('rename')
        ->assertHasNoErrors();

    expect($location->refresh()->name)->toBe('Lakeview North');
});

test('a location cannot be renamed to a name that is already in use', function () {
    Location::factory()->create(['name' => 'Riverside']);
    $location = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('edit', $location->id)
        ->set('editingName', 'Riverside')
        ->call('rename')
        ->assertHasErrors(['editingName' => 'The name has already been taken.']);

    expect($location->refresh()->name)->toBe('Lakeview');
});

test('a location keeps its own name when saved unchanged', function () {
    $location = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('edit', $location->id)
        ->call('rename')
        ->assertHasNoErrors();

    expect($location->refresh()->name)->toBe('Lakeview');
});

test('an owner can deactivate and reactivate a location', function () {
    $location = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $component = Livewire::actingAs($owner)->test('pages::settings.locations');

    $component->call('deactivate', $location->id)->assertHasNoErrors();

    expect($location->refresh()->is_active)->toBeFalse();

    $component->call('reactivate', $location->id)->assertHasNoErrors();

    expect($location->refresh()->is_active)->toBeTrue();
});

test('the location being renamed cannot be changed from the browser', function () {
    $location = Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('editingId', $location->id);
})->throws(Exception::class, 'Cannot update locked property: [editingId]');

test('location actions are refused once the permission has been removed', function (string $action, array $arguments) {
    $location = Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $component = Livewire::actingAs($owner)->test('pages::settings.locations');

    $owner->accessGrants()->delete();
    $owner->refresh();

    $component
        ->set('name', 'Riverside')
        ->call($action, ...array_map(fn ($argument) => $argument === 'location' ? $location->id : $argument, $arguments))
        ->assertForbidden();

    expect(Location::query()->count())->toBe(1);
    expect($location->refresh()->name)->toBe('Lakeview');
    expect($location->is_active)->toBeTrue();
})->with([
    'create' => ['create', []],
    'edit' => ['edit', ['location']],
    'deactivate' => ['deactivate', ['location']],
    'reactivate' => ['reactivate', ['location']],
]);

test('a location is created in the eastern timezone', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)->test('pages::settings.locations')->set('name', 'Lakeview')->call('create');

    expect(Location::query()->sole()->timezone)->toBe('America/New_York');
});

test('an owner can change a location timezone to any listed zone', function (string $timezone) {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editTimezone', $location->id)
        ->assertSet('editingTimezone', 'America/New_York')
        ->set('editingTimezone', $timezone)
        ->call('saveTimezone')
        ->assertHasNoErrors();

    expect($location->fresh()->timezone)->toBe($timezone);
})->with([
    'America/New_York',
    'America/Chicago',
    'America/Denver',
    'America/Phoenix',
    'America/Los_Angeles',
    'America/Anchorage',
    'Pacific/Honolulu',
]);

test('the timezone is shown on the locations page', function () {
    Location::factory()->create(['name' => 'Lakeview', 'timezone' => 'America/Chicago']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('settings.locations'))->assertOk()->assertSee('Central');
});

test('a timezone outside the fixed list is refused', function (string $timezone) {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editTimezone', $location->id)
        ->set('editingTimezone', $timezone)
        ->call('saveTimezone')
        ->assertHasErrors('editingTimezone');

    expect($location->fresh()->timezone)->toBe('America/New_York');
    expect(AuditEvent::query()->count())->toBe(0);
})->with(['Europe/London', 'UTC', 'Asia/Tokyo', 'america/chicago', 'EST', 'America/Puerto_Rico', '', 'not a zone']);

test('only someone who may manage locations can change a timezone, even by a direct call', function (Role $role) {
    $location = Location::factory()->create();
    $user = User::factory()->withRoleEverywhere($role)->create();

    Livewire::actingAs($user)
        ->test('pages::settings.locations')
        ->assertForbidden();

    expect($location->fresh()->timezone)->toBe('America/New_York');
})->with([Role::Leadership, Role::LocationManager, Role::CallCenterAgent, Role::Finance, Role::SalesConsultant, Role::ProductionManager]);

test('a timezone save is refused for an owner whose access was removed after the dialog opened', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editTimezone', $location->id)
        ->set('editingTimezone', 'America/Denver');

    $owner->accessGrants()->delete();
    $owner->refresh();

    $page->call('saveTimezone')->assertForbidden();

    expect($location->fresh()->timezone)->toBe('America/New_York');
});

test('the location being edited cannot be swapped from the browser', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('editTimezone', $location->id)
        ->set('timezoneLocationId', Location::factory()->create()->id);
})->throws(Exception::class, 'Cannot update locked property: [timezoneLocationId]');
