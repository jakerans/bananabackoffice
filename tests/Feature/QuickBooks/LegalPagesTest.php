<?php

use App\Enums\Role;
use App\Models\User;

/*
 * The public terms and privacy pages Intuit asks every QuickBooks app for
 * (decision 201).
 */

test('anyone can read the terms and the privacy policy without signing in', function () {
    $this->get(route('legal.terms'))->assertOk()
        ->assertSee('Terms of Use')
        ->assertSee('never creates, changes or deletes anything in QuickBooks');

    $this->get(route('legal.privacy'))->assertOk()
        ->assertSee('Privacy Policy')
        ->assertSee('Nothing is written back to QuickBooks.');

    $this->actingAs(User::factory()->withRoleEverywhere(Role::Finance)->create())
        ->get(route('legal.privacy'))->assertOk();
});
