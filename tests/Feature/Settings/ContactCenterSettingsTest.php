<?php

use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ContactCenterSettings;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Navigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function leader(): User
{
    return User::factory()->withRoleEverywhere(Role::Leadership)->create();
}

function settingsOwner(): User
{
    return User::factory()->withRoleEverywhere(Role::Owner)->create();
}

function settingsAction(): ChangeContactCenterSettings
{
    return app(ChangeContactCenterSettings::class);
}

/*
|--------------------------------------------------------------------------
| The migration
|--------------------------------------------------------------------------
*/

test('version one exists with the exact defaults and twelve attempts', function () {
    $settings = ContactCenterSettings::query()->sole();

    expect($settings->version)->toBe(1);
    expect($settings->cadence)->toBe([
        ['type' => 'timed', 'waits_minutes' => [0, 30, 90]],
        ['type' => 'spread', 'attempts' => 3],
        ['type' => 'spread', 'attempts' => 2],
        ['type' => 'spread', 'attempts' => 2],
        ['type' => 'spread', 'attempts' => 2],
    ]);
    expect($settings->totalAttempts())->toBe(12);
    expect($settings->calling_opens_at)->toBe(480);
    expect($settings->calling_closes_at)->toBe(1260);
    expect($settings->calling_days)->toBe([1, 2, 3, 4, 5, 6, 7]);
    expect($settings->hold_minutes)->toBe(5);
    expect($settings->changed_by)->toBeNull();
    expect($settings->changed_by_name)->toBeNull();
});

test('a location gets the eastern timezone unless one is given', function () {
    $location = Location::factory()->create();

    expect($location->fresh()->timezone)->toBe('America/New_York');
    expect((new Location)->timezone)->toBe('America/New_York');
});

test('a saved version can never be changed or deleted', function () {
    $settings = ContactCenterSettings::current();

    expect(fn () => $settings->forceFill(['hold_minutes' => 9])->save())->toThrow(LogicException::class);
    expect(fn () => $settings->delete())->toThrow(LogicException::class);
    expect(ContactCenterSettings::current()->hold_minutes)->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Who may open the page
|--------------------------------------------------------------------------
*/

test('guests are redirected to the login page', function () {
    $this->get(route('settings.contact_center'))->assertRedirect(route('login'));
});

test('owner and leadership with an all-locations grant open the page', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)
        ->get(route('settings.contact_center'))
        ->assertOk()
        ->assertSee('Call cadence')
        ->assertSee('Calling hours')
        ->assertSee('Calling days')
        ->assertSee('Hold length')
        ->assertSee('Changes apply to attempts scheduled from now on')
        ->assertSee('applied in each Location', escape: false);
})->with([Role::Owner, Role::Leadership]);

