<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;

/*
 * Settings' side menu (decision 202, point 3; decision 222): Settings' pages
 * in a menu beside the page, only those built and the person may open.
 */

beforeEach(function () {
    Location::factory()->create();
});

test('the Owner sees every built Settings page beside the page, and none still to be built', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('settings.lists'))->assertOk()
        ->assertSeeHtml('data-test="side-nav"')
        ->assertSeeInOrder(['Users and Access', 'Lead &amp; Sales Lists', 'Contact Center', 'Pricing Settings', 'Subcontractor Insurance', 'Integrations', 'Audit Log'], escape: false)
        ->assertDontSee('Service Areas')
        ->assertDontSee('Services and Products')
        ->assertDontSee('Templates');
});

test('Leadership sees only the Settings pages it may open', function () {
    $leader = User::factory()->withRoleEverywhere(Role::Leadership)->create();

    $this->actingAs($leader)->get(route('settings.lists'))->assertOk()
        ->assertSeeHtml('data-test="side-nav"')
        ->assertSee('Lead &amp; Sales Lists', escape: false)
        ->assertSee('Pricing Settings')
        ->assertDontSee('Users and Access')
        ->assertDontSee('Subcontractor Insurance')
        ->assertDontSee('Audit Log');
});

test('other Centers keep their row of pages', function () {
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();

    $this->actingAs($owner)->get(route('finance.receivables'))->assertOk()
        ->assertDontSeeHtml('data-test="side-nav"');
});
