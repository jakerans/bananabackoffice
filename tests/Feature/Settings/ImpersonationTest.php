<?php

use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Actions\Staff\StartImpersonation;
use App\Enums\AuditAction;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\LeadChannel;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Access\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Livewire\Drawer\Utils;
use Livewire\Livewire;

/**
 * Sign in as the Owner and start viewing Backoffice as a staff member, the way
 * the Owner does it: from the staff member's page in Settings.
 */
function impersonate(User $owner, User $staff): void
{
    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $staff])
        ->call('impersonate')
        ->assertRedirect(route('dashboard'));
}

function owner(string $name = 'Olive Owner'): User
{
    return User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => $name]);
}

/**
 * The state of the Livewire page in a response, as the browser holds it.
 *
 * @return array<string, mixed>
 */
function livewireSnapshot(TestResponse $response): array
{
    return Utils::extractAttributeDataFromHtml((string) $response->getContent(), 'wire:snapshot');
}

/**
 * Send a Livewire action the way a browser does: a real request through every
 * middleware. Livewire's own test helper skips middleware, and impersonation is
 * applied by middleware, so these tests must not use it once impersonating.
 *
 * @param  array<string, mixed>  $snapshot
 * @param  array<int, mixed>  $params
 * @param  array<string, mixed>  $updates
 */
function sendLivewireAction(array $snapshot, string $method, array $params = [], array $updates = []): TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(app('livewire')->getUpdateUri(), [
        'components' => [[
            'snapshot' => json_encode($snapshot),
            'updates' => $updates,
            'calls' => [['method' => $method, 'params' => $params, 'path' => '']],
        ]],
    ]);
}

/*
|--------------------------------------------------------------------------
| Who may start, and as whom
|--------------------------------------------------------------------------
*/

test('an owner can start viewing backoffice as an active staff member', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $consultant])
        ->assertSeeHtml('data-test="impersonate"')
        ->call('impersonate')
        ->assertRedirect(route('dashboard'));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Viewing Backoffice as Roseanne Smith')
        ->assertSee('Return to Olive Owner');
});

test('no other role can start impersonation, from the page or by calling it', function (Role $role) {
    $lakeview = Location::factory()->create();
    $target = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $user = User::factory()->withRoleEverywhere($role)->create();

    // None of them can open the staff member's page at all.
    $this->actingAs($user)->get(route('settings.users.show', $target))->assertForbidden();

    Livewire::actingAs($user)->test('pages::settings.user', ['user' => $target])->assertForbidden();

    expect(fn () => app(StartImpersonation::class)->handle($user, $target))->toThrow(AuthorizationException::class);

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
    expect(AuditEvent::query()->count())->toBe(0);
})->with([
    'leadership' => Role::Leadership,
    'finance' => Role::Finance,
    'location manager' => Role::LocationManager,
    'call center agent' => Role::CallCenterAgent,
    'sales consultant' => Role::SalesConsultant,
    'production manager' => Role::ProductionManager,
]);

test('another role that also holds owner may start impersonation', function () {
    $lakeview = Location::factory()->create();
    $target = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->withRoleEverywhere(Role::Finance)->create();

    impersonate($owner, $target);

    $this->get(route('dashboard'))->assertSee('Viewing Backoffice as Roseanne Smith');
});

test('an owner cannot impersonate themselves, a deactivated person, or someone who has not accepted their invitation', function (Closure $target) {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $target = $target($owner, $lakeview);

    Livewire::actingAs($owner)
        ->test('pages::settings.user', ['user' => $target])
        ->assertDontSeeHtml('data-test="impersonate"')
        ->call('impersonate')
        ->assertForbidden();

    expect(fn () => app(StartImpersonation::class)->handle($owner, $target))->toThrow(AuthorizationException::class);

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
    expect(AuditEvent::query()->count())->toBe(0);

    $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Viewing Backoffice as');
})->with([
    'themselves' => [fn (User $owner) => $owner],
    'a deactivated person' => [fn (User $owner, Location $lakeview) => User::factory()->inactive()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
    'someone still invited' => [fn (User $owner, Location $lakeview) => User::factory()->invited()->withRoleAt(Role::SalesConsultant, $lakeview)->create()],
]);

test('starting impersonation never touches the staff member\'s password or sign-in details', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['password' => Hash::make('their-own-passphrase'), 'remember_token' => 'their-remember-token']);

    $before = $consultant->fresh()->getAttributes();

    impersonate($owner, $consultant);

    $this->get(route('dashboard'))->assertOk();
    $this->post(route('impersonation.stop'));

    expect($consultant->fresh()->getAttributes())->toBe($before);
});