test('every other role is refused by address and by direct call', function (Role $role) {
    Location::factory()->create();
    $user = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($user)->get(route('settings.contact_center'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::settings.contact-center')->assertForbidden();
})->with([Role::Finance, Role::LocationManager, Role::CallCenterAgent, Role::SalesConsultant, Role::ProductionManager]);

test('leadership through a single-location grant cannot open the page', function () {
    $user = User::factory()->withRoleAt(Role::Leadership, Location::factory()->create())->create();

    $this->actingAs($user)->get(route('settings.contact_center'))->assertForbidden();

    Livewire::actingAs($user)->test('pages::settings.contact-center')->assertForbidden();
});

test('every action of the page is refused to someone who lost the permission after it opened', function (string $method) {
    $leader = leader();

    $page = Livewire::actingAs($leader)->test('pages::settings.contact-center');

    $leader->accessGrants()->delete();
    $leader->refresh();

    $page->call($method)->assertForbidden();

    expect(ContactCenterSettings::query()->count())->toBe(1);
})->with(['saveCadence', 'saveHours', 'saveDays', 'saveHold', 'saveReleaseDays', 'openResetHours', 'confirmResetHours', 'addDay', 'addDay1Attempt']);

test('the contact center permission opens only this page', function () {
    $leader = leader();

    $this->actingAs($leader)->get(route('settings.locations'))->assertForbidden();
    $this->get(route('settings.users'))->assertForbidden();

    // Held without the lists permission, the page is still the only one that opens.
    $only = User::factory()->create();
    Gate::before(fn (User $user, mixed $ability): ?bool => $user->is($only) && $ability === Permission::ManageContactCenterSettings->value ? true : null);

    $this->actingAs($only)->get(route('settings.lists'))->assertForbidden();
    $this->get(route('settings.locations'))->assertForbidden();
    $this->get(route('settings.users'))->assertForbidden();
    $this->get(route('settings.contact_center'))->assertOk();
});

test('the lists permission does not open the contact center page', function () {
    $only = User::factory()->create();
    Gate::before(fn (User $user, mixed $ability): ?bool => $user->is($only) && $ability === Permission::ManageLeadSalesLists->value ? true : null);

    $this->actingAs($only)->get(route('settings.lists'))->assertOk();
    $this->get(route('settings.contact_center'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Defaults on the page
|--------------------------------------------------------------------------
*/

test('the page shows the defaults', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->assertSet('loadedVersion', 1)
        ->assertSet('day1Waits', ['30', '90'])
        ->assertSet('laterDays', ['3', '2', '2', '2'])
        ->assertSet('opensAt', '480')
        ->assertSet('closesAt', '1260')
        ->assertSet('callingDays', ['1', '2', '3', '4', '5', '6', '7'])
        ->assertSet('holdMinutes', '5')
        ->assertSee('Total attempts: 12');
});

test('the loaded version cannot be changed from the browser', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('loadedVersion', 99);
})->throws(Exception::class, 'Cannot update locked property: [loadedVersion]');

/*
|--------------------------------------------------------------------------
| Calling hours
|--------------------------------------------------------------------------
*/

test('hours outside the limits or off the grid are refused on the page', function (string $opens, string $closes, string $field) {
    $page = Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('opensAt', $opens)
        ->set('closesAt', $closes)
        ->call('saveHours')
        ->assertHasErrors($field);

    expect(ContactCenterSettings::query()->count())->toBe(1);
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    '7:45 am' => ['465', '1260', 'opensAt'],
    '9:15 pm' => ['480', '1275', 'closesAt'],
    'closing before opening' => ['900', '600', 'closesAt'],
    'closing at opening' => ['900', '900', 'closesAt'],
    'a 45-minute window' => ['600', '645', 'closesAt'],
    'off the 15-minute grid' => ['485', '1260', 'opensAt'],
    'closing off the grid' => ['480', '1000', 'closesAt'],
    'words' => ['early', '1260', 'opensAt'],
    'a decimal' => ['480.5', '1260', 'opensAt'],
    'negative' => ['-60', '1260', 'opensAt'],
]);

test('the action refuses hours outside the limits by any route', function (mixed $opens, mixed $closes) {
    expect(fn () => settingsAction()->callingHours(leader(), 1, $opens, $closes))->toThrow(ValidationException::class);

    expect(ContactCenterSettings::query()->count())->toBe(1);
})->with([
    [465, 1260],
    [480, 1275],
    [0, 1260],
    [480, 1440],
    [600, 600],
    [600, 645],
    [480, 485],
    [[480], 1260],
    [null, 1260],
]);

test('hours narrowed inside the limits are saved as a new version and audited', function () {
    $leader = leader();

    Livewire::actingAs($leader)
        ->test('pages::settings.contact-center')
        ->set('opensAt', '540')
        ->set('closesAt', '1200')
        ->call('saveHours')
        ->assertHasNoErrors()
        ->assertSet('loadedVersion', 2);

    $current = ContactCenterSettings::current();
    expect($current->version)->toBe(2);
    expect($current->calling_opens_at)->toBe(540);
    expect($current->calling_closes_at)->toBe(1200);
    expect($current->changed_by)->toBe($leader->id);
    expect($current->changed_by_name)->toBe($leader->name);

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::ContactCenterCallingHoursChanged);
    expect($event->subject->is($current))->toBeTrue();
    expect($event->subject_type)->toBe('contact_center_settings');
    expect($event->before())->toEqualCanonicalizing(['calling_opens_at' => 480, 'calling_closes_at' => 1260]);
    expect($event->after())->toEqualCanonicalizing(['calling_opens_at' => 540, 'calling_closes_at' => 1200]);

    // The version before it is exactly as it was.
    $first = ContactCenterSettings::query()->where('version', 1)->sole();
    expect($first->calling_opens_at)->toBe(480);
    expect($first->calling_closes_at)->toBe(1260);
});

test('only the field that changed is recorded', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('closesAt', '1200')
        ->call('saveHours')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();
    expect($event->before())->toBe(['calling_closes_at' => 1260]);
    expect($event->after())->toBe(['calling_closes_at' => 1200]);
});

test('reset to default asks first, then restores the hours and is audited as a reset', function () {
    settingsAction()->callingHours(leader(), 1, 600, 1020);

    $page = Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->assertSet('opensAt', '600')
        ->call('openResetHours')
        ->assertHasNoErrors();

    // Opening the dialog changes nothing.
    expect(ContactCenterSettings::current()->version)->toBe(2);
    expect(AuditEvent::query()->count())->toBe(1);

    $page->call('confirmResetHours')->assertHasNoErrors()->assertSet('opensAt', '480')->assertSet('closesAt', '1260');

    $current = ContactCenterSettings::current();
    expect($current->version)->toBe(3);
    expect($current->calling_opens_at)->toBe(480);
    expect($current->calling_closes_at)->toBe(1260);

    $event = AuditEvent::query()->latest('id')->first();
    expect($event->action)->toBe(AuditAction::ContactCenterCallingHoursChanged);
    expect($event->after())->toMatchArray(['calling_opens_at' => 480, 'calling_closes_at' => 1260, 'reset' => true]);
    expect($event->before())->toEqualCanonicalizing(['calling_opens_at' => 600, 'calling_closes_at' => 1020]);
});

test('reset to default leaves the calling days and everything else alone', function () {
    settingsAction()->callingDays(leader(), 1, [1, 2, 3]);
    settingsAction()->callingHours(leader(), 2, 600, 1020);
    settingsAction()->holdLength(leader(), 3, 9);

    Livewire::actingAs(leader())->test('pages::settings.contact-center')->call('confirmResetHours');

    $current = ContactCenterSettings::current();
    expect($current->calling_days)->toBe([1, 2, 3]);
    expect($current->hold_minutes)->toBe(9);
    expect($current->calling_opens_at)->toBe(480);
});

test('reset to default when already default saves and records nothing', function () {
    Livewire::actingAs(leader())->test('pages::settings.contact-center')->call('confirmResetHours')->assertHasNoErrors();

    expect(ContactCenterSettings::query()->count())->toBe(1);
    expect(AuditEvent::query()->count())->toBe(0);
});

test('the reset dialog is the shared styled dialog with focus on cancel', function () {
    $this->actingAs(leader())
        ->get(route('settings.contact_center'))
        ->assertOk()
        ->assertSee('reset-calling-hours-confirmation')
        ->assertSee('data-test="confirmation-submit"', false)
        ->assertSee('autofocus', false)
        ->assertDontSee('wire:confirm', false);
});

/*
|--------------------------------------------------------------------------
| Calling days
|--------------------------------------------------------------------------
*/

test('no calling day ticked is refused', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('callingDays', [])
        ->call('saveDays')
        ->assertHasErrors('callingDays');

    expect(ContactCenterSettings::query()->count())->toBe(1);
});

test('calling days outside Monday to Sunday are refused', function (mixed $days) {
    expect(fn () => settingsAction()->callingDays(leader(), 1, $days))->toThrow(ValidationException::class);

    expect(ContactCenterSettings::query()->count())->toBe(1);
})->with([[[0]], [[8]], [['x']], [[1.5]], ['monday'], [null], [[[1]]]]);

test('calling days are saved and audited', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('callingDays', ['1', '2', '3', '4', '5'])
        ->call('saveDays')
        ->assertHasNoErrors();

    expect(ContactCenterSettings::current()->calling_days)->toBe([1, 2, 3, 4, 5]);

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::ContactCenterCallingHoursChanged);
    expect($event->before())->toBe(['calling_days' => [1, 2, 3, 4, 5, 6, 7]]);
    expect($event->after())->toBe(['calling_days' => [1, 2, 3, 4, 5]]);
});

