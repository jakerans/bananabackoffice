<?php

use App\Actions\Projects\AwardWorkPackage;
use App\Actions\Projects\ChangeColorSpecification;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordChangeOrders;
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
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Models\User;
use App\Models\WorkPackage;
use App\Models\WorkPackageAward;
use App\Models\WorkPackageRevision;
use App\Support\Projects\WorkOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Subcontractor work order revisions, step 18b (docs/slice-18-change-orders-plan.md;
 * decision 166, question 7; product-architecture.md, 6.7).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St"'s whole exterior
 * pays $4,250 and is given to Sandia Brush Co. for 16 to 20 November; the
 * colors are approved and the work order was sent.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $this->package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides, two coats.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4250', null, $this->pat);
    $window = app(ChangeWorkPackages::class)->proposeWindow($this->project, $this->package->id, '2026-11-16', '2026-11-20', $this->pat);

    $sandia = app(ChangeSubcontractor::class)->add(['businessName' => 'Sandia Brush Co.', 'contactName' => 'Rosa Alvarez', 'phone' => '505-555-0142', 'locationIds' => [(string) $this->lakeview->id]], $this->pat);
    app(ChangeSubcontractor::class)->moveTo($sandia, 'active', null, $this->pat);
    foreach (ComplianceRequirement::query()->whereNotNull('base_key')->get() as $requirement) {
        app(RecordCompliance::class)->handle($sandia, $requirement->id, '2026-10-01', $requirement->expires ? '2027-09-30' : null, false, null, $this->pat);
    }

    app(ChangeColorSpecification::class)->addLine($this->project, [
        'area' => 'Exterior', 'surface' => 'Siding', 'manufacturer' => 'Sherwin-Williams', 'colorName' => 'Repose Gray',
        'colorCode' => 'SW 7015', 'sheen' => 'satin', 'product' => 'Duration', 'note' => '',
    ], $this->pat);
    app(RecordColorApproval::class)->handle($this->project, 'Pat Customer', 'phone', '2026-10-14', $this->pat);

    app(AwardWorkPackage::class)->award($this->project, $this->package->id, $sandia->id, $window->id, 'Rosa Alvarez', 'phone', '2026-10-14', $this->pat);
    app(SendWorkOrder::class)->handle($this->project, $this->package->id, 'email', '2026-10-14', $this->pat);

    $this->project = $this->project->fresh();
});

/**
 * Change the whole exterior's scope, with a reason and change order if given.
 */
function scopeRevised($test, string $scope, ?string $reason = 'The customer added the garage.', ?int $changeOrderId = null, ?User $by = null): WorkPackage
{
    return app(ChangeWorkPackages::class)->changePackage($test->project, $test->package->id, [
        'name' => 'Whole exterior', 'scope' => $scope, 'workingDays' => '5', 'minimumCrew' => '3',
        'revisionReason' => $reason, 'changeOrderId' => $changeOrderId === null ? '' : (string) $changeOrderId,
    ], $by ?? $test->pat);
}

function revisionWorkOrder($test): WorkOrder
{
    $project = Project::query()->with(['colorSpecifications.lines'])->findOrFail($test->project->id);
    $package = WorkPackage::query()->with(['currentAward.subcontractor.locations', 'currentAward.subcontractor.complianceRecords'])->findOrFail($test->package->id);

    return WorkOrder::for($project, $package);
}

function signedChangeOrderOnOakSt($test): ProjectChangeOrder
{
    $order = app(RecordChangeOrders::class)->draft($test->project, 'Add the garage.', '900', '1', null, $test->pat);

    return app(RecordChangeOrders::class)->sign($test->project, $order->id, 'Pat Customer', 'email', '2026-10-14', UploadedFile::fake()->create('Signed.pdf', 50, 'application/pdf'), $test->pat);
}

function currentAwardOnOakSt($test): WorkPackageAward
{
    return WorkPackage::query()->findOrFail($test->package->id)->currentAward;
}

/*
|--------------------------------------------------------------------------
| A change of scope
|--------------------------------------------------------------------------
*/