test('the session gets a new id on entering and on leaving, and stays the owner\'s', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $this->actingAs($owner)->get(route('dashboard'));

    $before = session()->getId();

    impersonate($owner, $consultant);

    $during = session()->getId();

    expect($during)->not->toBe($before);
    expect(session(Impersonation::SESSION_KEY))->toBe(['impersonator_id' => $owner->id, 'staff_id' => $consultant->id]);

    $this->get(route('dashboard'))->assertOk();

    // Once the response is ready the signed-in person is the Owner again: the session never becomes the staff member's.
    expect(Auth::user()->is($owner))->toBeTrue();

    $this->post(route('impersonation.stop'));

    expect(session()->getId())->not->toBe($during);
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Seeing exactly what the staff member sees
|--------------------------------------------------------------------------
*/

test('while impersonating, navigation and direct urls follow the staff member\'s access, not the owner\'s', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    $own = Lead::factory()->at($lakeview)->assignedTo($consultant)->create(['inquiry_name' => 'Lakeview Lena']);
    $elsewhere = Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    $unrouted = Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    impersonate($owner, $consultant);

    // The menus are the consultant's: no Settings, Company or Finance.
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Sales')
        ->assertDontSee('Settings')
        ->assertDontSee('Finance');

    // Pages only an Owner may open are refused, by address.
    foreach (['settings.locations', 'settings.users', 'settings.lists', 'company.home', 'finance.home', 'marketing.home'] as $route) {
        $this->get(route($route))->assertForbidden();
    }

    $this->get(route('settings.users.show', $consultant))->assertForbidden();

    // Leads follow the consultant's location.
    $this->get(route('leads.show', $own))->assertOk()->assertSee('Lakeview Lena')->assertSee('Viewing Backoffice as Roseanne Smith');
    $this->get(route('leads.show', $elsewhere))->assertForbidden();
    $this->get(route('leads.show', $unrouted))->assertForbidden();
});

test('while impersonating, livewire pages and actions use the staff member\'s permissions', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $unassigned = Lead::factory()->at($lakeview)->create(['inquiry_name' => 'Unassigned Una']);
    Lead::factory()->at($northside)->create(['inquiry_name' => 'Northside Nora']);
    Lead::factory()->create(['inquiry_name' => 'Unrouted Una']);

    impersonate($owner, $consultant);

    // The list is a Sales Consultant's: their location only, and only their views.
    $this->get(route('leads', ['view' => 'all']))
        ->assertOk()
        ->assertSee('Unassigned Una')
        ->assertDontSee('Northside Nora')
        ->assertDontSee('Unrouted Una')
        ->assertDontSee('data-test="unrouted-tab"', false)
        ->assertDontSee('data-test="unassigned-tab"', false);

    // The lead page offers nothing an Owner could do and a Sales Consultant cannot...
    $page = $this->get(route('leads.show', $unassigned))
        ->assertOk()
        ->assertDontSee('data-test="assign-lead"', false)
        ->assertDontSee('data-test="route-lead"', false)
        ->assertDontSee('data-test="start-working"', false);

    // ...and calling those actions anyway is refused.
    foreach (['openAssign', 'openRouting', 'openReturn', 'startWorking', 'assign', 'route', 'returnToCentral', 'unassign'] as $action) {
        sendLivewireAction(livewireSnapshot($page), $action)->assertForbidden();
    }

    expect($unassigned->fresh()->status)->toBe(LeadStatus::New);
    expect($unassigned->fresh()->isAssigned())->toBeFalse();
    expect($unassigned->fresh()->location->is($lakeview))->toBeTrue();
});

