<?php

use App\Actions\Projects\ChangeWorkPackages;
use App\Actions\Projects\RecordChangeOrders;
use App\Actions\Projects\RecordWelcomeCall;
use App\Actions\Projects\SetProjectTimeframe;
use App\Actions\Projects\TakeProject;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Projects\AfterPayouts;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
 * What a job brings in after subcontractor payouts, step 18c
 * (docs/slice-18-change-orders-plan.md; decision 166, question 8).
 *
 * It is Wednesday 14 October 2026 at Lakeview. "Oak St" sold for $8,450.
 * Its whole exterior pays $4,250; the garage, split off, has no payout yet.
 */

beforeEach(function () {
    $this->travelTo('2026-10-14 15:00:00');

    Storage::fake('files');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->pat = User::factory()->withRoleAt(Role::ProductionManager, $this->lakeview)->create(['name' => 'Pat Production']);

    $this->project = soldProject($this->lakeview, $this->sam, ['title' => 'Whole exterior — 1 Oak St']);
    completeHandoff($this->project, $this->sam);
    app(TakeProject::class)->handle($this->project, $this->pat);
    app(RecordWelcomeCall::class)->handle($this->project, '2026-10-14', null, $this->pat);
    app(SetProjectTimeframe::class)->handle($this->project, '2026-11-16', '2026-11-27', null, null, $this->pat);

    $this->exterior = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Whole exterior', 'scope' => 'All four sides.', 'workingDays' => '5', 'minimumCrew' => '3'], $this->pat);
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->exterior->id, '4250', null, $this->pat);
    $this->garage = app(ChangeWorkPackages::class)->addPackage($this->project, ['name' => 'Garage', 'scope' => 'The garage.', 'workingDays' => '1', 'minimumCrew' => '2'], $this->pat);
    $this->project = $this->project->fresh();
});

test('it is the contract total less every payout, in dollars and as a share, and says when a payout is missing', function () {
    $after = AfterPayouts::for($this->project);

    expect($after->contractCents)->toBe(845000)
        ->and($after->payoutsCents)->toBe(425000)
        ->and($after->afterCents())->toBe(420000)
        ->and($after->words())->toBe('$4,200 (49.7%)')
        ->and($after->packagesWithoutPayout)->toBe(1);

    app(ChangeWorkPackages::class)->setPayout($this->project, $this->garage->id, '600', null, $this->pat);

    expect(AfterPayouts::for($this->project)->words())->toBe('$3,600 (42.6%)')
        ->and(AfterPayouts::for($this->project)->packagesWithoutPayout)->toBe(0);
});

test('a signed change order adds to the contract; a draft does not', function () {
    $order = app(RecordChangeOrders::class)->draft($this->project, 'Add the shed.', '1,200', '1', null, $this->pat);
    expect(AfterPayouts::for($this->project)->afterCents())->toBe(420000);

    app(RecordChangeOrders::class)->sign($this->project, $order->id, 'Pat Customer', 'email', '2026-10-14', UploadedFile::fake()->create('Signed.pdf', 40, 'application/pdf'), $this->pat);

    expect(AfterPayouts::for($this->project)->words())->toBe('$5,400 (56%)');
});

test('it previews a price change and a payout before they are saved', function () {
    $after = AfterPayouts::for($this->project);

    expect($after->withPriceChange(120000)->words())->toBe('$5,400 (56%)')
        ->and($after->withPayout(425000, 470000)->words())->toBe('$3,750 (44.4%)')
        ->and($after->withPayout(null, 60000)->packagesWithoutPayout)->toBe(0);
});

test('the job shows it to those who may see payouts, with a preview while drafting a change order', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertSee('After subcontractor payouts: $4,200 (49.7%)')
        ->assertSee('1 work package has no payout yet, so this is not complete.')
        ->call('openDraft')
        ->set('changePrice', '1,200')
        ->assertSee('$4,200 (49.7%) now; $5,400 (56%) once this is signed');

    Livewire::actingAs($this->sam)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertDontSee('After subcontractor payouts')
        ->assertDontSee('$4,200');
});

test('payouts above the contract are said plainly', function () {
    app(ChangeWorkPackages::class)->setPayout($this->project, $this->garage->id, '5,000', null, $this->pat);

    Livewire::actingAs($this->pat)->test('pages::projects.change-orders', ['project' => $this->project])
        ->assertSee('After subcontractor payouts: -$800 (-9.5%)')
        ->assertSee('The payouts are more than the contract.');
});

test('the payout dialog previews it with the new payout', function () {
    Livewire::actingAs($this->pat)->test('pages::projects.work', ['project' => $this->project])
        ->call('openPayout', $this->exterior->id)
        ->set('payoutAmount', '4,700')
        ->assertSee('After subcontractor payouts: $4,200 (49.7%) now; $3,750 (44.4%) with this payout.');
});
