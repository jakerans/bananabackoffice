<?php

use App\Actions\Locations\ChangeTargetMargin;
use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordJobCosts;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\ProjectCost;
use App\Models\User;
use App\Support\Projects\JobCosting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Costs on the job and the budget, step 19a (docs/slice-19-job-costing-plan.md;
 * decision 170; product-architecture.md, 6.9, 10.1 and 10.3).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" sold for $8,450;
 * its whole exterior pays $4,250. Pat is its Production Manager, Morgan
 * manages Lakeview, Fran is Finance, Sam sold it.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->owner = User::factory()->withRoleEverywhere(Role::Owner)->create(['name' => 'Olive Owner']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);
    $package = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $package->id, '4250', null, $this->pat);
    $this->project = $this->project->fresh();
});

function costRecorded($test, string $kind = 'materials', string $amount = '412.50', ?string $purchaseOrder = 'PO-1001', ?UploadedFile $receipt = null, ?User $by = null): ProjectCost
{
    return app(RecordJobCosts::class)->add($test->project, $kind, $amount, 'Sherwin-Williams', '2026-10-14', $purchaseOrder, null, $receipt, $by ?? $test->pat);
}

function costingOf($test): JobCosting
{
    return JobCosting::for($test->project->fresh());
}

test('the budget runs from the contract and the target margin, and commits every payout and cost', function () {
    costRecorded($this, receipt: UploadedFile::fake()->image('Receipt.jpg'));
    costRecorded($this, 'rental', '150', 'R-77');

    $costing = costingOf($this);
    expect($costing->contractCents())->toBe(845000)
        ->and($costing->targetPercent())->toBe(55)
        ->and($costing->targetCostCents())->toBe(380250)
        ->and($costing->payoutsCents())->toBe(425000)
        ->and($costing->costsCents())->toBe(56250)
        ->and($costing->committedCents())->toBe(481250)
        ->and($costing->remainingCents())->toBe(-101000)
        ->and($costing->marginWords())->toBe('$3,637.50 (43%)')
        ->and($costing->missingReceipts())->toHaveCount(1);
});

test('the target margin is set per Location', function () {
    app(ChangeTargetMargin::class)->handle($this->lakeview, '40', $this->owner);

    expect(costingOf($this)->targetCostCents())->toBe(507000);
    expect(fn () => app(ChangeTargetMargin::class)->handle($this->lakeview, '100', $this->owner))->toThrow(ValidationException::class, 'Give a whole percent from 1 to 99.');
    expect(fn () => app(ChangeTargetMargin::class)->handle($this->lakeview, '50', $this->manager))->toThrow(AuthorizationException::class);
});

test('a cost that cannot be recorded is refused', function (?string $kind, string $amount, string $vendor, string $day, string $message) {
    expect(fn () => app(RecordJobCosts::class)->add($this->project, $kind, $amount, $vendor, $day, null, null, null, $this->pat))
        ->toThrow(ValidationException::class, $message);

    expect(ProjectCost::query()->count())->toBe(0);
})->with([
    'no kind' => [null, '100', 'Home Depot', '2026-10-14', 'Choose what kind of cost it is.'],
    'not an amount' => ['materials', 'lots', 'Home Depot', '2026-10-14', 'Give the amount in dollars'],
    'paid to nobody' => ['materials', '100', ' ', '2026-10-14', 'Say who it was paid to.'],
    'tomorrow' => ['materials', '100', 'Home Depot', '2026-10-15', 'The day of the cost cannot be in the future.'],
]);

test('a receipt can come later, and a wrong cost is voided with why', function () {
    $cost = costRecorded($this);

    app(RecordJobCosts::class)->attachReceipt($this->project, $cost->id, UploadedFile::fake()->image('Receipt.jpg'), $this->pat);
    expect(costingOf($this)->missingReceipts())->toHaveCount(0)
        ->and($cost->receipts()->sole()->path)->toStartWith('project_cost/'.$cost->id.'/');

    expect(fn () => app(RecordJobCosts::class)->void($this->project, $cost->id, ' ', $this->pat))->toThrow(ValidationException::class, 'Say why it is voided.');
    app(RecordJobCosts::class)->void($this->project, $cost->id, 'Returned to the store.', $this->pat);

    expect(costingOf($this)->costsCents())->toBe(0)
        ->and($cost->fresh()->voided_reason)->toBe('Returned to the store.');
    expect(fn () => $cost->fresh()->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
    expect(fn () => $cost->fresh()->delete())->toThrow(LogicException::class);
});

test('the Production Manager or a manager records costs; nobody else does', function () {
    costRecorded($this, by: $this->manager);

    foreach ([$this->sam, $this->fran] as $person) {
        expect(fn () => costRecorded($this, by: $person))->toThrow(AuthorizationException::class);
    }
});

test('the job shows the budget and costs only to those who may see payouts', function () {
    costRecorded($this);

    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertSee('Target cost, for a 55% margin')
        ->assertSee('$3,802.50')
        ->assertSee('Projected gross margin')
        ->assertSee('1 cost is missing its receipt.')
        ->assertSeeHtml('data-test="add-job-cost"');

    // Finance sees it, and records costs of its own in its reconciliation (step 19c).
    Livewire::actingAs($this->fran)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertSee('$412.50')
        ->assertSeeHtml('data-test="add-job-cost"');

    Livewire::actingAs($this->sam)->test('pages::projects.job-costing', ['project' => $this->project])->assertForbidden();
    Livewire::actingAs($this->sam)->test('pages::projects.show', ['project' => $this->project])->assertDontSeeLivewire('pages::projects.job-costing');
});

test('recording a cost from the page, with its receipt', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->call('openCost')
        ->set('costKind', 'disposal')
        ->set('costAmount', '85')
        ->set('costVendor', 'City dump')
        ->set('costReceipt', UploadedFile::fake()->image('Dump ticket.jpg'))
        ->call('saveCost')
        ->assertHasNoErrors()
        ->assertSee('City dump');

    expect(ProjectCost::query()->sole()->receipts()->count())->toBe(1);
});
