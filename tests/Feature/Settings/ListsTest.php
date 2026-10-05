<?php

use App\Enums\DisqualificationCategory;
use App\Enums\ManagedList;
use App\Enums\Role;
use App\Models\LeadChannel;
use App\Models\LeadDisqualificationReason;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\SalesLossReason;
use App\Models\User;
use App\Support\Navigation\Navigation;
use Livewire\Livewire;

/**
 * The names in a list, in the order staff would see them.
 *
 * @return list<string>
 */
function namesIn(ManagedList $list, bool $activeOnly = false): array
{
    $query = $list->modelClass()::query()->ordered();

    return ($activeOnly ? $query->active() : $query)->pluck('name')->all();
}

dataset('lists', [
    'marketing sources' => [ManagedList::MarketingSources],
    'lead sources' => [ManagedList::LeadSources],
    'lead channels' => [ManagedList::LeadChannels],
    'disqualification reasons' => [ManagedList::DisqualificationReasons],
    'sales loss reasons' => [ManagedList::SalesLossReasons],
]);

/*
|--------------------------------------------------------------------------
| Who may open the page
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('settings.lists'))->assertRedirect(route('login'));
});

test('owner and leadership with company-wide access can open the page', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)
        ->get(route('settings.lists'))
        ->assertOk()
        ->assertSee('Lead & Sales Lists', escape: true)
        ->assertSee('Google Ads');
})->with([
    'owner' => Role::Owner,
    'leadership' => Role::Leadership,
]);

test('leadership at a single location cannot open the page', function () {
    $user = User::factory()->withRoleAt(Role::Leadership, Location::factory()->create())->create();

    $this->actingAs($user)->get(route('settings.lists'))->assertForbidden();
});

test('other roles cannot open the page', function (Role $role) {
    Location::factory()->create();

    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('settings.lists'))->assertForbidden();
})->with([
    'finance' => Role::Finance,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
]);

test('the page cannot be mounted without permission', function () {
    $manager = User::factory()->withRoleEverywhere(Role::LocationManager)->create();

    Livewire::actingAs($manager)->test('pages::settings.lists')->assertForbidden();
});

test('a list that does not exist is not found', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('settings.lists', ['list' => 'payroll_codes']))->assertNotFound();

    Livewire::actingAs($owner)->test('pages::settings.lists')->call('show', 'payroll_codes')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| Leadership gets this page and no other part of Settings
|--------------------------------------------------------------------------
*/

test('leadership sees settings in the menu with only this page in it', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $navigation = app(Navigation::class);
    $settings = $navigation->center('settings');

    expect(collect($navigation->centersFor($leader))->pluck('id'))->toContain('settings');
    expect($navigation->landingFor($leader, $settings)?->id)->toBe('settings.lists');

    $visible = collect($settings->children)
        ->filter(fn ($section) => $navigation->allows($leader, $section))
        ->pluck('id')
        ->all();

    expect($visible)->toBe(['settings.lists']);
});

test('the settings address takes leadership to this page', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)->get(route('settings.home'))->assertRedirect(route('settings.lists'));
});

test('leadership is still refused every other settings page', function (string $route) {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)->get(route($route))->assertForbidden();
})->with(['settings.locations', 'settings.users']);

test('leadership is shown no other settings page, built or not', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)
        ->get(route('settings.lists'))
        ->assertOk()
        ->assertDontSee('Users and Access')
        ->assertDontSee('Audit Log')
        ->assertDontSee('Service Areas')
        ->assertDontSee('Integrations');
});

test('an owner still opens settings on locations and sees this page beside it', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('settings.home'))->assertRedirect(route('settings.locations'));

    $this->actingAs($owner)->get(route('settings.locations'))->assertSee('Lead & Sales Lists', escape: true);
});

/*
|--------------------------------------------------------------------------
| The approved starting values
|--------------------------------------------------------------------------
*/

