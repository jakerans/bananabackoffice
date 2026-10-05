<?php

use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use Livewire\Livewire;

test('adding a location records who added it', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', 'Lakeview')
        ->call('create')
        ->assertHasNoErrors();

    $lakeview = Location::query()->sole();
    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LocationCreated);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($lakeview))->toBeTrue();
    expect($event->location->is($lakeview))->toBeTrue();
    expect($event->after())->toBe(['name' => 'Lakeview']);
});

test('a location that is refused records nothing', function () {
    Location::factory()->create(['name' => 'Lakeview']);

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->set('name', 'Lakeview')
        ->call('create')
        ->assertHasErrors('name');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('renaming a location records the old and new names', function () {
    $location = Location::factory()->create(['name' => 'Lake View']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('edit', $location->id)
        ->set('editingName', 'Lakeview')
        ->call('rename')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::LocationRenamed);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($location))->toBeTrue();
    expect($event->before())->toBe(['name' => 'Lake View']);
    expect($event->after())->toBe(['name' => 'Lakeview']);
});

test('saving a location under the name it already has records nothing', function () {
    $location = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('edit', $location->id)
        ->call('rename')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('deactivating and reactivating a location records each', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::settings.locations');

    $page->call('deactivate', $location->id)->assertHasNoErrors();

    $deactivated = AuditEvent::query()->sole();

    expect($deactivated->action)->toBe(AuditAction::LocationDeactivated);
    expect($deactivated->actor->is($owner))->toBeTrue();
    expect($deactivated->subject->is($location))->toBeTrue();

    $page->call('reactivate', $location->id)->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(2);
    expect(AuditEvent::query()->latest('id')->firstOrFail()->action)->toBe(AuditAction::LocationReactivated);
});

test('deactivating a location that is already inactive records nothing more', function () {
    $location = Location::factory()->create(['is_active' => false]);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('deactivate', $location->id)
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});
