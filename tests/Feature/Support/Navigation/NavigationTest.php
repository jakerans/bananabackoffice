<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;

/**
 * @return list<NavigationItem>
 */
function allNavigationItems(): array
{
    return collect(app(Navigation::class)->destinations())
        ->flatMap(fn (NavigationItem $destination) => $destination->flatten())
        ->all();
}

test('every navigation id is unique apart from the shared items', function () {
    $ids = collect(allNavigationItems())
        ->pluck('id')
        ->reject(fn (string $id) => str_starts_with($id, 'shared.'));

    expect($ids->duplicates()->all())->toBe([]);
});

test('every live navigation item points at a route that exists', function () {
    $live = collect(allNavigationItems())->filter->isLive();

    expect($live)->not->toBeEmpty();

    $live->each(function (NavigationItem $item) {
        expect($item->route)->not->toBeNull("Live item [{$item->id}] has no route.");
        expect(Route::has($item->route))->toBeTrue("Route [{$item->route}] for [{$item->id}] is not defined.");
    });
});

test('every navigation item with a route points at a route that exists', function () {
    $routed = collect(allNavigationItems())->filter->isReachable();

    expect($routed)->not->toBeEmpty();

    $routed->each(function (NavigationItem $item) {
        expect(Route::has($item->route))->toBeTrue("Route [{$item->route}] for [{$item->id}] is not defined.");
    });
});

test('an unbuilt section has no route unless its center opens on it', function () {
    $primaryIds = collect(app(Navigation::class)->destinations())
        ->map(fn (NavigationItem $destination) => $destination->primaryChild()?->id)
        ->all();

    $unbuilt = collect(allNavigationItems())
        ->reject->isLive()
        ->reject(fn (NavigationItem $item) => in_array($item->id, $primaryIds, true));

    expect($unbuilt)->not->toBeEmpty();

    $unbuilt->each(function (NavigationItem $item) {
        expect($item->route)->toBeNull("Unbuilt item [{$item->id}] must not have a route.");
    });
});

test('every navigation destination opens on a section that has a page', function () {
    collect(app(Navigation::class)->destinations())->each(function (NavigationItem $destination) {
        expect($destination->primaryChild()?->isReachable())->toBeTrue("Destination [{$destination->id}] has no page to open on.");
    });
});

test('each center opens on its designated primary section', function (string $center, string $primary) {
    expect(app(Navigation::class)->center($center)->primaryChild()->id)->toBe($primary);
})->with([
    'company' => ['company', 'company.performance'],
    'marketing' => ['marketing', 'marketing.performance'],
    'sales' => ['sales', 'sales.performance'],
    'production' => ['production', 'production.performance'],
    'finance' => ['finance', 'finance.performance'],
]);

test('color studio remains an external destination with its existing primary section', function () {
    $navigation = app(Navigation::class);

    expect(collect($navigation->externalSurfaces())->pluck('id')->all())->toBe(['color_studio']);
    expect($navigation->destination('color_studio')?->primaryChild()?->id)->toBe('color_studio.projects');
    expect($navigation->center('color_studio'))->toBeNull();
});

test('a section with sub-sections opens on its designated primary sub-section', function (string $section, string $primary) {
    expect(app(Navigation::class)->find($section)->primaryChild()->id)->toBe($primary);
})->with([
    'contact center' => ['marketing.call_center', 'shared.leads'],
    'sales workspace' => ['sales.workspace', 'shared.leads'],
    'cash management' => ['finance.cash', 'finance.cash.receivables'],
    'settings' => ['settings', 'settings.users'],
]);

