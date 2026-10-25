<?php

use App\Actions\Projects\ManageWarrantyCases;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\Subcontractor;
use App\Models\User;
use App\Models\WarrantyCase;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
 * The warranty budget and visit, step 22b (docs/slice-22-warranty-plan.md;
 * decision 177, questions 4 and 6).
 *
 * It is Wednesday 14 October 2026 at Lakeview. The customer at "Oak St"
 * reports peeling; Pat, its Production Manager, owns the case. Morgan manages
 * Lakeview. Sandia Brush Co. works at Lakeview; Harbor Coats does not.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    $this->project = $this->project->fresh();

    $this->sandia = Subcontractor::factory()->create(['business_name' => 'Sandia Brush Co.']);
    $this->sandia->locations()->attach($this->lakeview);
    $this->harborCoats = Subcontractor::factory()->create(['business_name' => 'Harbor Coats']);

    $this->case = app(ManageWarrantyCases::class)->open($this->project, 'Peeling on the south fascia.', 'The customer, by phone', $this->sam);
});

function scheduleVisit($test, string $kind = 'other_subcontractor', ?int $subcontractorId = null, ?string $name = null, string $day = '2026-10-20'): WarrantyCase
{
    return app(ManageWarrantyCases::class)->schedule($test->case, $day, $kind, $name, $subcontractorId ?? $test->sandia->id, $test->pat);
}

test('a manager approves the budget with why; $0 is allowed; nobody else approves it', function () {
    expect(fn () => app(ManageWarrantyCases::class)->approveBudget($this->case, '350', 'Materials.', $this->pat))->toThrow(AuthorizationException::class);
    expect(fn () => app(ManageWarrantyCases::class)->approveBudget($this->case, '350', ' ', $this->manager))->toThrow(ValidationException::class, 'Say why this budget.');
    expect(fn () => app(ManageWarrantyCases::class)->approveBudget($this->case, 'some', 'Materials.', $this->manager))->toThrow(ValidationException::class, 'Give the budget in dollars, like 350 or 0.');

    app(ManageWarrantyCases::class)->approveBudget($this->case, '0', 'Sandia corrects its own work.', $this->manager);
    expect($this->case->fresh()->budget_cents)->toBe(0)->and($this->case->fresh()->budget_approved_by_name)->toBe('Morgan Manager');

    app(ManageWarrantyCases::class)->approveBudget($this->case, '$350', 'Another crew, and materials.', $this->manager);
    expect($this->case->fresh()->budget_cents)->toBe(35000);
});

test('the visit is scheduled only once the budget is approved, on a day not past, with who does it', function () {
    expect(fn () => scheduleVisit($this))->toThrow(ValidationException::class, 'A manager approves the warranty budget first.');

    app(ManageWarrantyCases::class)->approveBudget($this->case, '350', 'Materials.', $this->manager);

    expect(fn () => scheduleVisit($this, day: '2026-10-13'))->toThrow(ValidationException::class, 'The visit cannot be in the past.');
    expect(fn () => scheduleVisit($this, subcontractorId: $this->harborCoats->id))->toThrow(ValidationException::class, 'Choose a subcontractor who works at Lakeview.');
    expect(fn () => scheduleVisit($this, 'original_subcontractor'))->toThrow(ValidationException::class, 'Choose one of the subcontractors who did the original work.');
    expect(fn () => scheduleVisit($this, 'w2_painter'))->toThrow(ValidationException::class, 'Say who does it.');

    $case = scheduleVisit($this);
    expect($case->state)->toBe(WarrantyCase::SCHEDULED)
        ->and($case->visit_on->toDateString())->toBe('2026-10-20')
        ->and($case->doneByWords())->toBe('Sandia Brush Co. (another subcontractor)');

    expect(scheduleVisit($this, 'w2_painter', name: 'Riley')->doneByWords())->toBe('Riley (a W-2 painter)');
    expect(fn () => app(ManageWarrantyCases::class)->schedule($this->case, '2026-10-21', 'other', 'Sam', null, $this->sam))->toThrow(AuthorizationException::class);
});

test('a scheduled case is completed with what was done', function () {
    expect(fn () => app(ManageWarrantyCases::class)->complete($this->case, 'Scraped and repainted.', $this->pat))->toThrow(ValidationException::class, 'Only a scheduled case is completed.');

    app(ManageWarrantyCases::class)->approveBudget($this->case, '0', 'Own work.', $this->manager);
    scheduleVisit($this);

    expect(fn () => app(ManageWarrantyCases::class)->complete($this->case, ' ', $this->pat))->toThrow(ValidationException::class, 'Say what was done.');
    app(ManageWarrantyCases::class)->complete($this->case, 'Scraped and repainted the fascia.', $this->pat);

    expect($this->case->fresh()->state)->toBe(WarrantyCase::COMPLETED)->and($this->case->fresh()->ended_by_name)->toBe('Pat Production');
});

test('the visit shows on Production > Schedule, and the case page approves, schedules and completes it', function () {
    Livewire::actingAs($this->manager)->test('pages::projects.warranty-case', ['project' => $this->project, 'warrantyCase' => $this->case])
        ->call('openBudget')
        ->set('warrantyBudget', '250')
        ->set('warrantyBudgetReason', 'Paint and a half day.')
        ->call('saveBudget')
        ->assertHasNoErrors()
        ->assertSee('$250');

    Livewire::actingAs($this->pat)->test('pages::projects.warranty-case', ['project' => $this->project, 'warrantyCase' => $this->case])
        ->assertDontSeeHtml('data-test="approve-warranty-budget"')
        ->call('openSchedule')
        ->set('warrantyVisitOn', '2026-10-20')
        ->set('warrantyDoneByKind', 'other_subcontractor')
        ->set('warrantySubcontractor', $this->sandia->id)
        ->call('saveSchedule')
        ->assertHasNoErrors()
        ->assertSee('Tue, Oct 20, 2026')
        ->call('openComplete')
        ->set('warrantyOutcome', 'Repainted.')
        ->call('complete')
        ->assertHasNoErrors();

    Livewire::actingAs($this->pat)->test('pages::production.schedule')
        ->assertSeeInOrder(['Warranty visit', 'Whole exterior — 1 Oak St', 'Peeling on the south fascia.', 'Done by Sandia Brush Co. (another subcontractor)']);
});