test('a page the owner opened as themselves cannot be used with their own permissions once impersonating', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);

    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->create();

    // Open in the Owner's browser, as the Owner, before impersonating.
    $this->actingAs($owner);

    $locations = livewireSnapshot($this->get(route('settings.locations'))->assertOk());
    $leadPage = livewireSnapshot($this->get(route('leads.show', $lead))->assertOk());

    // As themselves, these work.
    sendLivewireAction($leadPage, 'openAssign')->assertOk();

    impersonate($owner, $consultant);

    sendLivewireAction($locations, 'create', updates: ['name' => 'Sneaky Branch'])->assertForbidden();
    sendLivewireAction($locations, 'deactivate', [$lakeview->id])->assertForbidden();
    sendLivewireAction($leadPage, 'openAssign')->assertForbidden();
    sendLivewireAction($leadPage, 'startWorking')->assertForbidden();

    expect(Location::query()->where('name', 'Sneaky Branch')->exists())->toBeFalse();
    expect($lakeview->fresh()->is_active)->toBeTrue();
    expect($lead->fresh()->isAssigned())->toBeFalse();
});

test('while impersonating, the location choice is limited to the staff member\'s locations', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $northside = Location::factory()->create(['name' => 'Northside']);

    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // The Owner had been working in "All Locations".
    $this->actingAs($owner)->post(route('location.switch'), ['location' => CurrentLocation::ALL_LOCATIONS]);

    impersonate($owner, $consultant);

    $this->get(route('leads'))->assertOk();

    expect(session(CurrentLocation::SESSION_KEY))->toBe($lakeview->id);

    $this->post(route('location.switch'), ['location' => (string) $northside->id])->assertForbidden();
    $this->post(route('location.switch'), ['location' => CurrentLocation::ALL_LOCATIONS])->assertForbidden();

    expect(session(CurrentLocation::SESSION_KEY))->toBe($lakeview->id);
});

test('impersonating someone with no access shows what they would see: nothing', function () {
    Location::factory()->create();

    $owner = owner();
    $nobody = User::factory()->create(['name' => 'Noah Noaccess']);

    impersonate($owner, $nobody);

    $this->get(route('dashboard'))->assertOk()->assertSee('Viewing Backoffice as Noah Noaccess')->assertDontSee('Settings');
    $this->get(route('leads'))->assertForbidden();
    $this->get(route('settings.users'))->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Stopping, and signing out
|--------------------------------------------------------------------------
*/

test('returning restores the owner at once and lands on the staff member\'s page', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    impersonate($owner, $consultant);

    $this->get(route('settings.users'))->assertForbidden();

    $this->post(route('impersonation.stop'))->assertRedirect(route('settings.users.show', $consultant));

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    $this->get(route('settings.users'))->assertOk()->assertDontSee('Viewing Backoffice as');
    $this->get(route('settings.locations'))->assertOk();
});

test('pressing return when not impersonating does nothing', function () {
    Location::factory()->create();

    $this->actingAs(owner())->post(route('impersonation.stop'))->assertRedirect(route('dashboard'));

    expect(AuditEvent::query()->count())->toBe(0);
});

test('a guest cannot use the return address', function () {
    $this->post(route('impersonation.stop'))->assertRedirect(route('login'));
});

test('signing out while impersonating ends the whole session, leaving no owner session behind', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['remember_token' => 'their-remember-token']);

    impersonate($owner, $consultant);

    $this->post(route('logout'))->assertRedirect();

    $this->assertGuest();

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
    // Signing out was the Owner's act: the staff member's own sign-ins elsewhere are untouched.
    expect($consultant->fresh()->remember_token)->toBe('their-remember-token');

    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('settings.users'))->assertRedirect(route('login'));
});

