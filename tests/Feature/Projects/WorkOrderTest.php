<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\ChangeInsuranceDeduction;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordColorApproval;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SendWorkOrder;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Actions\Subcontractors\RecordCompliance;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\ComplianceRequirement;
use App\Models\InsuranceDeductionRate;
use App\Models\Location;
use App\Models\Project;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WorkOrderSent;
use App\Models\WorkPackage;
use App\Support\Customers\CustomerActivity;
use App\Support\Projects\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The work order, step 13c (docs/slice-13-giving-work-plan.md;
 * product-architecture.md, 6.6 and 7.2; decision 151, questions 7 to 9).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" is ready; its whole
 * exterior pays $4,250 and is given to Sandia Brush Co. for 16 to 20
 * November. The customer has not approved the colors yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);
    $this->finance = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Fran Finance']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides, two coats.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $this->first = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);
    $this->later = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-23', '2026-11-27', $this->pat);
    $this->project = $this->project->fresh();
});

/**
 * An Active subcontractor at Lakeview with every base item on file, or with
 * workers' compensation covered by Banana.
 */
function workOrderSubcontractor($test, bool $usesBananaCoverage = false): Subcontractor
{
    $subcontractor = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $test->lakeview->id]], $test->pat);
    app(ChangeSubcontractor::class)->moveTo($subcontractor, 'active', null, $test->pat);

    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        $covered = $usesBananaCoverage && $requirement->base_key === 'workers_comp';
        app(RecordCompliance::class)->handle($subcontractor, $requirement->id, $covered ? null : '2026-10-01', $requirement->expires && ! $covered ? '2027-09-30' : null, $covered, null, $test->pat);
    }

    return $subcontractor->fresh();
}

function workOrderAwarded($test, bool $usesBananaCoverage = false, ?int $windowId = null): Subcontractor
{
    $subcontractor = workOrderSubcontractor($test, $usesBananaCoverage);
    app(AwardWorkPackage::class)->award($test->project, $test->package->id, $subcontractor->id, $windowId ?? $test->first->id, 'Rosa Alvarez', 'phone', '2026-10-14', $test->pat);

    return $subcontractor;
}

function workOrderColorsApproved($test): void
{
    app(ChangeColorSpecification::class)->addLine($test->project, [
        'area' => 'Exterior', 'surface' => 'Siding', 'manufacturer' => 'Sherwin-Williams', 'colorName' => 'Repose Gray',
        'colorCode' => 'SW 7015', 'sheen' => 'satin', 'product' => 'Duration', 'note' => '',
    ], $test->pat);
    app(RecordColorApproval::class)->handle($test->project, 'Pat Customer', 'phone', '2026-10-14', $test->pat);
}

function workOrderOf($test): ?WorkOrder
{
    $project = Project::query()->with(['colorSpecifications.lines'])->findOrFail($test->project->id);
    $package = WorkPackage::query()->with(['currentAward.subcontractor.locations', 'currentAward.subcontractor.complianceRecords'])->findOrFail($test->package->id);

    return WorkOrder::for($project, $package);
}

function workOrderSent($test, ?User $by = null): WorkOrderSent
{
    return app(SendWorkOrder::class)->handle($test->project, $test->package->id, 'email', '2026-10-14', $by ?? $test->pat);
}

/*
|--------------------------------------------------------------------------
| What it says
|--------------------------------------------------------------------------
*/

test('work not given out has no work order; given out, it has the days, the payout and no deduction', function () {
    expect(workOrderOf($this))->toBeNull();

    workOrderAwarded($this);
    $order = workOrderOf($this);

    expect([$order->firstDay->toDateString(), $order->lastDay->toDateString(), $order->payoutCents, $order->deductionCents])->toBe(['2026-11-16', '2026-11-20', 425000, 0]);
    expect($order->hasDeduction())->toBeFalse();
    expect($order->lastSent)->toBeNull();
});

test('it cannot be sent until the customer approves the colors', function () {
    workOrderAwarded($this);

    expect(workOrderOf($this)->missing())->toBe(['the customer\'s approval of the colors']);
    expect(fn () => workOrderSent($this))->toThrow(ValidationException::class, 'It cannot be sent yet. It still needs the customer\'s approval of the colors.');

    workOrderColorsApproved($this);

    expect(workOrderOf($this)->canBeSent())->toBeTrue();
});

