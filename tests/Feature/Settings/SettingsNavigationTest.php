<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Shell;

test('users and access keeps company context by default', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee('aria-label="Company"', escape: false)
        ->assertSee('aria-label="Settings"', escape: false)
        ->assertSee('aria-current="page"', escape: false)
        ->assertSessionHas(Shell::LAST_CENTER_KEY, 'company');
});

test('users and access keeps finance context when the user is authorized for both', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->withSession([Shell::LAST_CENTER_KEY => 'finance'])
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee('aria-label="Finance Center"', escape: false)
        ->assertSee('People &amp; Access', escape: false)
        ->assertSee('aria-current="page"', escape: false)
        ->assertSessionHas(Shell::LAST_CENTER_KEY, 'finance');
});

test('the legacy settings context maps to company', function () {
    Location::factory()->create();

    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)
        ->withSession([Shell::LAST_CENTER_KEY => 'settings'])
        ->get(route('settings.users'))
        ->assertOk()
        ->assertSee('aria-label="Company"', escape: false)
        ->assertSessionHas(Shell::LAST_CENTER_KEY, 'company');
});

test('the finance role does not gain people and access', function () {
    $finance = User::factory()->withRoleAt(Role::Finance, Location::factory()->create())->create();

    $this->actingAs($finance)
        ->get(route('finance.performance'))
        ->assertOk()
        ->assertDontSee('People &amp; Access', escape: false);

    $this->get(route('settings.users'))->assertForbidden();
});
