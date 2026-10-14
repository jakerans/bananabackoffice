<?php

use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Enums\SubcontractorStanding;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\Service;
use App\Models\Subcontractor;
use App\Models\User;
use App\Support\Customers\CustomerActivity;
use App\Support\Navigation\Navigation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Subcontractors: the roster and recruiting, step 12a
 * (docs/slice-12-subcontractors-plan.md; product-architecture.md, 7, 7.1, 7.3
 * and 7.11; decision 148).
 *
 * Pat Production works at Lakeview; Hal at Harbor.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->hal = User::factory()->withRoleAt(Role::ProductionManager, $this->harbor)->create(['name' => 'Hal Harbor']);

    $this->exterior = Service::factory()->create(['name' => 'Whole-house exteriors', 'position' => 90]);
    $this->cabinets = Service::factory()->create(['name' => 'Cabinet refinishing', 'position' => 91]);
});

/**
 * A subcontractor as a person types them, unless told otherwise.
 *
 * @param  array<string, mixed>  $with
 * @return array<string, mixed>
 */
function subcontractorDetails($test, array $with = []): array
{
    return [
        'businessName' => 'Sandia Brush Co.',
        'contactName' => 'Rosa Alvarez',
        'phone' => '505-555-0142',
        'email' => 'Rosa@Example.com',
        'crewSize' => '4',
        'homeTown' => 'Rio Rancho',
        'homeZip' => '87124',
        'serviceIds' => [(string) $test->exterior->id],
        'locationIds' => [(string) $test->lakeview->id],
        ...$with,
    ];
}

function addedSubcontractor($test, array $with = [], ?User $by = null): Subcontractor
{
    return app(ChangeSubcontractor::class)->add(subcontractorDetails($test, $with), $by ?? $test->pat);
}

/**
 * Move a subcontractor along until they are Active.
 */
function activeSubcontractor($test): Subcontractor
{
    $subcontractor = addedSubcontractor($test);

    return app(ChangeSubcontractor::class)->moveTo($subcontractor, 'active', null, $test->pat);
}

function subcontractorsPage(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test('pages::production.subcontractors');
}

function subcontractorPage(User $user, Subcontractor $subcontractor): Testable
{
    return Livewire::actingAs($user)->test('pages::production.subcontractor', ['subcontractor' => $subcontractor]);
}

/*
|--------------------------------------------------------------------------
| Adding and changing
|--------------------------------------------------------------------------
*/

test('a subcontractor is added as a prospect with their details, Locations and services', function () {
    $subcontractor = addedSubcontractor($this);

    expect($subcontractor->only(['business_name', 'contact_name', 'phone', 'phone_normalized', 'email', 'crew_size', 'home_town', 'home_zip', 'is_vip', 'created_by', 'created_by_name']))->toBe([
        'business_name' => 'Sandia Brush Co.',
        'contact_name' => 'Rosa Alvarez',
        'phone' => '505-555-0142',
        'phone_normalized' => '+15055550142',
        'email' => 'rosa@example.com',
        'crew_size' => 4,
        'home_town' => 'Rio Rancho',
        'home_zip' => '87124',
        'is_vip' => false,
        'created_by' => $this->pat->id,
        'created_by_name' => 'Pat Production',
    ]);
    expect($subcontractor->standing)->toBe(SubcontractorStanding::Prospect);
    expect($subcontractor->locations->pluck('name')->all())->toBe(['Lakeview']);
    expect($subcontractor->services->pluck('name')->all())->toBe(['Whole-house exteriors']);
    expect($subcontractor->phoneForDisplay())->toBe('(505) 555-0142');
    expect($subcontractor->homeBase())->toBe('Rio Rancho 87124');

    $added = AuditEvent::query()->where('action', AuditAction::SubcontractorAdded)->sole();

    expect($added->subject_type)->toBe('subcontractor');
    expect($added->values_after)->toMatchArray(['business_name' => 'Sandia Brush Co.', 'locations' => ['Lakeview'], 'services' => ['Whole-house exteriors']]);
    expect(CustomerActivity::actions())->not->toContain(AuditAction::SubcontractorAdded);

    // Only a name, a person and one way to reach them are needed at first.
    $bare = addedSubcontractor($this, ['businessName' => 'Mesa Coats', 'phone' => '', 'crewSize' => '', 'homeTown' => '', 'homeZip' => '', 'serviceIds' => []]);

    expect($bare->only(['phone', 'crew_size', 'home_town', 'home_zip']))->toBe(['phone' => null, 'crew_size' => null, 'home_town' => null, 'home_zip' => null]);
    expect($bare->homeBase())->toBeNull();
});

