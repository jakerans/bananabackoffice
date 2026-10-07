<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavigationItem;

test('leads is one page with one address, shown in both the marketing and sales menus', function () {
    $navigation = app(Navigation::class);

    expect(collect($navigation->destinationsForRoute('leads'))->pluck('id')->all())->toBe(['marketing', 'sales']);
    expect(collect($navigation->destinationsForRoute('leads.show'))->pluck('id')->all())->toBe(['marketing', 'sales']);

    $entries = collect($navigation->centers())
        ->flatMap(fn (NavigationItem $center) => $center->flatten())
        ->filter(fn (NavigationItem $item) => $item->id === 'shared.leads');

    expect($entries)->toHaveCount(2);
    expect($entries->pluck('route')->unique()->all())->toBe(['leads']);
    expect($navigation->find('marketing.call_center.leads'))->toBeNull();
});

test('contact center opens on leads until its workspace is built', function () {
    $agent = User::factory()->withRoleAt(Role::CallCenterAgent, Location::factory()->create())->create();

    $navigation = app(Navigation::class);
    $callCenter = $navigation->find('marketing.call_center');

    expect($navigation->find('marketing.call_center.inbox'))->toBeNull();
    expect($navigation->landingFor($agent, $callCenter)?->id)->toBe('shared.leads');

    // In the Marketing menu, Contact Center is a link that goes to Leads.
    $this->actingAs($agent)
        ->get(route('marketing.performance'))
        ->assertOk()
        ->assertSeeInOrder(['Performance', 'href="'.route('leads').'"', 'Contact Center'], escape: false);
});

test('a call center agent sees leads inside the marketing menu under contact center', function () {
    $agent = User::factory()->withRoleAt(Role::CallCenterAgent, Location::factory()->create())->create();

    $this->actingAs($agent)
        ->get(route('leads'))
        ->assertOk()
        ->assertSee('Marketing Center')
        ->assertSeeInOrder(['Contact Center', 'Inbox', 'Campaigns', 'Customers', 'Reports', 'Leads'])
        ->assertSee('Campaigns')
        ->assertDontSee('Calendar')
        ->assertDontSee('Commissions');
});

test('a sales consultant, who cannot open marketing, sees leads inside the sales menu', function () {
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    $this->actingAs($consultant)->get(route('marketing.home'))->assertForbidden();

    $this->actingAs($consultant)
        ->get(route('leads'))
        ->assertOk()
        ->assertSee('Sales Center')
        ->assertSeeInOrder(['Performance', 'Workspace', 'Calendar', 'Commissions', 'Leaderboard', 'Customers', 'Reports', 'Leads'])
        ->assertDontSee('Campaigns')
        ->assertDontSee('Contact Center');
});

test('someone with both centers sees leads in the menu of the center they were last in', function () {
    $manager = User::factory()->withRoleAt(Role::LocationManager, Location::factory()->create())->create();

    // Marketing is first, so it is used when there is nothing to go on.
    $this->actingAs($manager)->get(route('leads'))->assertOk()->assertSee('Contact Center')->assertDontSee('Calendar');

    $this->get(route('sales.performance'))->assertOk();
    $this->get(route('leads'))->assertOk()->assertSee('Calendar')->assertDontSee('Contact Center');

    $this->get(route('marketing.performance'))->assertOk();
    $this->get(route('leads.create'))->assertOk()->assertSee('Contact Center')->assertDontSee('Calendar');
});

test('the remembered center never grants access to a center', function () {
    $consultant = User::factory()->withRoleAt(Role::SalesConsultant, Location::factory()->create())->create();

    $this->actingAs($consultant)
        ->withSession(['backoffice.center' => 'marketing'])
        ->get(route('leads'))
        ->assertOk()
        ->assertSee('Sales Center')
        ->assertDontSee('Campaigns');

    $this->get(route('marketing.performance'))->assertForbidden();
});

test('leads is not offered to roles that do not work leads', function (Role $role, string $center) {
    $user = User::factory()->withRoleAt($role, Location::factory()->create())->create();

    $this->actingAs($user)
        ->get(route($center))
        ->assertOk()
        ->assertDontSee('href="'.route('leads').'"', escape: false);
})->with([
    'production manager' => [Role::ProductionManager, 'production.performance'],
    'finance' => [Role::Finance, 'finance.performance'],
]);

test('a section with no built pages stays muted and is not a link', function () {
    $manager = User::factory()->withRoleAt(Role::ProductionManager, Location::factory()->create())->create();

    $navigation = app(Navigation::class);

    expect($navigation->landingFor($manager, $navigation->find('production.workspace')))->toBeNull();

    $this->actingAs($manager)
        ->get(route('production.performance'))
        ->assertOk()
        ->assertSee('Workspace')
        ->assertDontSee('Ready to Schedule');
});