/*
|--------------------------------------------------------------------------
| Cadence
|--------------------------------------------------------------------------
*/

/**
 * @param  list<int>  $waits
 * @param  list<int>  $later
 * @return list<array<string, mixed>>
 */
function cadenceOf(array $waits, array $later): array
{
    return [
        ['type' => 'timed', 'waits_minutes' => $waits],
        ...array_map(fn (int $attempts): array => ['type' => 'spread', 'attempts' => $attempts], $later),
    ];
}

test('a valid cadence edit writes a new version and leaves the old row unchanged', function () {
    $leader = leader();

    Livewire::actingAs($leader)
        ->test('pages::settings.contact-center')
        ->set('day1Waits', ['45'])
        ->set('laterDays', ['4', '1'])
        ->call('saveCadence')
        ->assertHasNoErrors()
        ->assertSet('loadedVersion', 2)
        ->assertSee('Total attempts: 7');

    $current = ContactCenterSettings::current();
    expect($current->version)->toBe(2);
    expect($current->cadence)->toBe(cadenceOf([0, 45], [4, 1]));
    expect($current->calling_opens_at)->toBe(480);
    expect($current->hold_minutes)->toBe(5);

    expect(ContactCenterSettings::query()->where('version', 1)->sole()->totalAttempts())->toBe(12);

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::ContactCenterCadenceChanged);
    expect($event->before()['total_attempts'])->toBe(12);
    expect($event->after()['total_attempts'])->toBe(7);
    expect($event->after()['days'])->toBe(cadenceOf([0, 45], [4, 1]));
});