test('impersonation cannot be started again while already impersonating', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $otherOwner = owner('Otto Owner');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $this->actingAs($owner);

    $consultantPage = livewireSnapshot($this->get(route('settings.users.show', $consultant))->assertOk());

    // Viewing as another Owner, whose own role would otherwise allow it.
    impersonate($owner, $otherOwner);

    $this->get(route('settings.users.show', $consultant))->assertForbidden();

    sendLivewireAction($consultantPage, 'impersonate')->assertForbidden();

    expect(fn () => app(StartImpersonation::class)->handle($owner, $consultant))->toThrow(AuthorizationException::class);

    expect(session(Impersonation::SESSION_KEY))->toBe(['impersonator_id' => $owner->id, 'staff_id' => $otherOwner->id]);
    expect(AuditEvent::query()->where('action', AuditAction::StaffImpersonationStarted)->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Changes to either person while impersonating
|--------------------------------------------------------------------------
*/

test('deactivating the staff member ends impersonation on the next request', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    impersonate($owner, $consultant);

    $consultant->forceFill(['is_active' => false])->save();

    $this->get(route('leads'))->assertRedirect(route('dashboard'));

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Roseanne Smith can no longer be viewed as, so you are back as yourself.')
        ->assertDontSee('Viewing Backoffice as');

    // The Owner is themselves again.
    $this->get(route('settings.users'))->assertOk();
});

test('a livewire action is not carried out as the owner when the staff member has just been deactivated', function () {
    Location::factory()->create();

    $owner = owner();
    // Another Owner, so the page and the action would both be allowed to the real Owner.
    $otherOwner = owner('Otto Owner');

    impersonate($owner, $otherOwner);

    $locations = livewireSnapshot($this->get(route('settings.locations'))->assertOk());

    $otherOwner->forceFill(['is_active' => false])->save();

    // The page is told it has expired, which reloads it as the Owner. The action does not run.
    sendLivewireAction($locations, 'create', updates: ['name' => 'Sneaky Branch'])->assertStatus(419);

    expect(Location::query()->where('name', 'Sneaky Branch')->exists())->toBeFalse();
    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});

test('removing the staff member\'s access takes effect on the next request', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    impersonate($owner, $consultant);

    $this->get(route('leads.show', $lead))->assertOk();

    $consultant->accessGrants()->delete();

    $this->get(route('leads.show', $lead))->assertForbidden();
    $this->get(route('leads'))->assertForbidden();

    // Still impersonating: this is exactly what the staff member now sees.
    $this->get(route('dashboard'))->assertOk()->assertSee('Viewing Backoffice as Roseanne Smith');
});

test('an owner who is deactivated mid-session is signed out, not left acting as the staff member', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    owner('Otto Owner');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    impersonate($owner, $consultant);

    $owner->forceFill(['is_active' => false])->save();

    $this->get(route('leads.show', $lead))->assertRedirect(route('login'));

    $this->assertGuest();

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();
});

test('an owner who loses the owner role mid-session is returned to their own, reduced, access', function () {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->withRoleAt(Role::ProductionManager, $lakeview)->create(['name' => 'Olive Owner']);
    $otherOwner = owner('Otto Owner');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    impersonate($owner, $consultant);

    $this->get(route('leads.show', $lead))->assertOk();

    $this->actingAs($otherOwner);
    app(RevokeAccess::class)->handle($owner->accessGrants()->where('role', Role::Owner)->sole());
    $this->actingAs($owner->fresh());

    // Not served as the consultant any more, and not as an Owner either.
    $this->get(route('leads.show', $lead))->assertRedirect(route('dashboard'));

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    $this->get(route('dashboard'))->assertSee('You are no longer an Owner, so you are back as yourself.');
    $this->get(route('leads.show', $lead))->assertForbidden();
    $this->get(route('settings.users'))->assertForbidden();
});

test('what one session remembers means nothing in someone else\'s session', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $lakeview)->create();
    $otherOwner = owner('Otto Owner');

    // A session that claims an impersonation its own signed-in person did not start.
    $this->actingAs($manager)
        ->withSession([Impersonation::SESSION_KEY => ['impersonator_id' => $owner->id, 'staff_id' => $otherOwner->id]])
        ->get(route('settings.users'))
        ->assertForbidden();

    expect(session()->has(Impersonation::SESSION_KEY))->toBeFalse();

    $this->get(route('dashboard'))->assertOk()->assertDontSee('Viewing Backoffice as');
});

/*
|--------------------------------------------------------------------------
| The staff member's sign-in details, and staff administration, are closed
|--------------------------------------------------------------------------
*/