test('details that cannot be kept are refused at each field, and nothing is saved', function (array $with, string $field) {
    try {
        addedSubcontractor($this, $with);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect(array_keys($refused->errors()))->toBe([$field]);
    }

    expect(Subcontractor::query()->count())->toBe(0);
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorAdded)->count())->toBe(0);
})->with([
    'no business name' => [['businessName' => ' '], 'details.businessName'],
    'a business name too long' => [['businessName' => str_repeat('a', 121)], 'details.businessName'],
    'no person to talk to' => [['contactName' => ''], 'details.contactName'],
    'no way to reach them' => [['phone' => '', 'email' => ''], 'details.phone'],
    'an email that is not one' => [['email' => 'rosa at example'], 'details.email'],
    'a crew of none' => [['crewSize' => '0'], 'details.crewSize'],
    'a crew that is not a number' => [['crewSize' => 'four'], 'details.crewSize'],
    'too large a crew' => [['crewSize' => '201'], 'details.crewSize'],
    'a ZIP that is not one' => [['homeZip' => '8712'], 'details.homeZip'],
    'no Location' => [['locationIds' => []], 'details.locationIds'],
]);

test('a Location the person does not look after, or a service no longer offered, cannot be chosen', function () {
    expect(fn () => addedSubcontractor($this, ['locationIds' => [(string) $this->lakeview->id, (string) $this->harbor->id]]))
        ->toThrow(ValidationException::class, 'Choose only Locations where you look after subcontractors.');

    $this->cabinets->forceFill(['is_active' => false])->save();

    expect(fn () => addedSubcontractor($this, ['serviceIds' => [(string) $this->cabinets->id]]))
        ->toThrow(ValidationException::class, 'Choose services from the list.');

    // The Owner looks after every Location.
    expect(addedSubcontractor($this, ['locationIds' => [(string) $this->lakeview->id, (string) $this->harbor->id]], $this->owner)->locations->pluck('name')->all())->toBe(['Harbor', 'Lakeview']);
});

test('details are changed, the audit record keeps only what changed, and a Location the person does not look after stays', function () {
    $subcontractor = addedSubcontractor($this, ['locationIds' => [(string) $this->lakeview->id, (string) $this->harbor->id]], $this->owner);

    app(ChangeSubcontractor::class)->change($subcontractor, subcontractorDetails($this, ['crewSize' => '6', 'locationIds' => [(string) $this->lakeview->id]]), $this->pat);

    $subcontractor->refresh();

    expect($subcontractor->crew_size)->toBe(6);
    // Pat does not look after Harbor, so it stays.
    expect($subcontractor->locations->pluck('name')->all())->toBe(['Harbor', 'Lakeview']);

    $changed = AuditEvent::query()->where('action', AuditAction::SubcontractorChanged)->sole();

    expect($changed->values_before)->toEqual(['crew_size' => 4]);
    expect($changed->values_after)->toEqual(['crew_size' => 6]);

    // Saved unchanged, nothing is recorded.
    app(ChangeSubcontractor::class)->change($subcontractor, subcontractorDetails($this, ['crewSize' => '6']), $this->pat);

    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorChanged)->count())->toBe(1);
});

test('a subcontractor is never deleted', function () {
    expect(fn () => addedSubcontractor($this)->delete())->toThrow(LogicException::class);
});

/*
|--------------------------------------------------------------------------
| Where they stand, and VIP
|--------------------------------------------------------------------------
*/

test('a subcontractor moves through recruiting to the roster, each step kept', function () {
    $subcontractor = addedSubcontractor($this);

    foreach (['screened', 'interview_scheduled', 'interviewed', 'approved', 'onboarding_sent', 'active'] as $standing) {
        $subcontractor = app(ChangeSubcontractor::class)->moveTo($subcontractor, $standing, null, $this->pat);
    }

    expect($subcontractor->standing)->toBe(SubcontractorStanding::Active);
    expect($subcontractor->standing->group())->toBe('roster');
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorStandingChanged)->count())->toBe(6);
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorStandingChanged)->latest('id')->first()->only(['values_before', 'values_after']))
        ->toEqual(['values_before' => ['standing' => 'onboarding_sent'], 'values_after' => ['standing' => 'active']]);

    expect(fn () => app(ChangeSubcontractor::class)->moveTo($subcontractor, 'active', null, $this->pat))->toThrow(ValidationException::class, 'They are already active.');
    expect(fn () => app(ChangeSubcontractor::class)->moveTo($subcontractor, 'retired', null, $this->pat))->toThrow(ValidationException::class, 'Choose where they stand now.');
});