test('the cadence refuses what is outside its limits', function (Closure $cadence) {
    expect(fn () => settingsAction()->cadence(leader(), 1, $cadence()))->toThrow(ValidationException::class);

    expect(ContactCenterSettings::query()->count())->toBe(1);
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'day 1 first wait not zero' => [fn () => cadenceOf([5, 30], [2])],
    'a wait under 5 minutes' => [fn () => cadenceOf([0, 4], [2])],
    'a wait over 720 minutes' => [fn () => cadenceOf([0, 721], [2])],
    'no attempts on day 1' => [fn () => cadenceOf([], [2])],
    'seven attempts on day 1' => [fn () => cadenceOf([0, 10, 10, 10, 10, 10, 10], [])],
    'no attempts on a later day' => [fn () => cadenceOf([0], [0])],
    'seven attempts on a later day' => [fn () => cadenceOf([0], [7])],
    'fifteen days' => [fn () => cadenceOf([0], array_fill(0, 14, 1))],
    'no days' => [fn () => []],
    'day 1 spread' => [fn () => [['type' => 'spread', 'attempts' => 2]]],
    'a later day timed' => [fn () => [['type' => 'timed', 'waits_minutes' => [0]], ['type' => 'timed', 'waits_minutes' => [0]]]],
    'a decimal wait' => [fn () => [['type' => 'timed', 'waits_minutes' => [0, 30.5]]]],
    'a text wait' => [fn () => [['type' => 'timed', 'waits_minutes' => [0, 'soon']]]],
    'not a list' => [fn () => 'cadence'],
]);

