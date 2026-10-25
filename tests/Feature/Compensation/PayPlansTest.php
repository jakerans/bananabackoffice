<?php

use App\Actions\Compensation\ManagePay;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\PayPlan;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Pay plans, adjustments and who sees pay, step 20a
 * (docs/slice-20-compensation-plan.md; decision 172, question 4).
 *
 * Finley is Finance for every Location; Fran is Finance at Lakeview only.
 * Sam sells at Lakeview, Pat runs jobs there, Morgan manages it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->morgan = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
});

test('a plan is kept with its terms, effective from a day, and a change says why', function () {
    $first = app(ManagePay::class)->setPlan($this->sam, 'sales_straight', '2026-10-01', null, $this->finley);

    expect($first->rate_basis_points)->toBe(1500)
        ->and($first->base_salary_cents)->toBe(0)
        ->and(PayPlan::onDay($this->sam->id, PayPlan::FAMILY_SALES, '2026-09-30'))->toBeNull()
        ->and(PayPlan::onDay($this->sam->id, PayPlan::FAMILY_SALES, '2026-10-01')?->id)->toBe($first->id);

    expect(fn () => app(ManagePay::class)->setPlan($this->sam, 'sales_base_35000', '2027-01-01', ' ', $this->finley))->toThrow(ValidationException::class, 'Say why the plan changes.');
    $second = app(ManagePay::class)->setPlan($this->sam, 'sales_base_35000', '2027-01-01', 'Moved to a base.', $this->finley);

    expect(PayPlan::onDay($this->sam->id, PayPlan::FAMILY_SALES, '2026-12-31')?->id)->toBe($first->id)
        ->and(PayPlan::onDay($this->sam->id, PayPlan::FAMILY_SALES, '2027-01-01')?->id)->toBe($second->id)
        ->and(AuditEvent::query()->where('action', AuditAction::PayPlanSet)->count())->toBe(2);
    expect(fn () => $first->fresh()->forceFill(['rate_basis_points' => 1])->save())->toThrow(LogicException::class);
});

test('a plan that cannot be set is refused', function (?string $plan, ?string $from, string $message) {
    expect(fn () => app(ManagePay::class)->setPlan($this->sam, $plan, $from, null, $this->finley))->toThrow(ValidationException::class, $message);
})->with([
    'no plan' => [null, '2026-10-01', 'Choose a pay plan.'],
    'no day' => ['sales_straight', '', 'Give the day it starts.'],
]);

test('an adjustment adds or deducts in this month, for a job or not, with why', function () {
    $entry = app(ManagePay::class)->adjust($this->sam, 'deduct', '120', null, 'Half of a shared sale.', $this->finley);

    expect($entry->kind)->toBe(CompensationEntry::KIND_ADJUSTMENT)
        ->and($entry->amount_cents)->toBe(-12000)
        ->and($entry->period)->toBe('2026-10')
        ->and(AuditEvent::query()->where('action', AuditAction::CompensationAdjusted)->sole()->reason)->toBe('Half of a shared sale.');
    expect(fn () => app(ManagePay::class)->adjust($this->sam, 'add', '120', null, '', $this->finley))->toThrow(ValidationException::class, 'Say why.');
    expect(fn () => app(ManagePay::class)->adjust($this->sam, 'add', '-5', null, 'Oops.', $this->finley))->toThrow(ValidationException::class, 'Give the amount in dollars');
    expect(fn () => $entry->fresh()->delete())->toThrow(LogicException::class);
});

test('only Finance for every Location, and the Owner, manage pay, and never their own', function () {
    app(ManagePay::class)->setPlan($this->sam, 'sales_straight', '2026-10-01', null, $this->owner);

    foreach ([$this->fran, $this->morgan, $this->sam] as $person) {
        expect(fn () => app(ManagePay::class)->setPlan($this->pat, 'production_bonus', '2026-10-01', null, $person))->toThrow(AuthorizationException::class);
    }

    expect(fn () => app(ManagePay::class)->adjust($this->finley, 'add', '100', null, 'A raise.', $this->finley))->toThrow(AuthorizationException::class);
});

/*
|--------------------------------------------------------------------------
| The pages
|--------------------------------------------------------------------------
*/

test('each person sees their own pay, and Finance and the Owner see anyone\'s', function () {
    app(ManagePay::class)->setPlan($this->sam, 'sales_straight', '2026-10-01', null, $this->finley);
    app(ManagePay::class)->adjust($this->sam, 'add', '250', null, 'Spiff for the home show.', $this->finley);

    $this->actingAs($this->sam)->get(route('pay'))->assertOk()
        ->assertSee('My pay')
        ->assertSee('Straight commission: 15% of gross-margin dollars')
        ->assertSee('Spiff for the home show.')
        ->assertSeeHtml('data-test="my-pay"');

    $this->actingAs($this->sam)->get(route('finance.payroll.person', $this->pat))->assertForbidden();
    $this->actingAs($this->morgan)->get(route('finance.payroll.person', $this->sam))->assertForbidden();
    $this->actingAs($this->fran)->get(route('finance.payroll.person', $this->sam))->assertForbidden();
    $this->actingAs($this->finley)->get(route('finance.payroll.person', $this->sam))->assertOk()->assertSee('Spiff for the home show.');
    $this->actingAs($this->owner)->get(route('finance.payroll.person', $this->sam))->assertOk();

    // Somebody with no pay has no menu item.
    $this->actingAs($this->morgan)->get(route('pay'))->assertOk()->assertDontSeeHtml('data-test="my-pay"')->assertSee('No pay plan is set in Backoffice.');
});

test('Finance > Payroll lists the people with variable pay, their plans and what is due', function () {
    app(ManagePay::class)->setPlan($this->sam, 'sales_straight', '2026-10-01', null, $this->finley);
    app(ManagePay::class)->adjust($this->sam, 'add', '250', null, 'Spiff.', $this->finley);

    $this->actingAs($this->finley)->get(route('finance.payroll'))->assertOk()
        ->assertSeeInOrder(['Sam Sales', '1 entry', '$250', 'People and pay plans', 'Pat Production', 'No pay plan', 'Sam Sales', 'Straight commission: 15% of gross-margin dollars']);

    $this->actingAs($this->fran)->get(route('finance.payroll'))->assertForbidden();
    $this->actingAs($this->sam)->get(route('finance.payroll'))->assertForbidden();
});

test('Finance sets a plan and records an adjustment from the person\'s page', function () {
    Livewire::actingAs($this->finley)->test('pages::pay.ledger', ['user' => $this->pat])
        ->call('openPlan')
        ->set('plan', 'production_bonus')
        ->set('planEffectiveFrom', '2026-10-01')
        ->call('savePlan')
        ->assertHasNoErrors()
        ->assertSee('$45,000 base + the Production Bonus')
        ->call('openAdjustment')
        ->set('adjustmentDirection', 'add')
        ->set('adjustmentAmount', '75')
        ->set('adjustmentReason', 'Weekend callback.')
        ->call('saveAdjustment')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['October 2026', '$75', 'Manual adjustment', 'Weekend callback.']);

    Livewire::actingAs($this->pat)->test('pages::pay.ledger')
        ->assertDontSeeHtml('data-test="set-pay-plan"')
        ->assertSee('Weekend callback.');
});
