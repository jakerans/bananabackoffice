<?php

use App\Enums\ManagedList;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\Note;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

test('customer confirmations lock the selected records and change nothing until confirmed', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Fictitious Customer')->withPrimaryContact()->withContact()->create();
    $removableLink = $account->contactLinks()->where('is_primary', false)->sole();
    $property = Property::factory()->of($account)->create();

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account]);

    $page->call('openRemoveContact', $removableLink->id)
        ->assertSet('pendingContactLinkId', $removableLink->id)
        ->assertSet('pendingContactName', $removableLink->contact->fullName());
    expect($removableLink->fresh()->isArchived())->toBeFalse();
    $page->call('confirmRemoveContact')->assertHasNoErrors();
    expect($removableLink->fresh()->isArchived())->toBeTrue();

    $page->call('openArchiveProperty', $property->id)->assertSet('pendingPropertyId', $property->id);
    expect($property->fresh()->isArchived())->toBeFalse();
    $page->call('confirmArchiveProperty')->assertHasNoErrors();
    expect($property->fresh()->isArchived())->toBeTrue();

    $page->call('openArchiveAccount');
    expect($account->fresh()->isArchived())->toBeFalse();
    $page->call('confirmArchiveAccount')->assertHasNoErrors();
    expect($account->fresh()->isArchived())->toBeTrue();
});

test('lead confirmations lock the selected note and preserve the customer link until confirmed', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($location)->from($account)->create();
    $note = Note::factory()->about($lead)->by($owner)->create();

    $page = Livewire::actingAs($owner)->test('pages::leads.show', ['lead' => $lead]);

    $page->call('openRemoveNote', $note->id)->assertSet('pendingNoteId', $note->id);
    expect($note->fresh()->isRemoved())->toBeFalse();
    $page->call('confirmRemoveNote')->assertHasNoErrors();
    expect($note->fresh()->isRemoved())->toBeTrue();

    $page->call('openUnlinkCustomer');
    expect($lead->fresh()->account->is($account))->toBeTrue();
    $page->call('confirmUnlinkCustomer')->assertHasNoErrors();
    expect($lead->fresh()->account_id)->toBeNull();
});

test('list confirmation locks the selected option and waits for confirmation', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $option = MarketingSource::query()->where('name', 'Radio')->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('openDeactivate', $option->id)
        ->assertSet('pendingDeactivationId', $option->id)
        ->assertSet('pendingDeactivationName', 'Radio')
        ->assertSet('pendingDeactivationList', ManagedList::MarketingSources->value);

    expect($option->fresh()->is_active)->toBeTrue();

    $page->call('confirmDeactivate')->assertHasNoErrors();

    expect($option->fresh()->is_active)->toBeFalse();
});

test('list confirmation cannot substitute an option from another list', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $marketingSource = MarketingSource::query()
        ->whereIn('id', LeadChannel::query()->select('id'))
        ->where('is_fallback', false)
        ->firstOrFail();
    $leadChannel = LeadChannel::query()->findOrFail($marketingSource->id);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('openDeactivate', $marketingSource->id)
        ->call('show', ManagedList::LeadChannels->value)
        ->call('confirmDeactivate')
        ->assertNotFound();

    expect($marketingSource->fresh()->is_active)->toBeTrue();
    expect($leadChannel->fresh()->is_active)->toBeTrue();
});

test('location confirmation waits and reuses the existing deactivation refusal', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $location = Location::factory()->create(['name' => 'Fictitious Empty Location']);

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.locations')
        ->call('openDeactivate', $location->id)
        ->assertSet('pendingDeactivationId', $location->id)
        ->assertSet('pendingDeactivationName', 'Fictitious Empty Location');

    expect($location->fresh()->is_active)->toBeTrue();

    $page->call('confirmDeactivateLocation')->assertHasNoErrors();

    expect($location->fresh()->is_active)->toBeFalse();
});

test('staff confirmations lock the exact grant and wait for confirmation', function () {
    $location = Location::factory()->create(['name' => 'Lakeview']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $staff = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();
    $grant = $staff->accessGrants()->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('openRevoke', $grant->id)
        ->assertSet('pendingGrantId', $grant->id)
        ->assertSet('pendingGrantDescription', 'Sales Consultant · Lakeview');

    expect($grant->fresh())->not->toBeNull();
    $page->call('confirmRevoke')->assertHasNoErrors();
    expect($grant->fresh())->toBeNull();

    $page->call('openDeactivate');
    expect($staff->fresh()->isActive())->toBeTrue();
    $page->call('confirmDeactivate')->assertHasNoErrors();
    expect($staff->fresh()->isActive())->toBeFalse();
});

test('application templates do not use browser-native dialogs', function () {
    $files = collect((new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('views')))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php');

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        expect($contents)
            ->not->toContain('wire:confirm')
            ->not->toMatch('/\b(?:window\.)?(?:alert|confirm|prompt)\s*\(/');
    }
});