test('the cadence accepts its outer limits', function () {
    $widest = cadenceOf([0, 5, 720, 5, 5, 5], array_fill(0, 13, 6));

    expect(settingsAction()->cadence(leader(), 1, $widest))->toBeTrue();
    expect(ContactCenterSettings::current()->totalAttempts())->toBe(6 + 13 * 6);

    expect(settingsAction()->cadence(leader(), 2, cadenceOf([0], [])))->toBeTrue();
    expect(ContactCenterSettings::current()->totalAttempts())->toBe(1);
});

test('the page refuses a bad cadence', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('day1Waits', ['2', '90'])
        ->call('saveCadence')
        ->assertHasErrors('cadence');

    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('laterDays', ['3', '9'])
        ->call('saveCadence')
        ->assertHasErrors('cadence');

    expect(ContactCenterSettings::query()->count())->toBe(1);
});

test('days and attempts can be added and removed on the page, within the limits', function () {
    $page = Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->call('addDay')
        ->assertSet('laterDays', ['3', '2', '2', '2', '2'])
        ->call('removeDay', 0)
        ->assertSet('laterDays', ['2', '2', '2', '2'])
        ->call('addDay1Attempt')
        ->assertSet('day1Waits', ['30', '90', '30'])
        ->call('removeDay1Attempt', 1)
        ->assertSet('day1Waits', ['30', '30']);

    foreach (range(1, 20) as $ignored) {
        $page->call('addDay')->call('addDay1Attempt');
    }

    expect(count($page->get('laterDays')))->toBe(13);
    expect(count($page->get('day1Waits')))->toBe(5);
});

/*
|--------------------------------------------------------------------------
| Hold length
|--------------------------------------------------------------------------
*/

test('a hold of 0 or 31 minutes is refused', function (string $minutes) {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('holdMinutes', $minutes)
        ->call('saveHold')
        ->assertHasErrors('holdMinutes');

    expect(ContactCenterSettings::query()->count())->toBe(1);
})->with(['0', '31', '-1', '2.5', 'five', '']);

test('a hold of 1 and of 30 minutes is accepted', function () {
    expect(settingsAction()->holdLength(leader(), 1, 1))->toBeTrue();
    expect(settingsAction()->holdLength(leader(), 2, 30))->toBeTrue();
    expect(ContactCenterSettings::current()->hold_minutes)->toBe(30);
});

test('hold length is saved and audited', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('holdMinutes', '10')
        ->call('saveHold')
        ->assertHasNoErrors();

    $event = AuditEvent::query()->sole();
    expect($event->action)->toBe(AuditAction::ContactCenterHoldLengthChanged);
    expect($event->before())->toBe(['hold_minutes' => 5]);
    expect($event->after())->toBe(['hold_minutes' => 10]);
});

/*
|--------------------------------------------------------------------------
| Versions
|--------------------------------------------------------------------------
*/

test('a save that changes nothing writes no row and no audit event', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->call('saveCadence')
        ->call('saveHours')
        ->call('saveDays')
        ->call('saveHold')
        ->assertHasNoErrors()
        ->assertSet('loadedVersion', 1);

    expect(ContactCenterSettings::query()->count())->toBe(1);
    expect(AuditEvent::query()->count())->toBe(0);
});

test('each section saves only its own fields and carries the rest forward', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('holdMinutes', '12')
        ->set('opensAt', '600')
        ->call('saveHold')
        ->assertSet('loadedVersion', 2);

    // The unsaved hours edit was not saved by the hold section.
    $current = ContactCenterSettings::current();
    expect($current->hold_minutes)->toBe(12);
    expect($current->calling_opens_at)->toBe(480);
    expect(AuditEvent::query()->sole()->action)->toBe(AuditAction::ContactCenterHoldLengthChanged);
});

