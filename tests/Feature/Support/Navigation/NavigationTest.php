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
    return collect(app(Navigation::class)->centers())
        ->flatMap(fn (NavigationItem $center) => $center->flatten())
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
    $primaryIds = collect(app(Navigation::class)->centers())
        ->map(fn (NavigationItem $center) => $center->primaryChild()?->id)
        ->all();

    $unbuilt = collect(allNavigationItems())
        ->reject->isLive()
        ->reject(fn (NavigationItem $item) => in_array($item->id, $primaryIds, true));

    expect($unbuilt)->not->toBeEmpty();

    $unbuilt->each(function (NavigationItem $item) {
        expect($item->route)->toBeNull("Unbuilt item [{$item->id}] must not have a route.");
    });
});

test('every center opens on a section that has a page', function () {
    collect(app(Navigation::class)->centers())->each(function (NavigationItem $center) {
        expect($center->primaryChild()?->isReachable())->toBeTrue("Center [{$center->id}] has no page to open on.");
    });
});

test('each center opens on its designated primary section', function (string $center, string $primary) {
    expect(app(Navigation::class)->center($center)->primaryChild()->id)->toBe($primary);
})->with([
    'company overview' => ['company', 'company.performance'],
    'marketing' => ['marketing', 'marketing.performance'],
    'sales' => ['sales', 'sales.performance'],
    'production' => ['production', 'production.performance'],
    'finance' => ['finance', 'finance.performance'],
    'color studio' => ['color_studio', 'color_studio.projects'],
    'settings' => ['settings', 'settings.locations'],
]);

test('a section with sub-sections opens on its designated primary sub-section', function (string $section, string $primary) {
    expect(app(Navigation::class)->find($section)->primaryChild()->id)->toBe($primary);
})->with([
    'call center' => ['marketing.call_center', 'marketing.call_center.inbox'],
    'marketing reporting' => ['marketing.reporting', 'marketing.reporting.lead_sources'],
    'production projects' => ['production.projects', 'production.projects.ready'],
    'cash management' => ['finance.cash', 'finance.cash.receivables'],
]);

test('a staff member is offered only the centers their role allows', function (Role $role, array $expected) {
    $location = Location::factory()->create();

    $user = User::factory()->withRoleAt($role, $location)->create();

    $centers = collect(app(Navigation::class)->centersFor($user))->pluck('id')->all();

    expect($centers)->toBe($expected);
})->with([
    'leadership at one location' => [Role::Leadership, ['marketing', 'sales', 'production', 'color_studio']],
    'finance' => [Role::Finance, ['finance']],
    'location manager' => [Role::LocationManager, ['marketing', 'sales', 'production', 'color_studio']],
    'call center agent' => [Role::CallCenterAgent, ['marketing']],
    'sales consultant' => [Role::SalesConsultant, ['sales', 'color_studio']],
    'production manager' => [Role::ProductionManager, ['production']],
]);

test('an owner is offered every center', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $centers = collect(app(Navigation::class)->centersFor($owner))->pluck('id')->all();

    expect($centers)->toBe(['company', 'marketing', 'sales', 'production', 'finance', 'color_studio', 'settings']);
});

test('a staff member with no grants is offered no centers', function () {
    Location::factory()->create();

    $user = User::factory()->create();

    expect(app(Navigation::class)->centersFor($user))->toBe([]);
});

test('a route is matched to the center that owns it', function () {
    $navigation = app(Navigation::class);

    expect($navigation->centerForRoute('sales.home')?->id)->toBe('sales');
    expect($navigation->centerForRoute('sales.performance')?->id)->toBe('sales');
    expect($navigation->centerForRoute('settings.users.show')?->id)->toBe('settings');
    expect($navigation->centerForRoute('dashboard'))->toBeNull();
    expect($navigation->centerForRoute(null))->toBeNull();
});
