<?php

use App\Actions\Leads\UnlinkLeadCustomer;
use App\Enums\LeadStatus;
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
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function applicationNativeDialogPattern(): string
{
    return '/(?:\b(?:window|globalThis|self)\s*\.\s*)?\b(?:alert|confirm|prompt)\s*\(/';
}

function usesApplicationNativeDialog(string $source): bool
{
    return str_contains($source, 'wire:confirm') || preg_match(applicationNativeDialogPattern(), $source) === 1;
}

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

test('a stale unlink confirmation preserves a newer customer relationship', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $original = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($location)->from($original)->create();
    $replacement = Account::factory()->withPrimaryContact()->create();
    $replacementProperty = Property::factory()->of($replacement)->create();
    $replacementContact = $replacement->primaryContact();

    $page = Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openUnlinkCustomer')
        ->assertSet('pendingUnlinkAccountId', $original->id)
        ->assertSet('pendingUnlinkContactId', $lead->contact_id)
        ->assertSet('pendingUnlinkPropertyId', $lead->property_id);

    $lead->forceFill([
        'account_id' => $replacement->id,
        'contact_id' => $replacementContact?->id,
        'property_id' => $replacementProperty->id,
    ])->save();

    $page->call('confirmUnlinkCustomer')
        ->assertHasErrors('customerChoice')
        ->assertSee('customer link was just changed by someone else');

    expect($lead->fresh()->only(['account_id', 'contact_id', 'property_id']))->toBe([
        'account_id' => $replacement->id,
        'contact_id' => $replacementContact?->id,
        'property_id' => $replacementProperty->id,
    ]);
});

test('unlink confirmation relationship identity cannot be changed by the browser', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = Lead::factory()->at($location)->from(Account::factory()->withPrimaryContact()->create())->create();

    Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openUnlinkCustomer')
        ->set('pendingUnlinkAccountId', Account::factory()->create()->id);
})->throws(Exception::class, 'Cannot update locked property: [pendingUnlinkAccountId]');

test('unlink confirmation rechecks permission after the dialog opens', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($location)->from($account)->create();

    $page = Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openUnlinkCustomer');

    $owner->accessGrants()->delete();
    $owner->refresh();

    $page->call('confirmUnlinkCustomer')->assertForbidden();

    expect($lead->fresh()->account->is($account))->toBeTrue();
});

test('confirmation refusals render inside customer dialogs', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Fictitious Customer')->withPrimaryContact()->withContact()->create();
    $primaryLink = $account->contactLinks()->where('is_primary', true)->sole();
    $property = Property::factory()->of($account)->create();
    $lead = Lead::factory()->at($location)->from($account)->create();
    $lead->forceFill(['contact_id' => $primaryLink->contact_id, 'property_id' => $property->id])->save();

    $page = Livewire::actingAs($owner)->test('pages::customers.show', ['account' => $account]);

    $page->call('openArchiveAccount')->call('confirmArchiveAccount')
        ->assertHasErrors('account')
        ->assertSee('cannot be archived yet');
    $page->call('openArchiveAccount')->assertHasNoErrors('account');

    $page->call('openRemoveContact', $primaryLink->id)->call('confirmRemoveContact')
        ->assertHasErrors('contact')
        ->assertSee('Make someone else primary first');
    $page->call('openRemoveContact', $primaryLink->id)->assertHasNoErrors('contact');

    $page->call('openArchiveProperty', $property->id)->call('confirmArchiveProperty')
        ->assertHasErrors('property')
        ->assertSee('property is on 1 active lead');
    $page->call('openArchiveProperty', $property->id)->assertHasNoErrors('property');
});

test('a contact-removal dialog shows a customer archived concurrently refusal', function () {
    Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Fictitious Customer')->withPrimaryContact()->withContact()->create();
    $link = $account->contactLinks()->where('is_primary', false)->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('openRemoveContact', $link->id);

    $account->forceFill(['archived_at' => now(), 'archived_by' => $owner->id])->save();
    expect($account->fresh()->isArchived())->toBeTrue();

    $page->call('confirmRemoveContact');
    expect($link->fresh()->isArchived())->toBeFalse();
    $page
        ->assertHasErrors('account')
        ->assertSee('This customer is archived. Restore it before changing it.');

});

test('a property-archive dialog shows a customer archived concurrently refusal', function () {
    Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Fictitious Customer')->withPrimaryContact()->create();
    $property = Property::factory()->of($account)->create();

    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('openArchiveProperty', $property->id);

    $account->forceFill(['archived_at' => now(), 'archived_by' => $owner->id])->save();
    expect($account->fresh()->isArchived())->toBeTrue();

    $page->call('confirmArchiveProperty');
    expect($property->fresh()->isArchived())->toBeFalse();
    $page
        ->assertHasErrors('account')
        ->assertSee('This customer is archived. Restore it before changing it.');
});