test('benching, offboarding and not hiring need a reason, and so does bringing one back', function (string $from, string $to) {
    $subcontractor = addedSubcontractor($this);
    $subcontractor->forceFill(['standing' => $from])->save();

    try {
        app(ChangeSubcontractor::class)->moveTo($subcontractor, $to, '  ', $this->pat);
        $this->fail('It was not refused.');
    } catch (ValidationException $refused) {
        expect($refused->errors())->toBe(['standingReason' => ['Say why.']]);
    }

    expect($subcontractor->fresh()->standing->value)->toBe($from);

    app(ChangeSubcontractor::class)->moveTo($subcontractor, $to, 'Missed two start dates.', $this->pat);

    expect($subcontractor->fresh()->standing->value)->toBe($to);
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorStandingChanged)->sole()->reason)->toBe('Missed two start dates.');
})->with([
    'benched' => ['active', 'benched'],
    'unbenched' => ['benched', 'active'],
    'offboarded' => ['active', 'offboarded'],
    'not hired' => ['interviewed', 'not_hired'],
    'brought back after not being hired' => ['not_hired', 'prospect'],
]);

test('VIP is set and taken off with a reason, only on an Active subcontractor, and goes when they leave Active', function () {
    $prospect = addedSubcontractor($this);

    expect(fn () => app(ChangeSubcontractor::class)->setVip($prospect, true, 'Great work.', $this->pat))
        ->toThrow(ValidationException::class, 'Only an Active subcontractor can be VIP.');

    $subcontractor = activeSubcontractor($this);

    expect(fn () => app(ChangeSubcontractor::class)->setVip($subcontractor, true, '', $this->pat))->toThrow(ValidationException::class, 'Say why.');

    app(ChangeSubcontractor::class)->setVip($subcontractor, true, 'Five clean jobs this year.', $this->pat);

    expect($subcontractor->fresh()->is_vip)->toBeTrue();
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorVipSet)->sole()->only(['reason', 'values_after']))
        ->toEqual(['reason' => 'Five clean jobs this year.', 'values_after' => ['vip' => true]]);

    app(ChangeSubcontractor::class)->moveTo($subcontractor->fresh(), 'benched', 'Insurance lapsed.', $this->pat);

    expect($subcontractor->fresh()->is_vip)->toBeFalse();
    expect(AuditEvent::query()->where('action', AuditAction::SubcontractorStandingChanged)->latest('id')->first()->values_after)->toEqual(['standing' => 'benched', 'vip' => false]);
});

/*
|--------------------------------------------------------------------------
| Who may
|--------------------------------------------------------------------------
*/

test('who may see and change a subcontractor', function (Closure $person, bool $may) {
    $subcontractor = activeSubcontractor($this);
    $person = $person($this);

    expect($person->can('view', $subcontractor->fresh()))->toBe($may);
    expect($person->can('update', $subcontractor->fresh()))->toBe($may);

    if (! $may) {
        foreach ([
            fn () => app(ChangeSubcontractor::class)->change($subcontractor, subcontractorDetails($this, ['crewSize' => '9']), $person),
            fn () => app(ChangeSubcontractor::class)->moveTo($subcontractor, 'benched', 'No.', $person),
            fn () => app(ChangeSubcontractor::class)->setVip($subcontractor, true, 'No.', $person),
        ] as $refused) {
            expect($refused)->toThrow(AuthorizationException::class);
        }

        // Adding one here is refused too: as unauthorized, or, for someone who looks after another Location, as a Location they may not choose.
        try {
            app(ChangeSubcontractor::class)->add(subcontractorDetails($this), $person);
            $this->fail('Adding was not refused.');
        } catch (AuthorizationException|ValidationException) {
            expect(Subcontractor::query()->count())->toBe(1);
        }
        expect($subcontractor->fresh()->only(['crew_size', 'is_vip']))->toBe(['crew_size' => 4, 'is_vip' => false]);
        expect($subcontractor->fresh()->standing)->toBe(SubcontractorStanding::Active);
    }
})->with([
    'a Production Manager there' => [fn ($test) => $test->pat, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true],
    'Leadership there' => [fn ($test) => User::factory()->withRoleAt(Role::Leadership, $test->lakeview)->create(), true],
    'the Owner' => [fn ($test) => $test->owner, true],
    'a Production Manager elsewhere' => [fn ($test) => $test->hal, false],
    'a Sales Consultant there' => [fn ($test) => User::factory()->withRoleAt(Role::SalesConsultant, $test->lakeview)->create(), false],
    'a Call Center Agent there' => [fn ($test) => User::factory()->withRoleAt(Role::CallCenterAgent, $test->lakeview)->create(), false],
    'Finance' => [fn ($test) => User::factory()->withRoleEverywhere(Role::Finance)->create(), false],
]);

/*
|--------------------------------------------------------------------------
| The pages
|--------------------------------------------------------------------------
*/