test('account and security pages are closed while impersonating', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    // Recently confirmed as the Owner, which must not open the staff member's security page.
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

    $this->get(route('security.edit'))->assertOk();

    impersonate($owner, $consultant);

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();

    $this->get(route('profile.edit'))
        ->assertForbidden()
        ->assertSee('Viewing Backoffice as Roseanne Smith')
        ->assertSee('closed until you return to yourself');

    // The security page asks for a password first, and that page is closed too.
    $this->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))->assertForbidden();

    // Even with a confirmation forced into the session, the page itself refuses.
    $this->withSession(['auth.password_confirmed_at' => time()])->get(route('security.edit'))->assertForbidden();

    $this->get(route('dashboard'))->assertDontSee('My account');
});

test('an account page opened before impersonating cannot change the staff member\'s details', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith', 'email' => 'roseanne@example.com']);

    // Open in the Owner's browser, as the Owner, before impersonating.
    $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);

    $profile = livewireSnapshot($this->get(route('profile.edit'))->assertOk());
    $security = livewireSnapshot($this->get(route('security.edit'))->assertOk());

    $before = $consultant->fresh()->getAttributes();

    impersonate($owner, $consultant);

    sendLivewireAction($profile, 'updateProfileInformation', updates: ['name' => 'Changed Name', 'email' => 'changed@example.com'])->assertForbidden();
    sendLivewireAction($profile, 'resendVerificationNotification')->assertForbidden();
    sendLivewireAction($security, 'updatePassword', updates: ['current_password' => 'password', 'password' => 'a-new-passphrase', 'password_confirmation' => 'a-new-passphrase'])->assertForbidden();
    sendLivewireAction($security, 'disable')->assertForbidden();
    sendLivewireAction($security, 'deletePasskey')->assertForbidden();

    // Neither person's details changed.
    expect($consultant->fresh()->getAttributes())->toBe($before);
    expect($owner->fresh()->name)->toBe('Olive Owner');
});

test('the two-factor setup and recovery code panels are closed while impersonating', function (string $component, array $params, string $action) {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // The panel as it sits in the Owner's own open security page.
    $panel = Livewire::actingAs($owner)->test($component, $params)->snapshot;

    $before = $consultant->fresh()->getAttributes();

    impersonate($owner, $consultant);

    sendLivewireAction($panel, $action)->assertForbidden();

    expect($consultant->fresh()->getAttributes())->toBe($before);
})->with([
    'two-factor setup' => ['pages::settings.two-factor-setup-modal', ['requiresConfirmation' => true], 'startTwoFactorSetup'],
    'recovery codes' => ['pages::settings.two-factor.recovery-codes', [], 'regenerateRecoveryCodes'],
]);

test('the sign-in packages\' own account addresses are refused while impersonating', function (string $method, string $uri) {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    $before = $consultant->fresh()->getAttributes();

    impersonate($owner, $consultant);

    $this->call($method, $uri, ['password' => 'password', 'code' => '123456', 'name' => 'A passkey'])->assertForbidden();

    expect($consultant->fresh()->getAttributes())->toBe($before);
    expect($consultant->passkeys()->count())->toBe(0);
})->with([
    'confirm their password' => ['POST', 'user/confirm-password'],
    'password confirmation page' => ['GET', 'user/confirm-password'],
    'enable two-factor' => ['POST', 'user/two-factor-authentication'],
    'disable two-factor' => ['DELETE', 'user/two-factor-authentication'],
    'confirm two-factor' => ['POST', 'user/confirmed-two-factor-authentication'],
    'see the two-factor secret' => ['GET', 'user/two-factor-secret-key'],
    'see the two-factor qr code' => ['GET', 'user/two-factor-qr-code'],
    'see recovery codes' => ['GET', 'user/two-factor-recovery-codes'],
    'regenerate recovery codes' => ['POST', 'user/two-factor-recovery-codes'],
    'passkey registration options' => ['GET', 'user/passkeys/options'],
    'add a passkey' => ['POST', 'user/passkeys'],
    'remove a passkey' => ['DELETE', 'user/passkeys/1'],
    'confirm with a passkey' => ['POST', 'passkeys/confirm'],
    'resend email verification' => ['POST', 'email/verification-notification'],
]);