test('an archived-concurrently refusal still rechecks customer archive permission', function () {
    Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->commercial('Fictitious Customer')->withPrimaryContact()->withContact()->create();
    $link = $account->contactLinks()->where('is_primary', false)->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::customers.show', ['account' => $account])
        ->call('openRemoveContact', $link->id);

    $account->forceFill(['archived_at' => now(), 'archived_by' => $owner->id])->save();
    $owner->accessGrants()->delete();
    $owner->refresh();

    $page->call('confirmRemoveContact')->assertForbidden();

    expect($link->fresh()->isArchived())->toBeFalse();
});

test('a closed lead unlink refusal renders inside the confirmation dialog', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $account = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($location)->from($account)->create();

    $page = Livewire::actingAs($owner)
        ->test('pages::leads.show', ['lead' => $lead])
        ->call('openUnlinkCustomer');

    $lead->forceFill(['status' => LeadStatus::Disqualified])->save();

    $page->call('confirmUnlinkCustomer')
        ->assertHasErrors('customerChoice')
        ->assertSee('This lead is closed, so its customer can no longer be changed.');
    $page->call('openUnlinkCustomer')->assertHasNoErrors('customerChoice');

    expect($lead->fresh()->account->is($account))->toBeTrue();
});

test('the unlink domain action atomically preserves a relationship replaced after the caller snapshot', function () {
    $location = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $original = Account::factory()->withPrimaryContact()->create();
    $lead = Lead::factory()->at($location)->from($original)->create();
    $snapshot = $lead->fresh();
    $replacement = Account::factory()->withPrimaryContact()->create();
    $replacementProperty = Property::factory()->of($replacement)->create();
    $replacementContact = $replacement->primaryContact();

    $lead->forceFill([
        'account_id' => $replacement->id,
        'contact_id' => $replacementContact?->id,
        'property_id' => $replacementProperty->id,
    ])->save();

    expect(fn () => app(UnlinkLeadCustomer::class)->handle(
        $snapshot,
        $snapshot->account_id,
        $snapshot->contact_id,
        $snapshot->property_id,
    ))->toThrow(ValidationException::class, 'customer link was just changed');

    expect($lead->fresh()->only(['account_id', 'contact_id', 'property_id']))->toBe([
        'account_id' => $replacement->id,
        'contact_id' => $replacementContact?->id,
        'property_id' => $replacementProperty->id,
    ]);
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

test('a list-option refusal renders inside its confirmation dialog', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    LeadChannel::query()->where('name', '!=', 'Phone Call')->update(['is_active' => false]);
    $phone = LeadChannel::query()->where('name', 'Phone Call')->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('openDeactivate', $phone->id)
        ->call('confirmDeactivate')
        ->assertHasErrors('option')
        ->assertSee('This is the only active lead channel.');
    $page->call('openDeactivate', $phone->id)->assertHasNoErrors('option');

    expect($phone->fresh()->is_active)->toBeTrue();
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

test('an access-revocation refusal renders inside its confirmation dialog', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $grant = $owner->accessGrants()->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $owner])
        ->call('openRevoke', $grant->id)
        ->call('confirmRevoke')
        ->assertHasErrors('grant')
        ->assertSee('This is the only Owner who can sign in.');
    $page->call('openRevoke', $grant->id)->assertHasNoErrors('grant');

    expect($grant->fresh())->not->toBeNull();
});

test('application templates do not use browser-native dialogs', function () {
    $files = collect([resource_path('views'), resource_path('js')])
        ->flatMap(fn (string $path) => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && in_array($file->getExtension(), ['php', 'js', 'jsx', 'ts', 'tsx', 'vue'], true));

    foreach ($files as $file) {
        $contents = file_get_contents($file->getPathname());

        expect(usesApplicationNativeDialog($contents))->toBeFalse($file->getPathname());
    }
});

test('the native-dialog guard recognizes markup and supported browser aliases', function (string $source) {
    expect(usesApplicationNativeDialog($source))->toBeTrue();
})->with([
    'Livewire confirmation' => '<button wire:confirm="Continue?">Continue</button>',
    'bare alert' => 'alert("Stop")',
    'bare confirm' => 'confirm ("Continue?")',
    'bare prompt' => 'prompt("Name")',
    'window confirm' => 'window.confirm("Continue?")',
    'globalThis confirm' => 'globalThis . confirm("Continue?")',
    'self alert' => 'self.alert("Stop")',
]);

test('the native-dialog guard ignores application confirmation method names', function () {
    expect(usesApplicationNativeDialog('confirmArchiveAccount(); confirmUnlinkCustomer();'))->toBeFalse();
});
