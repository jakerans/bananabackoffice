<?php

use App\Actions\Proposals\CorrectProposalResult;
use App\Actions\Proposals\RecordProposalResult;
use App\Enums\Role;
use App\Models\Location;
use App\Models\User;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

/*
 * Average Job Size and Close-on-the-Spot Rate on Sales > Performance
 * (product-architecture.md, 5.2; slice 15, built overnight under decision
 * 155).
 *
 * The month is the worked one in tests/Pest.php, octoberOfSales(): October
 * 2026 at Lakeview. "a" was demoed by Sam Sales on the 6th and sold that
 * day; "e" was demoed by Riley Rep on the 12th and is still pending.
 */

beforeEach(function () {
    $this->travelTo('2026-10-01 14:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->lakeview)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->lakeview)->create(['name' => 'Riley Rep']);

    $this->leads = octoberOfSales($this->lakeview, $this->sam, $this->riley, $this->manager);
});

function spotFigures(?User $salesperson = null): SalesFigures
{
    return SalesFigures::for(test()->lakeview, CarbonImmutable::parse('2026-10-15 12:00:00', 'UTC'), $salesperson?->id);
}

test('close on the spot is the leads first demoed in the month with something sold at that demo', function () {
    $october = spotFigures();

    expect([$october->leadsDemoed, $october->leadsClosedOnTheSpot, $october->closeOnTheSpotRate()])->toBe([2, 1, 50]);
    expect(spotFigures($this->sam)->closeOnTheSpotRate())->toBe(100);
    expect(spotFigures($this->riley)->closeOnTheSpotRate())->toBe(0);

    // Sold a week later is a win, and not on the spot.
    $this->travelTo('2026-10-19 15:00:00');
    app(RecordProposalResult::class)->handle($this->leads['e'], $this->leads['e']->proposals()->sole(), 'sold', [], $this->riley);

    expect(spotFigures($this->riley)->closeOnTheSpotRate())->toBe(0);
    expect(spotFigures($this->riley)->closeRate())->toBe(100);
});

test('average job size is the month\'s sold revenue over the jobs sold, after corrections', function () {
    $october = spotFigures();
    $jobs = $october->salesMade - $october->salesCorrected;

    expect($jobs)->toBeGreaterThan(0);
    expect($october->averageJobSizeCents())->toBe((int) round($october->netSoldCents() / $jobs));

    // A sale corrected away leaves no job to divide by for that salesperson.
    $this->travelTo('2026-10-20 15:00:00');
    app(CorrectProposalResult::class)->handle($this->leads['a']->fresh(), $this->leads['a']->proposals()->sole(), 'The customer backed out.', $this->manager);

    expect(spotFigures($this->sam)->averageJobSizeCents())->toBeNull();
    // The month of the demo keeps the sale made at it.
    expect(spotFigures($this->sam)->closeOnTheSpotRate())->toBe(100);
});

test('the performance page shows both, with how they are counted', function () {
    Livewire::actingAs($this->manager)->withQueryParams(['month' => '2026-10'])->test('pages::sales.performance')
        ->assertSeeHtml('data-test="figure-average-job-size"')
        ->assertSeeHtml('data-test="figure-close-on-the-spot"')
        ->assertSee('1 of 2 leads demoed sold at the demo')
        ->assertSee('Close on the spot: of the leads demoed for the first time in the month');
});