test('each list starts with the approved values in the approved order', function (ManagedList $list, array $expected) {
    expect(namesIn($list))->toBe($expected);
    expect($list->modelClass()::query()->where('is_active', false)->count())->toBe(0);
})->with([
    'marketing sources' => [ManagedList::MarketingSources, [
        'Google Search / Organic', 'Google Ads', 'Facebook / Instagram', 'Previous Customer', 'Referral',
        'Yard Sign', 'Vehicle / Trailer', 'Door Hanger', 'Direct Mail', 'Door-to-Door Canvassing',
        'Event / Community', 'Radio', 'TV / Streaming', 'Other', 'Unknown / Unattributed',
    ]],
    'lead sources' => [ManagedList::LeadSources, [
        'Google Business Profile', 'Google Search / Website', 'Facebook / Instagram', 'Website — Direct',
        'Previous Customer', 'Referral', 'Yard Sign', 'Vehicle / Trailer', 'Door Hanger', 'Direct Mail',
        'Door-to-Door Canvassing', 'Event / Community', 'Radio', 'TV / Streaming', 'Other', 'Unknown',
    ]],
    'lead channels' => [ManagedList::LeadChannels, [
        'Phone Call', 'Web Form', 'Meta Form', 'Text Message', 'Email', 'Facebook / Instagram Message',
        'In Person', 'Other',
    ]],
    'disqualification reasons' => [ManagedList::DisqualificationReasons, [
        'Outside Service Area', 'Service Not Offered', 'Project Too Small / Below Minimum',
        'Not Authorized to Make Decision', 'Invalid Contact Information', 'Unable to Contact',
        'Duplicate Lead', 'Spam / Solicitation', 'Other',
    ]],
    'sales loss reasons' => [ManagedList::SalesLossReasons, [
        'Price', 'Chose Another Contractor', 'Customer Decided Not to Proceed', 'Project Postponed / Cancelled',
        'Timing / Scheduling', 'Scope / Solution Not a Fit', 'Financing / Payment',
        'Unable to Re-Engage / No Decision', 'Other',
    ]],
]);

test('each disqualification reason starts in its approved reporting category', function () {
    $categories = LeadDisqualificationReason::query()
        ->ordered()
        ->get()
        ->mapWithKeys(fn (LeadDisqualificationReason $reason) => [$reason->name => $reason->category])
        ->all();

    expect($categories)->toBe([
        'Outside Service Area' => DisqualificationCategory::Serviceability,
        'Service Not Offered' => DisqualificationCategory::Serviceability,
        'Project Too Small / Below Minimum' => DisqualificationCategory::Serviceability,
        'Not Authorized to Make Decision' => DisqualificationCategory::LeadQuality,
        'Invalid Contact Information' => DisqualificationCategory::Invalid,
        'Unable to Contact' => DisqualificationCategory::LeadQuality,
        'Duplicate Lead' => DisqualificationCategory::Invalid,
        'Spam / Solicitation' => DisqualificationCategory::Invalid,
        'Other' => DisqualificationCategory::Other,
    ]);
});

test('only "other" requires a note, and only "chose another contractor" asks for a competitor', function () {
    expect(LeadDisqualificationReason::query()->where('requires_note', true)->pluck('name')->all())->toBe(['Other']);
    expect(SalesLossReason::query()->where('requires_note', true)->pluck('name')->all())->toBe(['Other']);
    expect(SalesLossReason::query()->where('asks_for_competitor', true)->pluck('name')->all())->toBe(['Chose Another Contractor']);
});

test('future timing is not a disqualification reason and sales loss reasons are not mixed in', function () {
    $disqualification = namesIn(ManagedList::DisqualificationReasons);

    expect($disqualification)->not->toContain('Timing / Scheduling');
    expect($disqualification)->not->toContain('Price');
    expect($disqualification)->not->toContain('Chose Another Contractor');
});

/*
|--------------------------------------------------------------------------
| Adding
|--------------------------------------------------------------------------
*/

test('an option can be added to the end of any list', function (ManagedList $list) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $before = namesIn($list);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', $list->value)
        ->set('name', '  Billboard  ')
        ->set('category', DisqualificationCategory::LeadQuality->value)
        ->call('add')
        ->assertHasNoErrors()
        ->assertSet('name', '');

    expect(namesIn($list))->toBe([...$before, 'Billboard']);
    expect($list->modelClass()::query()->where('name', 'Billboard')->sole()->is_active)->toBeTrue();
})->with('lists');

test('leadership can add an option', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    Livewire::actingAs($leader)
        ->test('pages::settings.lists')
        ->set('name', 'Billboard')
        ->call('add')
        ->assertHasNoErrors();

    expect(MarketingSource::query()->where('name', 'Billboard')->exists())->toBeTrue();
});

test('an added option belongs to the list being shown and no other', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->set('name', 'Carrier Pigeon')
        ->call('add')
        ->assertHasNoErrors();

    expect(LeadChannel::query()->where('name', 'Carrier Pigeon')->exists())->toBeTrue();
    expect(MarketingSource::query()->where('name', 'Carrier Pigeon')->exists())->toBeFalse();
    expect(LeadSource::query()->where('name', 'Carrier Pigeon')->exists())->toBeFalse();
});

test('an option name is required, limited in length and unique within its list', function (string $name, string $message) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $count = MarketingSource::query()->count();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->set('name', $name)
        ->call('add')
        ->assertHasErrors(['name' => $message]);

    expect(MarketingSource::query()->count())->toBe($count);
})->with([
    'blank' => ['   ', 'The name field is required.'],
    'too long' => [str_repeat('a', 101), 'The name field must not be greater than 100 characters.'],
    'already in the list' => ['Referral', 'The name has already been taken.'],
]);

