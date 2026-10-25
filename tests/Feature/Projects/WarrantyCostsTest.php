<?php

use App\Actions\Projects\ManageWarrantyCases;
use App\Actions\Projects\RecordWarrantyCosts;
use App\Actions\Projects\TakeProject;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\ProductionBonusCredit;
use App\Models\User;
use App\Models\WarrantyCost;
use App\Support\Projects\JobCosting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * Warranty cost, step 22c (docs/slice-22-warranty-plan.md; decision 177,
 * question 5; product-architecture.md, 6.1, 10.1 and 16).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" sold for $8,450; Pat
 * is its Production Manager and owns its warranty case, with a $250 budget.
 * Fran is Finance at Lakeview; Sam sold it; Morgan manages Lakeview.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');
    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->lakeview)->create(['name' => 'Fran Finance']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();

    $this->case = app(ManageWarrantyCases::class)->open($this->project, 'Peeling on the south fascia.', 'The customer, by phone', $this->sam);
    app(ManageWarrantyCases::class)->approveBudget($this->case, '250', 'Paint and a half day.', $this->manager);
});

function warrantyCost($test, string $kind = 'materials', string $amount = '180', ?User $by = null, ?UploadedFile $receipt = null): WarrantyCost
{
    return app(RecordWarrantyCosts::class)->add($test->case, $kind, $amount, 'Sherwin-Williams', '2026-10-14', null, $receipt, $by ?? $test->pat);
}

test('a cost is recorded with its receipt, rolls into the lifetime gross margin, and counts against the Production Bonus this month', function () {
    $cost = warrantyCost($this, receipt: UploadedFile::fake()->image('Receipt.jpg'));

    expect($cost->receipts()->count())->toBe(1)
        ->and(JobCosting::for($this->project->fresh())->warrantyCents())->toBe(18000)
        ->and(JobCosting::for($this->project->fresh())->lifetimeMarginCents())->toBe(845000 - 18000)
        ->and(ProductionBonusCredit::query()->sole()->only(['user_id', 'month', 'revenue_cents', 'margin_cents', 'warranty_cost_id']))
        ->toBe(['user_id' => $this->pat->id, 'month' => '2026-10', 'revenue_cents' => 0, 'margin_cents' => -18000, 'warranty_cost_id' => $cost->id])
        ->and(AuditEvent::query()->where('action', AuditAction::WarrantyCostRecorded)->count())->toBe(1);
});

test('only a subcontractor\'s payout may be $0, and a cost needs its kind, who and a day not in the future', function () {
    expect(warrantyCost($this, 'payout', '0')->amount_cents)->toBe(0)
        ->and(ProductionBonusCredit::query()->count())->toBe(0);

    expect(fn () => warrantyCost($this, 'materials', '0'))->toThrow(ValidationException::class, 'Give the amount in dollars, like 412.50.');
    expect(fn () => warrantyCost($this, 'paint'))->toThrow(ValidationException::class, 'Choose what kind of cost it is.');
    expect(fn () => app(RecordWarrantyCosts::class)->add($this->case, 'materials', '10', ' ', '2026-10-14', null, null, $this->pat))->toThrow(ValidationException::class, 'Say who it was paid to.');
    expect(fn () => app(RecordWarrantyCosts::class)->add($this->case, 'materials', '10', 'Home Depot', '2026-10-15', null, null, $this->pat))->toThrow(ValidationException::class, 'The day of the cost cannot be in the future.');
});

test('a wrong cost is voided with why; it stops counting, and the Production Bonus gets it back', function () {
    $cost = warrantyCost($this);

    $this->travelTo('2026-11-02 15:00:00');
    app(RecordWarrantyCosts::class)->void($this->case, $cost->id, 'Billed to the wrong job.', $this->pat);

    expect(JobCosting::for($this->project->fresh())->warrantyCents())->toBe(0)
        ->and(ProductionBonusCredit::query()->orderBy('id')->get(['month', 'margin_cents'])->toArray())->toBe([
            ['month' => '2026-10', 'margin_cents' => -18000],
            ['month' => '2026-11', 'margin_cents' => 18000],
        ]);
    expect(fn () => $cost->fresh()->forceFill(['amount_cents' => 1])->save())->toThrow(LogicException::class);
});

test('the owner or a manager who may see payouts records costs; nobody else, and not on a case closed without work', function () {
    warrantyCost($this, by: $this->manager);

    foreach ([$this->sam, $this->fran] as $person) {
        expect(fn () => warrantyCost($this, by: $person))->toThrow(AuthorizationException::class);
    }

    $other = app(ManageWarrantyCases::class)->open($this->project, 'A second issue.', 'The customer', $this->sam);
    app(ManageWarrantyCases::class)->close($other, 'Not workmanship.', $this->pat);
    expect(fn () => app(RecordWarrantyCosts::class)->add($other, 'materials', '10', 'Home Depot', '2026-10-14', null, null, $this->pat))
        ->toThrow(ValidationException::class, 'This case was closed without work, so it has no costs.');
});

test('the case shows what it cost against its budget; the job and Finance > Job Costing show the lifetime gross margin', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.warranty-case', ['project' => $this->project, 'warrantyCase' => $this->case])
        ->call('openCost')
        ->set('warrantyCostKind', 'materials')
        ->set('warrantyCostAmount', '300')
        ->set('warrantyCostPaidTo', 'Sherwin-Williams')
        ->call('saveCost')
        ->assertHasNoErrors()
        ->assertSeeInOrder(['$300', 'of a $250 budget', 'Over budget']);

    $this->actingAs($this->sam)->get(route('projects.warranty-case', [$this->project, $this->case]))->assertOk()->assertDontSeeHtml('data-test="warranty-costs"');

    Livewire::actingAs($this->pat)->test('pages::projects.job-costing', ['project' => $this->project])
        ->assertSee('Lifetime gross margin, after $300 of warranty: $8,150');

    Livewire::actingAs($this->fran)->test('pages::finance.job-costing')
        ->assertSeeInOrder(['Warranty cost, last 90 days', 'Whole exterior — 1 Oak St', '$300 of warranty cost', 'Lifetime $8,150']);
});
