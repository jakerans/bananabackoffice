<?php

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

function allowOnlyNavigationPermission(User $user, Permission $permission): void
{
    Gate::before(function (User $authenticatedUser, mixed $ability) use ($user, $permission): ?bool {
        $abilityName = $ability instanceof Permission ? $ability->value : $ability;

        return $authenticatedUser->is($user) && $abilityName === $permission->value ? true : null;
    });
}

test('guests are redirected to login from the settings compatibility address', function () {
    $this->get(route('settings.home'))->assertRedirect(route('login'));
});

test('settings redirects to the first existing destination the user may manage', function (Permission $permission, string $route) {
    $user = User::factory()->create();

    allowOnlyNavigationPermission($user, $permission);

    $this->actingAs($user)
        ->get(route('settings.home'))
        ->assertRedirect(route($route));
})->with([
    'locations' => [Permission::ManageLocations, 'settings.locations'],
    'users and access' => [Permission::ManageUsers, 'settings.users'],
    'lead and sales lists' => [Permission::ManageLeadSalesLists, 'settings.lists'],
]);

test('settings is forbidden when the user has none of its existing permissions', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('settings.home'))
        ->assertForbidden();
});

test('a settings-only permission enters company without granting executive board access', function () {
    $user = User::factory()->create();

    allowOnlyNavigationPermission($user, Permission::ManageUsers);

    $this->actingAs($user)
        ->get(route('company.home'))
        ->assertRedirect(route('settings.home'));

    $this->get(route('settings.home'))->assertRedirect(route('settings.users'));
    $this->get(route('company.performance'))->assertForbidden();
});