test('the same name may be used in two different lists', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->set('name', 'Referral')
        ->call('add')
        ->assertHasNoErrors();

    expect(LeadChannel::query()->where('name', 'Referral')->exists())->toBeTrue();
});

test('a disqualification reason needs a valid reporting category', function (string $category) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->set('name', 'Wrong Number')
        ->set('category', $category)
        ->call('add')
        ->assertHasErrors('category');

    expect(LeadDisqualificationReason::query()->where('name', 'Wrong Number')->exists())->toBeFalse();
})->with([
    'none chosen' => [''],
    'not a category' => ['sales_loss'],
]);

test('a new disqualification reason is stored with the category chosen for it', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->set('name', 'Wrong Number')
        ->set('category', DisqualificationCategory::Invalid->value)
        ->call('add')
        ->assertHasNoErrors();

    $reason = LeadDisqualificationReason::query()->where('name', 'Wrong Number')->sole();

    expect($reason->category)->toBe(DisqualificationCategory::Invalid);
    expect($reason->requires_note)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Renaming and reclassifying
|--------------------------------------------------------------------------
*/

test('an option can be renamed without changing its place or its identity', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $option = MarketingSource::query()->where('name', 'Radio')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('edit', $option->id)
        ->assertSet('editingName', 'Radio')
        ->set('editingName', '  Radio / Podcast  ')
        ->call('update')
        ->assertHasNoErrors();

    $renamed = $option->fresh();

    expect($renamed->name)->toBe('Radio / Podcast');
    expect($renamed->position)->toBe($option->position);
    expect(MarketingSource::query()->where('name', 'Radio')->exists())->toBeFalse();
});

test('an option cannot be renamed to a name already in its list, but may keep its own', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $option = MarketingSource::query()->where('name', 'Radio')->sole();

    $page = Livewire::actingAs($owner)->test('pages::settings.lists');

    $page->call('edit', $option->id)
        ->set('editingName', 'Referral')
        ->call('update')
        ->assertHasErrors(['editingName' => 'The name has already been taken.']);

    expect($option->fresh()->name)->toBe('Radio');

    $page->call('edit', $option->id)->call('update')->assertHasNoErrors();
});

test('the reporting category of a disqualification reason can be changed', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $reason = LeadDisqualificationReason::query()->where('name', 'Unable to Contact')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::DisqualificationReasons->value)
        ->call('edit', $reason->id)
        ->assertSet('editingCategory', DisqualificationCategory::LeadQuality->value)
        ->set('editingCategory', DisqualificationCategory::Invalid->value)
        ->call('update')
        ->assertHasNoErrors();

    expect($reason->fresh()->category)->toBe(DisqualificationCategory::Invalid);
    expect($reason->fresh()->name)->toBe('Unable to Contact');
});

/*
|--------------------------------------------------------------------------
| Reordering
|--------------------------------------------------------------------------
*/

test('an option can be moved up and down, one place at a time', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $email = LeadChannel::query()->where('name', 'Email')->sole();

    $page = Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value);

    $page->call('moveUp', $email->id)->assertHasNoErrors();

    expect(namesIn(ManagedList::LeadChannels))->toBe([
        'Phone Call', 'Web Form', 'Meta Form', 'Email', 'Text Message', 'Facebook / Instagram Message', 'In Person', 'Other',
    ]);

    $page->call('moveDown', $email->id)->call('moveDown', $email->id);

    expect(namesIn(ManagedList::LeadChannels))->toBe([
        'Phone Call', 'Web Form', 'Meta Form', 'Text Message', 'Facebook / Instagram Message', 'Email', 'In Person', 'Other',
    ]);

    expect(LeadChannel::query()->ordered()->pluck('position')->all())->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
});

test('the first option cannot move up and the last cannot move down', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $before = namesIn(ManagedList::LeadChannels);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('moveUp', LeadChannel::query()->where('name', 'Phone Call')->sole()->id)
        ->call('moveDown', LeadChannel::query()->where('name', 'Other')->sole()->id)
        ->assertHasNoErrors();

    expect(namesIn(ManagedList::LeadChannels))->toBe($before);
});

test('reordering one list leaves the others alone', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $marketing = namesIn(ManagedList::MarketingSources);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('moveUp', LeadChannel::query()->where('name', 'Email')->sole()->id);

    expect(namesIn(ManagedList::MarketingSources))->toBe($marketing);
});

/*
|--------------------------------------------------------------------------
| Deactivating: never deleted
|--------------------------------------------------------------------------
*/

