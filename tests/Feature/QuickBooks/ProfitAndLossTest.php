<?php

use App\Actions\QuickBooks\ReadProfitAndLoss;
use App\Enums\Role;
use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * Profit and loss read from QuickBooks, step 28b
 * (docs/slice-28-quickbooks-plan.md; decision 189, questions 5 to 7).
 *
 * It is Monday 26 October 2026. Lakeview and Harbor are each connected to
 * their own QuickBooks company; Riverside is not. QuickBooks is faked.
 */

beforeEach(function () {
    $this->travelTo('2026-10-26 15:00:00');

    $this->lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $this->harbor = Location::factory()->create(['name' => 'Harbor']);
    $this->riverside = Location::factory()->create(['name' => 'Riverside']);
    $this->finley = User::factory()->withRoleEverywhere(Role::Finance)->create(['name' => 'Finley Finance']);
    $this->leader = User::factory()->withRoleEverywhere(Role::Leadership)->create(['name' => 'Lee Leader']);

    connectQuickBooksFor($this->lakeview, '1001');
    connectQuickBooksFor($this->harbor, '2002');

    Http::preventStrayRequests();
    Http::fake([
        'sandbox-quickbooks.api.intuit.com/v3/company/1001/*' => Http::response(quickBooksProfitAndLoss('40000.00', '22000.00', '9000.00', '8500.50')),
        'sandbox-quickbooks.api.intuit.com/v3/company/2002/*' => Http::response(['Fault' => ['type' => 'SERVICE']], 500),
    ]);
});

test('a Location\'s profit and loss is read for the month so far, as QuickBooks totals it', function () {
    $statement = app(ReadProfitAndLoss::class)->handle($this->lakeview, '2026-10');

    expect($statement)
        ->income_cents->toBe(4000000)->cost_of_goods_cents->toBe(2200000)->expenses_cents->toBe(900000)
        ->net_operating_income_cents->toBe(900000)->net_income_cents->toBe(850050)
        ->and($statement->netProfitBasisPoints())->toBe(2125);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v3/company/1001/reports/ProfitAndLoss')
        && $request['start_date'] === '2026-10-01' && $request['end_date'] === '2026-10-26' && $request['accounting_method'] === 'Accrual'
        && $request->hasHeader('Authorization', 'Bearer access-1001'));

    app(ReadProfitAndLoss::class)->handle($this->lakeview, '2026-10');
    expect(LocationProfitAndLoss::query()->count())->toBe(1);
});

test('each night, this month and last are read for every connected Location; one QuickBooks cannot answer for is named', function () {
    $this->artisan('backoffice:quickbooks')
        ->expectsOutputToContain('2 months read')
        ->expectsOutputToContain('QuickBooks could not be read for Harbor.')
        ->assertSuccessful();

    expect(LocationProfitAndLoss::query()->orderBy('month')->pluck('month', 'location_id')->all())->toBe([$this->lakeview->id => '2026-10'])
        ->and(LocationProfitAndLoss::query()->where('location_id', $this->lakeview->id)->pluck('month')->sort()->values()->all())->toBe(['2026-09', '2026-10']);
    Http::assertSent(fn (Request $request): bool => $request['start_date'] === '2026-09-01' && $request['end_date'] === '2026-09-30');
});

test('Finance sees each Location\'s profit and loss on Finance > Performance, and reads it again now', function () {
    $this->actingAs($this->finley);

    Livewire::test('pages::finance.performance')
        ->assertSee('Not read yet')
        ->assertSee('Not connected to QuickBooks')
        ->call('readQuickBooks')
        ->assertSee('$40,000')
        ->assertSee('$8,500.50')
        ->assertSee('21.3%');

    expect(LocationProfitAndLoss::query()->where('location_id', $this->lakeview->id)->count())->toBe(2);

    $this->actingAs($this->leader)->get(route('finance.performance'))->assertForbidden();
});

test('nothing is read on a server without Intuit\'s keys', function () {
    config(['services.quickbooks.client_id' => null]);

    $this->artisan('backoffice:quickbooks')->expectsOutputToContain('not set up')->assertSuccessful();

    Http::assertNothingSent();
});