test('a subcontractor using Banana\'s coverage has the rate in force on the work\'s first day taken off', function () {
    workOrderAwarded($this, usesBananaCoverage: true);
    $order = workOrderOf($this);

    expect($order->rate->percent())->toBe('10%');
    expect([$order->deductionCents, $order->payoutAfterDeduction()])->toBe([42500, 382500]);

    // A new rate from 20 November does not reach work that starts on the 16th.
    app(ChangeInsuranceDeduction::class)->handle('7.5', '2026-11-20', $this->owner);

    expect(workOrderOf($this)->rate->percent())->toBe('10%');
    expect(InsuranceDeductionRate::inForceOn(CarbonImmutable::parse('2026-11-23'))->percent())->toBe('7.5%');
});

test('the dates are the confirmed ones once the customer confirms', function () {
    workOrderAwarded($this);
    app(ChangeWorkPackages::class)->confirmWindow($this->project, $this->first->id, 'Pat Customer', 'phone', '2026-10-14', $this->pat);
    app(ChangeWorkPackages::class)->reschedule($this->project, $this->package->id, '2026-11-30', '2026-12-04', 'Rain.', 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    $order = workOrderOf($this);

    expect([$order->firstDay->toDateString(), $order->lastDay->toDateString()])->toBe(['2026-11-30', '2026-12-04']);
});

/*
|--------------------------------------------------------------------------
| Sending it
|--------------------------------------------------------------------------
*/

test('sending it keeps what it said, and is in the customer\'s Activity without the payout', function () {
    workOrderAwarded($this, usesBananaCoverage: true);
    workOrderColorsApproved($this);

    $sent = workOrderSent($this);

    expect($sent->only(['payout_cents', 'deduction_cents', 'deduction_basis_points', 'sent_by', 'sent_by_name']))->toBe([
        'payout_cents' => 425000, 'deduction_cents' => 42500, 'deduction_basis_points' => 1000, 'sent_by' => $this->pat->id, 'sent_by_name' => 'Pat Production',
    ]);
    expect([$sent->sent_on->toDateString(), $sent->first_day->toDateString(), $sent->last_day->toDateString()])->toBe(['2026-10-14', '2026-11-16', '2026-11-20']);

    $event = AuditEvent::query()->where('action', AuditAction::ProjectWorkOrderSent)->sole();

    expect($event->values_after)->toEqual(['package' => 'Whole exterior', 'subcontractor' => 'Sandia Brush Co.', 'first_day' => '2026-11-16', 'last_day' => '2026-11-20', 'method' => 'email', 'sent_on' => '2026-10-14']);
    expect(CustomerActivity::label(AuditAction::ProjectWorkOrderSent))->toBe('Work order sent');

    // What was sent is kept as it was.
    expect(fn () => $sent->forceFill(['payout_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $sent->delete())->toThrow(LogicException::class);
});

test('a changed payout must be accepted again before it is sent, and marks the sent one as changed', function () {
    workOrderAwarded($this);
    workOrderColorsApproved($this);
    workOrderSent($this);

    expect(workOrderOf($this)->changedSinceSent())->toBeFalse();

    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4600', 'Extra trim.', $this->pat);

    expect(workOrderOf($this)->changedSinceSent())->toBeTrue();
    expect(workOrderOf($this)->missing())->toBe(['their acceptance of the payout it has now']);

    app(AwardWorkPackage::class)->acceptAgain($this->project, $this->package->id, 'Rosa Alvarez', 'text', '2026-10-14', $this->pat);
    workOrderSent($this);

    expect(workOrderOf($this)->changedSinceSent())->toBeFalse();
    expect(WorkOrderSent::query()->count())->toBe(2);
});

test('what cannot be recorded as sent is refused', function () {
    expect(fn () => workOrderSent($this))->toThrow(ValidationException::class, 'This work is not given to a subcontractor.');

    workOrderAwarded($this);
    workOrderColorsApproved($this);

    expect(fn () => app(SendWorkOrder::class)->handle($this->project, $this->package->id, '', '2026-10-14', $this->pat))->toThrow(ValidationException::class, 'Choose how it was sent.');
    expect(fn () => app(SendWorkOrder::class)->handle($this->project, $this->package->id, 'email', '2026-10-15', $this->pat))->toThrow(ValidationException::class, 'The day it was sent cannot be in the future.');
    expect(WorkOrderSent::query()->count())->toBe(0);
});

test('who may record a work order as sent', function (Closure $person, bool $may) {
    workOrderAwarded($this);
    workOrderColorsApproved($this);
    $person = $person($this);

    if ($may) {
        expect(workOrderSent($this, $person)->exists)->toBeTrue();

        return;
    }

    expect(fn () => workOrderSent($this, $person))->toThrow(AuthorizationException::class);
})->with([
    'its Production Manager' => [fn ($test) => $test->pat, true],
    'a Location Manager there' => [fn ($test) => $test->manager, true],
    'Finance' => [fn ($test) => $test->finance, false],
    'the salesperson' => [fn ($test) => $test->sam, false],
]);

/*
|--------------------------------------------------------------------------
| The deduction rate
|--------------------------------------------------------------------------
*/

test('the deduction rate starts at 10% and is changed from a day on by whoever manages Locations', function () {
    expect(InsuranceDeductionRate::inForceOn(now())->percent())->toBe('10%');

    $rate = app(ChangeInsuranceDeduction::class)->handle('12', '2026-11-01', $this->owner);

    expect($rate->only(['basis_points', 'set_by']))->toBe(['basis_points' => 1200, 'set_by' => $this->owner->id]);
    expect(AuditEvent::query()->where('action', AuditAction::InsuranceDeductionChanged)->sole()->values_after)->toEqual(['basis_points' => 1200, 'effective_on' => '2026-11-01']);

    expect(fn () => app(ChangeInsuranceDeduction::class)->handle('8', '2026-10-13', $this->owner))->toThrow(ValidationException::class, 'A new rate cannot take effect in the past.');
    expect(fn () => app(ChangeInsuranceDeduction::class)->handle('60', '2026-11-01', $this->owner))->toThrow(ValidationException::class, 'Give the rate as a percent from 0 to 50, like 10 or 7.5.');
    expect(fn () => app(ChangeInsuranceDeduction::class)->handle('8', '2026-11-01', $this->manager))->toThrow(AuthorizationException::class);
    expect(fn () => $rate->forceFill(['basis_points' => 1])->save())->toThrow(LogicException::class);

    Livewire::actingAs($this->owner)->test('pages::settings.locations')
        ->assertSee('Now 10% of payout.')
        ->assertSee('12% from Nov 1, 2026')
        ->set('deductionPercent', '9')
        ->set('deductionEffectiveOn', '2027-01-01')
        ->call('saveDeduction')
        ->assertHasNoErrors()
        ->assertSee('9% from Jan 1, 2027');
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the work order page shows where, when, the work, the colors and the pay, and records it as sent', function () {
    workOrderAwarded($this, usesBananaCoverage: true);

    $page = Livewire::actingAs($this->pat)->test('pages::projects.work-order', ['project' => $this->project, 'workPackage' => $this->package])
        ->assertSee('Whole exterior')
        ->assertSee('Sandia Brush Co.')
        ->assertSee($this->project->property->address)
        ->assertSee('Access: Gate code 4411. Dog in the yard.')
        ->assertSee('Mon, Nov 16 – Fri, Nov 20, 2026')
        ->assertSee('All four sides, two coats.')
        ->assertSee('The colors are not approved by the customer yet.')
        ->assertSee('$4,250')
        ->assertSee('Insurance deduction: Banana\'s coverage, 10%')
        ->assertSee('$3,825')
        ->assertSee('Not sent yet.')
        ->assertSeeHtml('data-test="work-order-missing"');

    workOrderColorsApproved($this);

    Livewire::actingAs($this->pat)->test('pages::projects.work-order', ['project' => $this->project->fresh(), 'workPackage' => $this->package])
        ->assertSeeHtml('Exterior &middot; Siding:')
        ->assertSee('Repose Gray (SW 7015)')
        ->call('openSent')
        ->call('saveSent')
        ->assertHasErrors(['workOrderMethod'])
        ->set('workOrderMethod', 'email')
        ->call('saveSent')
        ->assertHasNoErrors()
        ->assertSee('Sent by email on Oct 14, 2026 by Pat Production.');
});

test('the page needs payouts: finance reads it, the salesperson cannot open it', function () {
    workOrderAwarded($this);

    $this->actingAs($this->finance)->get(route('projects.work-order', [$this->project, $this->package]))->assertOk()->assertSee('$4,250');
    Livewire::actingAs($this->finance)->test('pages::projects.work-order', ['project' => $this->project, 'workPackage' => $this->package])
        ->assertDontSeeHtml('data-test="record-work-order-sent"')
        ->call('openSent')
        ->assertForbidden();

    $this->actingAs($this->sam)->get(route('projects.work-order', [$this->project, $this->package]))->assertForbidden();

    // A package of another job is not found under this one.
    $other = soldProject($this->lakeview, $this->sam);
    $this->actingAs($this->pat)->get(route('projects.work-order', [$other, $this->package]))->assertNotFound();
});