test('a deactivated option is kept but no longer offered, and can be reactivated', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $radio = MarketingSource::query()->where('name', 'Radio')->sole();
    $count = MarketingSource::query()->count();

    $page = Livewire::actingAs($owner)->test('pages::settings.lists');

    $page->call('deactivate', $radio->id)->assertHasNoErrors();

    expect(MarketingSource::query()->count())->toBe($count);
    expect($radio->fresh()->is_active)->toBeFalse();
    expect(MarketingSource::query()->find($radio->id)?->name)->toBe('Radio');
    expect(namesIn(ManagedList::MarketingSources, activeOnly: true))->not->toContain('Radio');
    expect(namesIn(ManagedList::MarketingSources))->toContain('Radio');

    $page->call('reactivate', $radio->id)->assertHasNoErrors();

    expect($radio->fresh()->is_active)->toBeTrue();
    expect(namesIn(ManagedList::MarketingSources, activeOnly: true))->toContain('Radio');
});

test('a deactivated option keeps its place in the order', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $before = namesIn(ManagedList::MarketingSources);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('deactivate', MarketingSource::query()->where('name', 'Radio')->sole()->id);

    expect(namesIn(ManagedList::MarketingSources))->toBe($before);
});

test('the last active option in a list cannot be deactivated', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    LeadChannel::query()->where('name', '!=', 'Phone Call')->update(['is_active' => false]);

    $phone = LeadChannel::query()->where('name', 'Phone Call')->sole();

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('deactivate', $phone->id)
        ->assertHasErrors(['option' => 'This is the only active lead channel. Add or reactivate another before deactivating it.']);

    expect($phone->fresh()->is_active)->toBeTrue();
});

test('a list option cannot be deleted', function (ManagedList $list) {
    $option = $list->modelClass()::query()->firstOrFail();

    expect(fn () => $option->delete())
        ->toThrow(LogicException::class, 'A list option is deactivated, never deleted, so records that use it keep their history.');

    expect($list->modelClass()::query()->whereKey($option->id)->exists())->toBeTrue();
})->with('lists');

/*
|--------------------------------------------------------------------------
| One list's page never reaches another list's options
|--------------------------------------------------------------------------
*/

test('an option from another list cannot be changed through the list being shown', function (string $action) {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    // An ID that exists among sales loss reasons but not among lead channels.
    $reason = SalesLossReason::factory()->create(['name' => 'Left Alone']);
    LeadChannel::query()->whereKey($reason->id)->exists() && $this->fail('Expected an ID with no matching lead channel.');

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call($action, $reason->id)
        ->assertNotFound();

    $untouched = $reason->fresh();

    expect($untouched->name)->toBe('Left Alone');
    expect($untouched->is_active)->toBeTrue();
    expect($untouched->position)->toBe($reason->position);
})->with(['edit', 'moveUp', 'moveDown', 'deactivate', 'reactivate']);

test('an ID that exists in two lists only ever changes the list being shown', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $channel = LeadChannel::query()->where('name', 'Meta Form')->sole();
    $reason = SalesLossReason::query()->findOrFail($channel->id);

    Livewire::actingAs($owner)
        ->test('pages::settings.lists')
        ->call('show', ManagedList::LeadChannels->value)
        ->call('edit', $channel->id)
        ->set('editingName', 'Meta Lead Form')
        ->call('update')
        ->call('moveUp', $channel->id)
        ->call('deactivate', $channel->id)
        ->assertHasNoErrors();

    expect($channel->fresh()->only(['name', 'position', 'is_active']))
        ->toBe(['name' => 'Meta Lead Form', 'position' => $channel->position - 1, 'is_active' => false]);

    expect($reason->fresh()->only(['name', 'position', 'is_active']))
        ->toBe($reason->only(['name', 'position', 'is_active']));
});

/*
|--------------------------------------------------------------------------
| Every action re-checks permission
|--------------------------------------------------------------------------
*/

test('list actions are refused once the permission has been removed', function (string $action, array $arguments) {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $radio = MarketingSource::query()->where('name', 'Radio')->sole();
    $before = MarketingSource::query()->ordered()->get(['name', 'position', 'is_active'])->toArray();

    $page = Livewire::actingAs($leader)->test('pages::settings.lists');

    $leader->accessGrants()->delete();
    $leader->refresh();

    $page->set('name', 'Billboard')
        ->call($action, ...array_map(fn ($argument) => $argument === ':radio' ? $radio->id : $argument, $arguments))
        ->assertForbidden();

    expect(MarketingSource::query()->ordered()->get(['name', 'position', 'is_active'])->toArray())->toBe($before);
})->with([
    'add' => ['add', []],
    'edit' => ['edit', [':radio']],
    'update' => ['update', []],
    'move up' => ['moveUp', [':radio']],
    'move down' => ['moveDown', [':radio']],
    'deactivate' => ['deactivate', [':radio']],
    'reactivate' => ['reactivate', [':radio']],
    'show another list' => ['show', ['lead_channels']],
]);
