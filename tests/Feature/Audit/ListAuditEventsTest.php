<?php

use App\Enums\AuditAction;
use App\Enums\DisqualificationCategory;
use App\Enums\ManagedList;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\MarketingSource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('adding an option records who added what, to which list', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);

    Livewire::actingAs($leader)
        ->test('pages::settings.lists')
        ->set('name', 'Billboard')
        ->call('add')
        ->assertHasNoErrors();

    $option = MarketingSource::query()->where('name', 'Billboard')->sole();
    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::ListOptionAdded);
    expect($event->actor->is($leader))->toBeTrue();
    expect($event->actor_name)->toBe('Lee Leader');
    expect($event->subject->is($option))->toBeTrue();
    expect($event->location_id)->toBeNull();
    expect($event->after())->toBe(['name' => 'Billboard']);
    expect(DB::table('audit_events')->value('subject_type'))->toBe('marketing_source');
});

test('adding a disqualification reason records its reporting category', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->set('name', 'Wrong Number')
        ->set('category', DisqualificationCategory::Invalid->value)
        ->call('add')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->subject)->toBeInstanceOf(LeadDisqualificationReason::class);
    expect($event->after()['name'])->toBe('Wrong Number');
    expect($event->after()['category'])->toBe('invalid');
});

test('an option that is refused records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->set('name', 'Referral')
        ->call('add')
        ->assertHasErrors('name');

    expect(AuditEvent::query()->count())->toBe(0);
});

test('renaming an option records the old and new names', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $option = MarketingSource::query()->where('name', 'Radio')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('edit', $option->id)
        ->set('editingName', 'Radio / Podcast')
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::ListOptionRenamed);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($option))->toBeTrue();
    expect($event->before())->toBe(['name' => 'Radio']);
    expect($event->after())->toBe(['name' => 'Radio / Podcast']);
});

test('changing a reporting category records the old and new categories, and no rename', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $reason = LeadDisqualificationReason::query()->where('name', 'Unable to Contact')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->call('edit', $reason->id)
        ->set('editingCategory', DisqualificationCategory::Invalid->value)
        ->call('update')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::ListOptionReclassified);
    expect($event->subject->is($reason))->toBeTrue();
    expect($event->before())->toBe(['category' => 'lead_quality']);
    expect($event->after())->toBe(['category' => 'invalid']);
});

test('renaming and reclassifying together records both', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $reason = LeadDisqualificationReason::query()->where('name', 'Unable to Contact')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->call('edit', $reason->id)
        ->set('editingName', 'Could Not Reach')
        ->set('editingCategory', DisqualificationCategory::Invalid->value)
        ->call('update')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->orderBy('id')->pluck('action')->all())
        ->toBe([AuditAction::ListOptionRenamed, AuditAction::ListOptionReclassified]);
});

test('saving an option without changing it records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $reason = LeadDisqualificationReason::query()->where('name', 'Unable to Contact')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->call('edit', $reason->id)
        ->call('update')
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('moving an option records where it was and where it is now', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $email = LeadChannel::query()->where('name', 'Email')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('moveUp', $email->id)
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();

    expect($event->action)->toBe(AuditAction::ListOptionMoved);
    expect($event->actor->is($owner))->toBeTrue();
    expect($event->subject->is($email))->toBeTrue();
    expect($event->before())->toBe(['position' => 5]);
    expect($event->after())->toBe(['position' => 4]);
});

test('a move that goes nowhere records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('moveUp', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});

test('deactivating and reactivating an option records each', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $radio = MarketingSource::query()->where('name', 'Radio')->sole();

    $page = Livewire::actingAs($owner)->test('pages::settings.lists');

    $page->call('deactivate', $radio->id)->assertHasNoErrors();

    $deactivated = AuditEvent::query()->sole();

    expect($deactivated->action)->toBe(AuditAction::ListOptionDeactivated);
    expect($deactivated->actor->is($owner))->toBeTrue();
    expect($deactivated->subject->is($radio))->toBeTrue();

    $page->call('reactivate', $radio->id)->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(2);
    expect(AuditEvent::query()->latest('id')->firstOrFail()->action)->toBe(AuditAction::ListOptionReactivated);
});

test('a deactivation that is refused, or that changes nothing, records nothing', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    LeadChannel::query()->where('name', '!=', 'Phone Call')->update(['is_active' => false]);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('deactivate', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->assertHasErrors('option')
        ->call('deactivate', LeadChannel::query()->where('name', 'Email')->sole()->id)
        ->assertHasNoErrors();

    expect(AuditEvent::query()->count())->toBe(0);
});