test('saving several sections in turn from one page counts the versions up', function () {
    Livewire::actingAs(leader())
        ->test('pages::settings.contact-center')
        ->set('holdMinutes', '12')
        ->call('saveHold')
        ->set('callingDays', ['1', '2'])
        ->call('saveDays')
        ->assertHasNoErrors()
        ->assertSet('loadedVersion', 3);

    expect(ContactCenterSettings::query()->count())->toBe(3);
    expect(ContactCenterSettings::current()->hold_minutes)->toBe(12);
    expect(ContactCenterSettings::current()->calling_days)->toBe([1, 2]);
});

test('a second save from the same loaded version is refused as stale and writes nothing', function () {
    $first = Livewire::actingAs(leader())->test('pages::settings.contact-center');
    $second = Livewire::actingAs(leader())->test('pages::settings.contact-center');

    $first->set('holdMinutes', '10')->call('saveHold')->assertHasNoErrors();

    $second->set('holdMinutes', '20')
        ->call('saveHold')
        ->assertHasErrors('version')
        // The page now shows the latest settings, not what was typed.
        ->assertSet('holdMinutes', '10')
        ->assertSet('loadedVersion', 2);

    expect(ContactCenterSettings::query()->count())->toBe(2);
    expect(ContactCenterSettings::current()->hold_minutes)->toBe(10);
    expect(AuditEvent::query()->count())->toBe(1);
});

test('the action refuses a stale version and a version from the future', function (int $loaded) {
    settingsAction()->holdLength(leader(), 1, 8);

    expect(fn () => settingsAction()->holdLength(leader(), $loaded, 9))->toThrow(ValidationException::class);

    expect(ContactCenterSettings::query()->count())->toBe(2);
    expect(ContactCenterSettings::current()->hold_minutes)->toBe(8);
    expect(AuditEvent::query()->count())->toBe(1);
})->with([1, 3, 0]);

test('the unique version index refuses two rows with one version', function () {
    $current = ContactCenterSettings::current();

    expect(fn () => $current->replicate()->forceFill(['version' => 1])->save())->toThrow(UniqueConstraintViolationException::class);
});

test('an unchanged save from a stale version is still refused', function () {
    settingsAction()->holdLength(leader(), 1, 8);

    expect(fn () => settingsAction()->holdLength(leader(), 1, 8))->toThrow(ValidationException::class);
});

test('the action refuses someone without the permission', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();

    expect(fn () => settingsAction()->holdLength($user, 1, 9))->toThrow(AuthorizationException::class);

    expect(ContactCenterSettings::query()->count())->toBe(1);
})->with([Role::LocationManager, Role::CallCenterAgent, Role::Finance]);

/*
|--------------------------------------------------------------------------
| Navigation
|--------------------------------------------------------------------------
*/

test('leadership with an all-locations grant reaches the page through company and settings', function () {
    $leader = leader();
    $navigation = app(Navigation::class);

    $settings = $navigation->find('settings');
    $item = $navigation->find('settings.contact_center');

    expect($item->isLive())->toBeTrue();
    expect($item->route)->toBe('settings.contact_center');
    expect(collect($navigation->centersFor($leader))->pluck('id'))->toContain('company');
    expect($navigation->allows($leader, $item))->toBeTrue();

    $visible = collect($settings->children)->filter(fn ($section) => $navigation->allows($leader, $section))->pluck('id')->all();
    expect($visible)->toBe(['settings.lists', 'settings.contact_center', 'settings.pricing']);

    $this->actingAs($leader)->get(route('settings.home'))->assertRedirect(route('settings.lists'));
    $this->get(route('settings.contact_center'))->assertOk()->assertSee('Contact Center');
});

test('the item follows lead and sales lists in settings', function () {
    $ids = collect(app(Navigation::class)->find('settings')->children)->pluck('id')->all();

    expect(array_search('settings.contact_center', $ids, true))->toBe(array_search('settings.lists', $ids, true) + 1);
});