test('those account addresses still work for the owner as themselves', function () {
    Location::factory()->create();

    $this->actingAs(owner())->get('user/confirm-password')->assertOk();
});

test('staff accounts and access cannot be managed while impersonating, even as another owner', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $otherOwner = owner('Otto Owner');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    // Open in the Owner's browser, as the Owner, before impersonating.
    $this->actingAs($owner);

    $usersPage = livewireSnapshot($this->get(route('settings.users'))->assertOk());
    $otherOwnerPage = livewireSnapshot($this->get(route('settings.users.show', $otherOwner))->assertOk());
    $consultantPage = livewireSnapshot($this->get(route('settings.users.show', $consultant))->assertOk());

    impersonate($owner, $otherOwner);

    $this->get(route('settings.users'))->assertForbidden();
    $this->get(route('settings.users.show', $otherOwner))->assertForbidden();
    $this->get(route('settings.users.show', $consultant))->assertForbidden();

    // The impersonated person's own access, someone else's, and inviting: all refused.
    sendLivewireAction($otherOwnerPage, 'grant', updates: ['role' => Role::Finance->value, 'scope' => 'all'])->assertForbidden();
    sendLivewireAction($otherOwnerPage, 'revoke', [$otherOwner->accessGrants()->sole()->id])->assertForbidden();
    sendLivewireAction($consultantPage, 'revoke', [$consultant->accessGrants()->sole()->id])->assertForbidden();
    sendLivewireAction($consultantPage, 'deactivate')->assertForbidden();
    sendLivewireAction($usersPage, 'invite', updates: ['name' => 'New Person', 'email' => 'new.person@example.com'])->assertForbidden();

    expect($otherOwner->accessGrants()->count())->toBe(1);
    expect($consultant->accessGrants()->count())->toBe(1);
    expect($consultant->fresh()->isActive())->toBeTrue();
    expect(User::query()->where('email', 'new.person@example.com')->exists())->toBeFalse();

    // Other Owner-only work is still open: this is what the other Owner sees.
    $this->get(route('settings.locations'))->assertOk();
});

/*
|--------------------------------------------------------------------------
| Audit
|--------------------------------------------------------------------------
*/

test('starting and stopping impersonation are recorded in the owner\'s own name', function () {
    $lakeview = Location::factory()->create();
    $owner = owner('Jacob Ransom');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    impersonate($owner, $consultant);

    $started = AuditEvent::query()->sole();

    expect($started->action)->toBe(AuditAction::StaffImpersonationStarted);
    expect($started->actor->is($owner))->toBeTrue();
    expect($started->subject->is($consultant))->toBeTrue();
    expect($started->wasImpersonated())->toBeFalse();
    expect($started->madeBy())->toBe('Jacob Ransom');

    $this->post(route('impersonation.stop'));

    $stopped = AuditEvent::query()->where('action', AuditAction::StaffImpersonationStopped)->sole();

    expect($stopped->actor->is($owner))->toBeTrue();
    expect($stopped->subject->is($consultant))->toBeTrue();
    expect($stopped->wasImpersonated())->toBeFalse();
    expect($stopped->after())->toBe(['cause' => 'returned']);
});

test('each other way impersonation ends is recorded with its cause', function (Closure $end, string $cause) {
    $lakeview = Location::factory()->create();
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->withRoleAt(Role::ProductionManager, $lakeview)->create();
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    impersonate($owner, $consultant);

    $end($this, $owner, $consultant);

    $stopped = AuditEvent::query()->where('action', AuditAction::StaffImpersonationStopped)->sole();

    expect($stopped->actor->is($owner))->toBeTrue();
    expect($stopped->subject->is($consultant))->toBeTrue();
    expect($stopped->after())->toBe(['cause' => $cause]);
})->with([
    'signing out' => [fn ($test) => $test->post(route('logout')), 'signed_out'],
    'the staff member is deactivated' => [function ($test, User $owner, User $consultant) {
        $consultant->forceFill(['is_active' => false])->save();
        $test->get(route('dashboard'));
    }, 'staff_member_unavailable'],
    'the owner is no longer an owner' => [function ($test, User $owner) {
        $owner->accessGrants()->where('role', Role::Owner)->delete();
        $test->actingAs($owner->fresh())->get(route('dashboard'));
    }, 'owner_no_longer_authorized'],
]);

