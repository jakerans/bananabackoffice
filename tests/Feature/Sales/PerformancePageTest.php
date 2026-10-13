<?php

use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Navigation\Navigation;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * Sales > Performance: who sees which figures
 * (docs/slice-7-appointment-results-plan.md, step 7d; decisions 133 and 134).
 *
 * The figures are the worked month in tests/Pest.php, octoberOfSales():
 * October 2026 at Lakeview, which ends at 10:00 am on October 12.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);
});

/**
 * The Performance page, as a staff member opens it.
 */
function performancePage(User $user, array $query = []): Testable
{
    return Livewire::actingAs($user)->withQueryParams($query)->test('pages::sales.performance');
}

/**
 * Check one figure's number and the line under it.
 */
function expectFigure(Testable $page, string $figure, string $value, string $detail): void
{
    $card = str($page->html())->after('data-test="figure-'.$figure.'"')->before('class="rounded-xl');

    expect((string) $card->after('data-test="figure-value"')->after('>')->before('<')->trim())->toBe($value);
    expect((string) $card->after('data-test="figure-detail"')->after('>')->before('</div>')->squish())->toBe($detail);
}

test('performance is a live page in the sales center', function () {
    $item = app(Navigation::class)->find('sales.performance');

    expect($item->isLive())->toBeTrue();
    expect($item->route)->toBe('sales.performance');

    $this->actingAs($this->manager)->get(route('sales.performance'))
        ->assertOk()
        ->assertSee('Performance')
        // The placeholder that stood here is gone.
        ->assertDontSee('data-test="section-scope"', false)
        ->assertSee('data-test="sales-performance"', false);
});

test('performance is refused to people without sales access, and a guest is sent to sign in', function (Role $role) {
    $person = User::factory()->withRoleEverywhere($role)->create();

    $this->actingAs($person)->get(route('sales.performance'))->assertForbidden();
    Livewire::actingAs($person)->test('pages::sales.performance')->assertForbidden();

    auth()->logout();

    $this->get(route('sales.performance'))->assertRedirect(route('login'));
})->with([Role::CallCenterAgent, Role::Finance, Role::ProductionManager]);

test('a manager sees the month for everyone at their location, each figure with how it was reached', function () {
    $page = performancePage($this->manager)
        ->assertSee('Everyone at Lakeview, October 2026')
        ->assertSeeHtml('data-test="performance-salesperson"')
        // One Location to see, so there is nothing to choose.
        ->assertDontSeeHtml('data-test="performance-location"')
        ->assertSee('How these are counted');

    expectFigure($page, 'nsli', '$1,640', '$8,200 sold, over 5 leads issued');
    expectFigure($page, 'leads-issued', '5', '6 handed to Sales this month, less 1 taken back this month.');
    expectFigure($page, 'appointments-run', '3', 'of 5 booked for this month');
    expectFigure($page, 'demo-rate', '67%', '2 of 3 run appointments have a proposal');
    expectFigure($page, 'close-rate', '33%', '1 lead won this month, against 3 leads first run this month.');
    expectFigure($page, 'sold-revenue', '$8,200', 'From 1 sale this month.');
    expectFigure($page, 'cancellation-rate', '20%', '1 of 5 booked appointments were cancelled. 1 no-show, counted beside it.');
});

test('a manager chooses one salesperson', function () {
    $page = performancePage($this->manager)
        ->assertSeeInOrder(['Everyone at Lakeview', 'Riley Rep', 'Sam Sales'])
        // Managers are not salespeople here and are not offered.
        ->assertDontSeeHtml('>Morgan Manager</option>')
        ->set('salespersonId', $this->sam->id)
        ->assertSee('Sam Sales at Lakeview, October 2026');

    expectFigure($page, 'nsli', '$2,050', '$8,200 sold, over 4 leads issued');
    expectFigure($page, 'demo-rate', '50%', '1 of 2 run appointments have a proposal');
    expectFigure($page, 'close-rate', '50%', '1 lead won this month, against 2 leads first run this month.');

    $page->set('salespersonId', $this->riley->id)->assertSee('Riley Rep at Lakeview, October 2026');

    expectFigure($page, 'nsli', '$0', '$0 sold, over 1 lead issued');
    expectFigure($page, 'sold-revenue', '$0', 'Nothing was sold this month.');
    expectFigure($page, 'close-rate', '0%', 'No lead won this month, against 1 lead first run this month.');
});