test('subcontractors is a live page in the production center, refused to those who may not see them', function (Role $role) {
    expect(app(Navigation::class)->find('production.crews')->isLive())->toBeTrue();

    $this->actingAs($this->pat)->get(route('production.subcontractors'))->assertOk()->assertSee('data-test="production-subcontractors"', false);

    $person = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($person)->get(route('production.subcontractors'))->assertForbidden();
    $this->actingAs($person)->get(route('production.subcontractors.show', activeSubcontractor($this)))->assertForbidden();
})->with([Role::SalesConsultant, Role::CallCenterAgent, Role::Finance]);

test('the list shows the Location\'s subcontractors by where they stand, and finds them by name', function () {
    activeSubcontractor($this);
    addedSubcontractor($this, ['businessName' => 'Mesa Coats', 'contactName' => 'Lee Park']);
    $gone = addedSubcontractor($this, ['businessName' => 'Old Town Painters']);
    app(ChangeSubcontractor::class)->moveTo($gone, 'not_hired', 'Wanted day rates.', $this->pat);
    addedSubcontractor($this, ['businessName' => 'Harbor Hues', 'locationIds' => [(string) $this->harbor->id]], $this->hal);

    $page = subcontractorsPage($this->pat)->assertSee('The crews who work at Lakeview')->assertDontSee('Harbor Hues');
    $list = fn (string $group) => (string) str($page->html())->after('data-test="subcontractors-'.$group.'"')->before('class="flex flex-col gap-4 rounded-xl')->stripTags()->squish();

    expect($list('roster'))->toContain('Sandia Brush Co.')->toContain('Rosa Alvarez')->toContain('(505) 555-0142')->toContain('crew of 4 · Whole-house exteriors · Rio Rancho 87124')->toContain('Active');
    expect($list('recruiting'))->toContain('Mesa Coats')->toContain('Prospect')->not->toContain('Sandia');
    expect($list('past'))->toContain('Old Town Painters')->toContain('Not hired');

    $page->set('search', 'lee')->assertSee('Mesa Coats')->assertDontSee('Sandia Brush Co.');

    // Asking for a Location they may not see shows their own.
    subcontractorsPage($this->pat, ['location' => $this->harbor->id])->assertSee('The crews who work at Lakeview')->assertDontSee('Harbor Hues');
});

test('a subcontractor is added from the list and opens on their page', function () {
    subcontractorsPage($this->pat)
        ->assertSeeHtml('data-test="add-subcontractor"')
        ->call('openAdd')
        ->assertSet('details.locationIds', [(string) $this->lakeview->id])
        ->set('details.businessName', 'Sandia Brush Co.')
        ->call('add')
        ->assertHasErrors(['details.contactName', 'details.phone'])
        ->set('details.contactName', 'Rosa Alvarez')
        ->set('details.email', 'rosa@example.com')
        ->call('add')
        ->assertHasNoErrors()
        ->assertRedirect(route('production.subcontractors.show', Subcontractor::query()->sole()));
});

test('the page shows who they are and their history, and changes where they stand and VIP', function () {
    $subcontractor = activeSubcontractor($this);

    subcontractorPage($this->pat, $subcontractor)
        ->assertSee('Sandia Brush Co.')
        ->assertSee('4 people')
        ->assertSee('Rio Rancho 87124')
        ->assertSeeInOrder(['History', 'Prospect to Active', 'Added as a prospect'])
        ->call('openVip')
        ->call('saveVip')
        ->assertHasErrors(['vipReason'])
        ->set('vipReason', 'Five clean jobs this year.')
        ->call('saveVip')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="subcontractor-vip"')
        ->assertSee('Made VIP')
        ->call('openStanding')
        ->set('standing', 'benched')
        ->assertSee('Why (required)')
        ->call('saveStanding')
        ->assertHasErrors(['standingReason'])
        ->set('standingReason', 'Insurance lapsed.')
        ->call('saveStanding')
        ->assertHasNoErrors()
        ->assertSee('Active to Benched (VIP taken off)')
        ->assertSee('Insurance lapsed.')
        ->assertDontSeeHtml('data-test="change-vip"')
        ->call('openEdit')
        ->assertSet('details.crewSize', '4')
        ->set('details.crewSize', '5')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSee('Details changed: crew size');
});

test('someone at another Location cannot open the page, and a manager there is offered the changes', function () {
    $subcontractor = activeSubcontractor($this);

    subcontractorPage($this->hal, $subcontractor)->assertForbidden();

    // A Location Manager there reads it and is offered the same as the Production Manager.
    subcontractorPage($this->manager, $subcontractor)->assertSeeHtml('data-test="change-standing"');
});