test('a change of scope once the work is given out needs why, and waits for the subcontractor', function () {
    expect(fn () => scopeRevised($this, 'All four sides and the garage, two coats.', ' '))
        ->toThrow(ValidationException::class, 'This work is given to a subcontractor. Say why the scope changes');

    $order = signedChangeOrderOnOakSt($this);
    scopeRevised($this, 'All four sides and the garage, two coats.', 'The customer added the garage.', $order->id);

    $revision = WorkPackageRevision::query()->sole();
    expect($revision->only(['kind', 'scope_before', 'scope_after', 'reason', 'project_change_order_id']))->toBe([
        'kind' => 'scope',
        'scope_before' => 'All four sides, two coats.',
        'scope_after' => 'All four sides and the garage, two coats.',
        'reason' => 'The customer added the garage.',
        'project_change_order_id' => $order->id,
    ]);
    expect(currentAwardOnOakSt($this)->matches(WorkPackage::query()->findOrFail($this->package->id)))->toBeFalse()
        ->and(revisionWorkOrder($this)->changedSinceSent())->toBeTrue();
    expect(AuditEvent::query()->where('action', AuditAction::ProjectWorkRevised)->sole()->values_after)
        ->toEqual(['package' => 'Whole exterior', 'kind' => 'scope', 'change_order' => 1]);
});

test('their acceptance of the revised work is a new award, and the work order is sent again', function () {
    $first = currentAwardOnOakSt($this);
    scopeRevised($this, 'All four sides and the garage, two coats.');

    $this->travelTo('2026-10-15 10:00:00');
    app(AwardWorkPackage::class)->acceptAgain($this->project, $this->package->id, 'Rosa Alvarez', 'phone', '2026-10-15', $this->pat);

    $again = currentAwardOnOakSt($this);
    expect($again->id)->not->toBe($first->id)
        ->and($again->accepted_scope)->toBe('All four sides and the garage, two coats.')
        ->and($first->fresh()->isCurrent())->toBeFalse();

    app(SendWorkOrder::class)->handle($this->project, $this->package->id, 'email', '2026-10-15', $this->pat);
    expect(revisionWorkOrder($this)->changedSinceSent())->toBeFalse();

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project->fresh()])
        ->assertSee('accepted')
        ->assertDontSee('waiting for their acceptance')
        ->assertDontSeeHtml('data-test="work-accept-again"');
});

test('a change of name or days, or anything before the work is given out, is no revision', function () {
    app(ChangeWorkPackages::class)->changePackage($this->project, $this->package->id, ['name' => 'Exterior', 'scope' => 'All four sides, two coats.', 'workingDays' => '6', 'minimumCrew' => '3'], $this->pat);

    expect(WorkPackageRevision::query()->count())->toBe(0)
        ->and(currentAwardOnOakSt($this)->matches(WorkPackage::query()->findOrFail($this->package->id)))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| A change of payout
|--------------------------------------------------------------------------
*/

test('a change of payout once the work is given out is a revision, and may come from a change order', function () {
    $order = signedChangeOrderOnOakSt($this);

    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4,700', 'The garage adds a day.', $this->pat, $order->id);

    expect(WorkPackageRevision::query()->sole()->only(['kind', 'payout_before_cents', 'payout_after_cents', 'project_change_order_id']))
        ->toBe(['kind' => 'payout', 'payout_before_cents' => 425000, 'payout_after_cents' => 470000, 'project_change_order_id' => $order->id]);
});

test('only a signed change order of this job can be named', function () {
    $draft = app(RecordChangeOrders::class)->draft($this->project, 'Maybe the shed.', '300', '0', null, $this->pat);

    expect(fn () => app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4,700', 'The shed.', $this->pat, $draft->id))
        ->toThrow(ValidationException::class, 'Choose one of this job\'s signed change orders.');
    expect(WorkPackageRevision::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The page
|--------------------------------------------------------------------------
*/

test('the job shows each revision and what waits; the payout only to those who may see it', function () {
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->package->id, '4,700', 'The garage adds a day.', $this->pat);

    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->assertSee('Payout revised from $4,250 to $4,700')
        ->assertSee('waiting for their acceptance')
        ->assertSeeHtml('data-test="work-accept-again"');

    Livewire::actingAs($this->sam)->test('pages::projects.work', ['project' => $this->project])
        ->assertSee('Payout revised')
        ->assertDontSee('$4,700')
        ->assertSee('The work was revised since they accepted.');
});

test('changing the scope from the page asks why once the work is given out', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->call('openChange', $this->package->id)
        ->assertSee('This work is given to Sandia Brush Co.')
        ->set('package.scope', 'All four sides and the garage, two coats.')
        ->call('savePackage')
        ->assertHasErrors(['package.revisionReason'])
        ->set('package.revisionReason', 'The customer added the garage.')
        ->call('savePackage')
        ->assertHasNoErrors()
        ->assertSee('Scope revised');
});

test('the job\'s activity names each revision', function () {
    scopeRevised($this, 'All four sides and the garage, two coats.');

    Livewire::actingAs($this->pat)->test('pages::projects.show', ['project' => $this->project])
        ->assertSee('Whole exterior revised: the scope');
});