test('a salesperson sees their own figures and nobody else\'s, whatever the browser asks for', function () {
    $page = performancePage($this->sam)
        ->assertSee('Sam Sales at Lakeview, October 2026')
        ->assertDontSeeHtml('data-test="performance-salesperson"')
        ->assertDontSee('Riley Rep');

    expectFigure($page, 'nsli', '$2,050', '$8,200 sold, over 4 leads issued');
    expectFigure($page, 'leads-issued', '4', '5 handed to Sales this month, less 1 taken back this month.');

    // Asking for a colleague, or for everyone, changes nothing.
    $page->set('salespersonId', $this->riley->id)->assertSee('Sam Sales at Lakeview, October 2026');
    expectFigure($page, 'leads-issued', '4', '5 handed to Sales this month, less 1 taken back this month.');

    $asked = performancePage($this->sam, ['salesperson' => $this->riley->id])->assertSee('Sam Sales at Lakeview, October 2026')->assertDontSee('Riley Rep');
    expectFigure($asked, 'leads-issued', '4', '5 handed to Sales this month, less 1 taken back this month.');
});

test('nobody sees a location that is not theirs, whatever the browser asks for', function () {
    $harborSales = User::factory()->withRoleAt(Role::SalesConsultant, $this->harbor)->create(['name' => 'Harper Harbor']);

    // A Lakeview manager asking for Harbor is shown Lakeview.
    performancePage($this->manager, ['location' => $this->harbor->id])->assertSee('Everyone at Lakeview, October 2026');
    performancePage($this->manager)->set('locationId', $this->harbor->id)->assertSee('Everyone at Lakeview, October 2026');

    // A Harbor salesperson asking for Lakeview is shown their own, empty, month at Harbor.
    $page = performancePage($harborSales, ['location' => $this->lakeview->id, 'salesperson' => $this->sam->id])
        ->assertSee('Harper Harbor at Harbor, October 2026')
        ->assertDontSee('Sam Sales');

    expectFigure($page, 'leads-issued', '0', 'Handed to Sales this month.');
    expectFigure($page, 'nsli', '—', 'No leads were issued this month.');
    expectFigure($page, 'demo-rate', '—', 'No appointments were run this month.');
});

test('someone with several locations chooses one, starting with the one they are working in', function () {
    $this->actingAs($this->owner)->withSession([CurrentLocation::SESSION_KEY => $this->lakeview->id])
        ->get(route('sales.performance'))
        ->assertOk()
        ->assertSee('Everyone at Lakeview, October 2026')
        ->assertSee('data-test="performance-location"', false);

    $page = performancePage($this->owner, ['location' => $this->harbor->id])->assertSee('Everyone at Harbor, October 2026');

    expectFigure($page, 'leads-issued', '0', 'Handed to Sales this month.');

    // Changing the Location forgets the person chosen: they belong to the other one.
    performancePage($this->owner, ['location' => $this->lakeview->id, 'salesperson' => $this->sam->id])
        ->assertSee('Sam Sales at Lakeview, October 2026')
        ->set('locationId', $this->harbor->id)
        ->assertSet('salespersonId', null)
        ->assertSee('Everyone at Harbor, October 2026');
});

test('the last twelve months can be chosen, and anything else shows this month', function () {
    $page = performancePage($this->manager)
        ->assertSet('month', '2026-10')
        ->assertSeeInOrder(['October 2026', 'September 2026', 'November 2025'])
        ->assertDontSee('October 2025')
        ->set('month', '2026-09')
        ->assertSee('Everyone at Lakeview, September 2026');

    expectFigure($page, 'leads-issued', '0', 'Handed to Sales this month.');
    expectFigure($page, 'cancellation-rate', '—', 'No appointments were booked for this month. No no-shows.');

    performancePage($this->manager, ['month' => '1999-01'])->assertSee('Everyone at Lakeview, October 2026');
    performancePage($this->manager, ['month' => 'next month'])->assertSee('Everyone at Lakeview, October 2026');
    performancePage($this->manager, ['month' => '2026-11'])->assertSee('Everyone at Lakeview, October 2026');
});

test('someone who once had appointments here can still be chosen after losing their access', function () {
    $this->riley->forceFill(['is_active' => false])->save();

    $page = performancePage($this->manager)->set('salespersonId', $this->riley->id)->assertSee('Riley Rep at Lakeview, October 2026');

    expectFigure($page, 'appointments-run', '1', 'of 1 booked for this month');
});