test('leadership with a single-location grant does not see the item', function () {
    $user = User::factory()->withRoleAt(Role::Leadership, Location::factory()->create())->create();
    $navigation = app(Navigation::class);

    expect($navigation->allows($user, $navigation->find('settings.contact_center')))->toBeFalse();
});

test('nobody without the permission sees the item', function (Role $role) {
    $user = User::factory()->withRoleEverywhere($role)->create();
    $navigation = app(Navigation::class);

    expect($navigation->allows($user, $navigation->find('settings.contact_center')))->toBeFalse();
})->with([Role::Finance, Role::LocationManager, Role::CallCenterAgent, Role::SalesConsultant, Role::ProductionManager]);

test('an owner sees the item', function () {
    $owner = settingsOwner();
    $navigation = app(Navigation::class);

    expect($navigation->allows($owner, $navigation->find('settings.contact_center')))->toBeTrue();
    $this->actingAs($owner)->get(route('settings.home'))->assertRedirect(route('settings.locations'));
});

test('the unbuilt settings pages do not borrow the contact center permission', function () {
    $only = User::factory()->create();
    Gate::before(fn (User $user, mixed $ability): ?bool => $user->is($only) && $ability === Permission::ManageContactCenterSettings->value ? true : null);

    $navigation = app(Navigation::class);
    $visible = collect($navigation->find('settings')->children)->filter(fn ($section) => $navigation->allows($only, $section))->pluck('id')->all();

    expect($visible)->toBe(['settings.contact_center']);
});

/*
|--------------------------------------------------------------------------
| The queue permission opens one screen: the Call Queue (step 4c)
|--------------------------------------------------------------------------
|
| The engine's eligibility rule reads it (4b); the Call Queue's route and
| navigation item are gated by it (4c). Nothing else is.
|
*/

test('only the enum, the role lists, the eligibility rule and the Call Queue route and navigation item read the queue permission', function () {
    $offenders = collect(['app', 'routes', 'resources', 'config', 'bootstrap'])
        ->map(fn (string $directory) => base_path($directory))
        ->filter(fn (string $directory): bool => is_dir($directory))
        ->flatMap(fn (string $directory) => iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS))))
        ->filter(fn (SplFileInfo $file): bool => $file->isFile())
        ->filter(fn (SplFileInfo $file): bool => preg_match('/WorkLeadQueue|leads\.work_queue/', (string) file_get_contents($file->getPathname())) === 1)
        ->map(fn (SplFileInfo $file): string => str_replace(DIRECTORY_SEPARATOR, '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname())))
        ->sort()
        ->values()
        ->all();

    expect($offenders)->toBe([
        'app/Enums/Permission.php',
        'app/Enums/Role.php',
        'app/Support/Navigation/Navigation.php',
        'app/Support/Queue/DueQueue.php',
        'app/Support/Queue/QueueEligibility.php',
        'resources/views/pages/marketing/⚡call-queue.blade.php',
        'routes/web.php',
    ]);
});

test('exactly one route and one navigation item are opened by the queue permission', function () {
    $routed = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains(json_encode($route->gatherMiddleware()), 'work_queue'))
        ->map(fn ($route): string => $route->getName())
        ->values()
        ->all();

    expect($routed)->toBe(['marketing.call_center.queue']);

    $items = collect(app(Navigation::class)->destinations())
        ->flatMap(fn ($destination) => $destination->flatten())
        ->filter(fn ($item) => in_array(Permission::WorkLeadQueue, $item->permissions, true))
        ->map(fn ($item) => $item->id)
        ->unique()
        ->values()
        ->all();

    expect($items)->toBe(['marketing.call_center.queue']);
});

test('holding the queue permission opens no settings page', function () {
    $agent = User::factory()->withRoleEverywhere(Role::CallCenterAgent)->create();
    $navigation = app(Navigation::class);

    expect($navigation->allows($agent, $navigation->find('settings')))->toBeFalse();
});