test('a change made while impersonating records the staff identity and the real owner', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = owner('Jacob Ransom');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);
    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    impersonate($owner, $consultant);

    $page = $this->get(route('leads.show', $lead))->assertOk();

    sendLivewireAction(livewireSnapshot($page), 'startWorking')->assertOk();

    expect($lead->fresh()->status)->toBe(LeadStatus::Working);

    $event = AuditEvent::query()->where('action', AuditAction::LeadWorkStarted)->sole();

    expect($event->actor->is($consultant))->toBeTrue();
    expect($event->actor_name)->toBe('Roseanne Smith');
    expect($event->impersonator->is($owner))->toBeTrue();
    expect($event->impersonator_name)->toBe('Jacob Ransom');
    expect($event->wasImpersonated())->toBeTrue();
    expect($event->madeBy())->toBe('Jacob Ransom, impersonating Roseanne Smith');
    expect($event->subject->is($lead))->toBeTrue();
});

test('every event of an impersonated action names the owner, including ones recorded for a named actor', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = owner('Jacob Ransom');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create(['name' => 'Roseanne Smith']);

    impersonate($owner, $consultant);

    // Recording a lead as the consultant: the lead is received, and assigned to them.
    $form = $this->get(route('leads.create'))->assertOk();

    sendLivewireAction(livewireSnapshot($form), 'save', updates: [
        'projectSizeId' => projectSizeId(),
        'serviceIds' => oneService(),
        'name' => 'Rita Referral',
        'channelId' => LeadChannel::query()->where('name', 'Phone Call')->sole()->id,
    ])->assertOk();

    $lead = Lead::query()->sole();

    // The lead belongs to the identity it was recorded as.
    expect($lead->capturedBy->is($consultant))->toBeTrue();
    expect($lead->assignee->is($consultant))->toBeTrue();

    $events = AuditEvent::query()->whereIn('action', [AuditAction::LeadReceived, AuditAction::LeadAssigned])->get();

    expect($events)->toHaveCount(2);

    foreach ($events as $event) {
        expect($event->actor->is($consultant))->toBeTrue();
        expect($event->impersonator->is($owner))->toBeTrue();
        expect($event->madeBy())->toBe('Jacob Ransom, impersonating Roseanne Smith');
    }
});

test('changes made before and after impersonating name nobody but the person who made them', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $owner = owner('Jacob Ransom');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    impersonate($owner, $consultant);

    $this->get(route('dashboard'));
    $this->post(route('impersonation.stop'));

    Livewire::actingAs($owner)->test('pages::settings.locations')->set('name', 'Northside')->call('create')->assertHasNoErrors();

    $event = AuditEvent::query()->where('action', AuditAction::LocationCreated)->sole();

    expect($event->actor->is($owner))->toBeTrue();
    expect($event->wasImpersonated())->toBeFalse();
    expect($event->madeBy())->toBe('Jacob Ransom');

    // And the staff member's own changes, signed in as themselves, are theirs alone.
    $this->post(route('logout'));

    $lead = Lead::factory()->at($lakeview)->assignedTo($consultant)->create();

    Livewire::actingAs($consultant)->test('pages::leads.show', ['lead' => $lead])->call('startWorking')->assertHasNoErrors();

    expect(AuditEvent::query()->where('action', AuditAction::LeadWorkStarted)->sole()->wasImpersonated())->toBeFalse();
});

test('an owner deactivated through the normal action cannot continue an impersonation', function () {
    $lakeview = Location::factory()->create();
    $owner = owner();
    $otherOwner = owner('Otto Owner');
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, $lakeview)->create();

    impersonate($owner, $consultant);

    Auth::setUser($otherOwner);
    app(DeactivateStaffMember::class)->handle($owner);
    Auth::setUser($owner->fresh());

    $this->get(route('dashboard'))->assertRedirect(route('login'));

    $this->assertGuest();
});
