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

test('a coming soon navigation item has no route', function () {
    $comingSoon = collect(allNavigationItems())->reject->isLive();

    expect($comingSoon)->not->toBeEmpty();

    $comingSoon->each(function (NavigationItem $item) {
        expect($item->route)->toBeNull("Coming soon item [{$item->id}] must not have a route.");
    });
});

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
    expect($navigation->centerForRoute('dashboard'))->toBeNull();
    expect($navigation->centerForRoute(null))->toBeNull();
});