test('the five centers use the approved target labels and order', function () {
    $navigation = app(Navigation::class);

    expect(collect($navigation->centers())->map(fn (NavigationItem $center): array => [
        'id' => $center->id,
        'label' => $center->label,
    ])->all())->toBe([
        ['id' => 'company', 'label' => 'Company'],
        ['id' => 'marketing', 'label' => 'Marketing Center'],
        ['id' => 'sales', 'label' => 'Sales Center'],
        ['id' => 'production', 'label' => 'Production Center'],
        ['id' => 'finance', 'label' => 'Finance Center'],
    ]);

    expect(collect($navigation->center('company')?->children)->pluck('id')->all())->toBe([
        'company.performance',
        'company.leadership_scorecards',
        'company.needs_attention',
        'settings.locations',
        'company.reports',
        'settings',
    ]);

    expect(collect($navigation->center('marketing')?->children)->pluck('id')->all())->toBe([
        'marketing.performance',
        'marketing.call_center',
        'shared.inbox',
        'marketing.campaigns',
        'shared.customers',
        'marketing.reporting',
    ]);

    expect(collect($navigation->center('sales')?->children)->pluck('id')->all())->toBe([
        'sales.performance',
        'sales.workspace',
        'sales.calendar',
        'sales.commissions',
        'sales.leaderboard',
        'shared.customers',
        'sales.reports',
    ]);

    expect(collect($navigation->center('production')?->children)->pluck('id')->all())->toBe([
        'production.performance',
        'production.workspace',
        'production.schedule',
        'production.crews',
        'production.warranty',
        'shared.customers',
        'production.reports',
    ]);

    expect(collect($navigation->center('finance')?->children)->pluck('id')->all())->toBe([
        'finance.performance',
        'finance.cash',
        'finance.job_costing',
        'finance.payroll',
        'finance.people_access',
        'shared.customers',
        'finance.reports',
    ]);
});

test('a staff member is offered only the centers their role allows', function (Role $role, array $expected) {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleAt($role, $location)->create();

    $centers = collect(app(Navigation::class)->centersFor($user))->pluck('id')->all();

    expect($centers)->toBe($expected);
})->with([
    'leadership at one location' => [Role::Leadership, ['marketing', 'sales', 'production']],
    'finance' => [Role::Finance, ['finance']],
    'location manager' => [Role::LocationManager, ['marketing', 'sales', 'production']],
    'call center agent' => [Role::CallCenterAgent, ['marketing']],
    'sales consultant' => [Role::SalesConsultant, ['sales']],
    'production manager' => [Role::ProductionManager, ['production']],
]);

test('an owner is offered every center', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $centers = collect(app(Navigation::class)->centersFor($owner))->pluck('id')->all();

    expect($centers)->toBe(['company', 'marketing', 'sales', 'production', 'finance']);
    expect(collect(app(Navigation::class)->externalSurfacesFor($owner))->pluck('id')->all())->toBe(['color_studio']);
});

test('leadership with an all-locations grant is offered company without finance', function () {
    Location::factory()->create();

    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();
    $navigation = app(Navigation::class);

    expect(collect($navigation->centersFor($leader))->pluck('id')->all())->toBe([
        'company',
        'marketing',
        'sales',
        'production',
    ]);
});

test('external surfaces keep the existing role matrix', function (Role $role, array $expected) {
    $user = User::factory()->withRoleAt($role, Location::factory()->create())->create();

    expect(collect(app(Navigation::class)->externalSurfacesFor($user))->pluck('id')->all())->toBe($expected);
})->with([
    'leadership' => [Role::Leadership, ['color_studio']],
    'finance' => [Role::Finance, []],
    'location manager' => [Role::LocationManager, ['color_studio']],
    'call center agent' => [Role::CallCenterAgent, []],
    'sales consultant' => [Role::SalesConsultant, ['color_studio']],
    'production manager' => [Role::ProductionManager, []],
]);

test('a staff member with no grants is offered no centers', function () {
    Location::factory()->create();

    $user = User::factory()->create();

    expect(app(Navigation::class)->centersFor($user))->toBe([]);
    expect(app(Navigation::class)->externalSurfacesFor($user))->toBe([]);
});

test('a route is matched to the navigation destination that owns it', function () {
    $navigation = app(Navigation::class);

    expect($navigation->destinationForRoute('sales.home')?->id)->toBe('sales');
    expect($navigation->destinationForRoute('sales.performance')?->id)->toBe('sales');
    expect($navigation->destinationForRoute('settings.users.show')?->id)->toBe('company');
    expect($navigation->destinationForRoute('color_studio.projects')?->id)->toBe('color_studio');
    expect(collect($navigation->destinationsForRoute('settings.users'))->pluck('id')->all())->toBe(['company', 'finance']);
    expect($navigation->destinationForRoute('dashboard'))->toBeNull();
    expect($navigation->destinationForRoute(null))->toBeNull();
});
