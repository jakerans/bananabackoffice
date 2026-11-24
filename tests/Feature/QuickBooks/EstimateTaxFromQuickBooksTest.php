<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\RepriceEstimate;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Actions\QuickBooks\SyncEstimateToQuickBooks;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Estimate;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\QuickBooksConnection;
use App\Models\RateCategory;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * An estimate's tax from QuickBooks, step 38b (docs/slice-38-quickbooks-tax-plan.md;
 * decisions 229 and 230). QuickBooks itself is faked: nothing leaves the test.
 * Made-up prices only. The faked company charges 8.1875% in three parts, as a
 * company with automated sales tax answers.
 *
 * Walls are 90 sq ft an hour for two coats at $50 an hour; the paint is
 * $30.50 a gallon and covers 250 sq ft. A 12 by 14 by 8 bedroom is $352.
 */

beforeEach(function () {
    config(['services.quickbooks' => ['client_id' => 'test-client', 'client_secret' => 'test-secret', 'environment' => 'sandbox']]);

    $this->farmington = Location::factory()->create(['name' => 'Farmington']);
    $this->manager = User::factory()->withRoleAt(Role::LocationManager, $this->farmington)->create(['name' => 'Morgan Manager']);
    $this->sam = User::factory()->withRoleAt(Role::SalesConsultant, $this->farmington)->create(['name' => 'Sam Sales']);
    $this->riley = User::factory()->withRoleAt(Role::SalesConsultant, $this->farmington)->create(['name' => 'Riley Rep']);

    $this->book = PriceBook::factory()->create(['hourly_rate_cents' => 5000]);
    $category = RateCategory::factory()->create(['price_book_id' => $this->book->id]);
    $this->paint = PaintProduct::factory()->create(['price_book_id' => $this->book->id, 'price_cents' => 3050, 'coverage_area' => '250', 'coverage_each' => '0.2']);
    $this->walls = ProductionRate::factory()->create([
        'price_book_id' => $this->book->id, 'rate_category_id' => $category->id, 'value_1' => '150', 'value_2' => '90', 'value_3' => null,
        'uses_coats' => true, 'default_coats' => 2, 'paint_product_id' => $this->paint->id,
    ]);
    $this->listTax = TaxRate::factory()->create(['price_book_id' => $this->book->id, 'name' => 'Sample City Tax', 'percent' => '5.5']);

    $this->lead = bookableLead($this->farmington);
    bookAppointment($this->lead, $this->sam, $this->manager);
    $this->lead = $this->lead->fresh();
    $this->lead->forceFill([
        'inquiry_name' => 'Pat Painter', 'inquiry_email' => 'pat@example.test',
        'address_line1' => '800 Municipal Dr', 'address_city' => 'Farmington', 'address_state' => 'NM', 'address_postal_code' => '87401',
    ])->save();
    $this->lead->contact?->forceFill(['email' => 'pat@example.test'])->save();

    $this->connection = (new QuickBooksConnection)->forceFill([
        'location_id' => $this->farmington->id, 'realm_id' => '9130350001', 'company_name' => 'Sample Painting Farmington',
        'access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'access_token_expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    $this->connection->save();

    $this->quickBooks = fakeQuickBooksCompany();
});

/**
 * A QuickBooks company that keeps customers and estimates and works out
 * 8.1875% on taxable lines. Returns what it holds, to look at afterwards.
 */
function fakeQuickBooksCompany(): ArrayObject
{
    $company = new ArrayObject(['estimates' => [], 'customers' => [], 'deleted' => [], 'down' => false, 'next' => 100, 'requests' => 0, 'existingEmail' => null]);

    Http::preventStrayRequests();
    Http::fake(['sandbox-quickbooks.api.intuit.com/*' => function (Request $request) use ($company) {
        $company['requests']++;

        if ($company['down']) {
            return Http::response(['Fault' => ['Error' => [['Message' => 'Down']]]], 503);
        }

        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        if (str_ends_with($path, '/query')) {
            return match (true) {
                str_contains($query['query'], 'from Item') => Http::response(['QueryResponse' => ['Item' => [
                    ['Id' => '1', 'Name' => 'Services', 'Type' => 'Service', 'Taxable' => false],
                    ['Id' => '4', 'Name' => 'Change orders', 'Type' => 'Service', 'Taxable' => true],
                    ['Id' => '3', 'Name' => 'Painting services', 'Type' => 'Service', 'Taxable' => true],
                ]]]),
                $company['existingEmail'] !== null && str_contains($query['query'], 'from Customer') && str_contains($query['query'], $company['existingEmail']) => Http::response(['QueryResponse' => ['Customer' => [['Id' => '7', 'DisplayName' => 'Already Here']]]]),
                default => Http::response(['QueryResponse' => []]),
            };
        }

        if (str_ends_with($path, '/customer')) {
            $id = (string) $company['next']++;
            $company['customers'][$id] = $request->data();

            return Http::response(['Customer' => ['Id' => $id, ...$request->data()]]);
        }

        if (preg_match('#/estimate/(\d+)$#', $path, $found) === 1) {
            return isset($company['estimates'][$found[1]])
                ? Http::response(['Estimate' => $company['estimates'][$found[1]]])
                : Http::response(['Fault' => ['Error' => [['Message' => 'Object Not Found']]]], 400);
        }

        if (str_ends_with($path, '/estimate') && ($query['operation'] ?? null) === 'delete') {
            $company['deleted'][] = $request['Id'];
            unset($company['estimates'][$request['Id']]);

            return Http::response(['Estimate' => ['Id' => $request['Id'], 'status' => 'Deleted']]);
        }

        if (str_ends_with($path, '/estimate')) {
            $id = (string) ($request['Id'] ?? $company['next']++);
            $taxable = collect($request['Line'])->where('SalesItemLineDetail.TaxCodeRef.value', 'TAX')->sum('Amount');
            $net = collect($request['Line'])->sum('Amount');
            $parts = array_map(fn (float $percent): array => [
                'Amount' => round($taxable * $percent / 100, 2),
                'DetailType' => 'TaxLineDetail',
                'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => $percent, 'NetAmountTaxable' => $taxable],
            ], $taxable > 0 ? [4.875, 1.25, 2.0625] : []);
            $tax = array_sum(array_column($parts, 'Amount'));

            $company['estimates'][$id] = [
                ...($company['estimates'][$id] ?? []),
                ...$request->data(),
                'Id' => $id,
                'SyncToken' => (string) ((int) ($company['estimates'][$id]['SyncToken'] ?? -1) + 1),
                'TotalAmt' => round($net + $tax, 2),
                'TxnTaxDetail' => ['TotalTax' => $tax, 'TaxLine' => $parts],
            ];

            return Http::response(['Estimate' => $company['estimates'][$id]]);
        }

        return Http::response([], 404);
    }]);

    return $company;
}

/**
 * An interior estimate with one bedroom's walls: $352 before tax.
 */
function pricedEstimate(): Estimate
{
    $estimate = app(StartEstimate::class)->handle(test()->lead, EstimateType::ResidentialInterior->value, test()->sam);
    $room = app(SaveEstimateArea::class)->handle($estimate, $estimate->options()->firstOrFail(), null, ['name' => 'Bedroom', 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'], test()->sam);
    app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => test()->walls->id, 'coats' => '2', 'paint_product_id' => test()->paint->id], test()->sam);

    return $estimate->refresh();
}

test('each priced option becomes a QuickBooks estimate at the job\'s address, and its tax comes back', function () {
    $estimate = pricedEstimate();

    expect($estimate->options()->sole()->total_cents)->toBe(35200);

    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);

    $estimate->refresh();
    $option = $estimate->options()->sole();
    $kept = $this->quickBooks['estimates'][$option->quickbooks_estimate_id];

    // $352 at 8.1875% is $28.82.
    expect($estimate->tax_name)->toBe(Estimate::QUICKBOOKS_TAX_NAME)
        ->and((float) $estimate->tax_percent)->toBe(8.1875)
        ->and($estimate->taxIsFromQuickBooks())->toBeTrue()
        ->and($option->total_cents)->toBe(35200 + 2882)
        ->and($option->quickbooks_tax_cents)->toBe(2882)
        ->and($option->quickbooks_total_cents)->toBe(38082)
        ->and($this->lead->refresh()->quickbooks_customer_id)->toBe('100')
        ->and($this->quickBooks['customers']['100']['DisplayName'])->toBe('Pat Painter')
        ->and($this->connection->refresh()->work_item_name)->toBe('Painting services')
        // The tax is left for QuickBooks to work out, from the ship-to address.
        ->and($kept)->not->toHaveKey('TxnTaxDetail.TxnTaxCodeRef')
        ->and($kept['ShipAddr'])->toMatchArray(['Line1' => '800 Municipal Dr', 'City' => 'Farmington', 'CountrySubDivisionCode' => 'NM', 'PostalCode' => '87401'])
        ->and($kept['CustomerRef']['value'])->toBe('100')
        ->and($kept['Line'][0]['Amount'])->toEqual(352)
        ->and($kept['Line'][0]['SalesItemLineDetail']['ItemRef']['value'])->toBe('3');

    $recorded = AuditEvent::query()->where('action', AuditAction::LeadEstimateTaxFromQuickbooks)->sole();

    expect($recorded->subject_id)->toBe($this->lead->id)
        ->and($recorded->values_after)->toEqual(['tax' => 'Sales tax', 'percent' => '8.1875']);
});

test('sent again, an option keeps its one QuickBooks estimate, and a new option gets its own', function () {
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);
    $first = $estimate->options()->sole()->quickbooks_estimate_id;

    $second = app(SaveEstimateOption::class)->copy($estimate->refresh(), $estimate->options()->sole(), $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);

    expect($estimate->options()->pluck('quickbooks_estimate_id')->all())->toBe([$first, $second->refresh()->quickbooks_estimate_id])
        ->and($this->quickBooks['estimates'])->toHaveCount(2)
        ->and($this->quickBooks['estimates'][$first]['SyncToken'])->toBe('1')
        ->and($this->quickBooks['customers'])->toHaveCount(1)
        // The rate did not change, so it is not recorded a second time.
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateTaxFromQuickbooks)->count())->toBe(1);
});

test('a customer already in QuickBooks with the same email is used, not added again', function () {
    $this->quickBooks['existingEmail'] = 'pat@example.test';

    app(SyncEstimateToQuickBooks::class)->handle(pricedEstimate(), $this->sam);

    expect($this->lead->refresh()->quickbooks_customer_id)->toBe('7')
        ->and($this->quickBooks['customers'])->toBeEmpty();
});

test('nothing is sent without an address, without a price, or by someone who may not change the estimate', function (Closure $arrange, string $throws) {
    $estimate = $arrange();

    $actor = $throws === AuthorizationException::class ? $this->riley : $this->sam;

    expect(fn () => app(SyncEstimateToQuickBooks::class)->handle($estimate, $actor))->toThrow($throws);

    expect($this->quickBooks['requests'])->toBe(0)
        ->and($estimate->refresh()->taxIsFromQuickBooks())->toBeFalse();
})->with([
    'no address' => [function () {
        $estimate = pricedEstimate();
        test()->lead->forceFill(['address_city' => null])->save();

        return $estimate->refresh();
    }, QuickBooksUnavailable::class],
    'nothing priced' => [fn () => app(StartEstimate::class)->handle(test()->lead, EstimateType::ResidentialInterior->value, test()->sam), QuickBooksUnavailable::class],
    'another salesperson' => [fn () => pricedEstimate(), AuthorizationException::class],
]);

test('the rate from QuickBooks stays through discounts and repricing, until a rate is chosen from the list', function () {
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);

    app(ChangeEstimateDiscounts::class)->handle($estimate->refresh(), [], null, $this->sam);
    app(RepriceEstimate::class)->handle($estimate->refresh(), $this->sam);

    expect((float) $estimate->refresh()->tax_percent)->toBe(8.1875)
        ->and($estimate->taxIsFromQuickBooks())->toBeTrue();

    app(ChangeEstimateDiscounts::class)->handle($estimate, [], $this->listTax->id, $this->sam);

    expect($estimate->refresh()->tax_name)->toBe('Sample City Tax')
        ->and($estimate->taxIsFromQuickBooks())->toBeFalse();
});

test('on the estimate, the tax is fetched by itself once something is priced, and again when asked', function () {
    $estimate = app(StartEstimate::class)->handle($this->lead, EstimateType::ResidentialInterior->value, $this->sam);
    $room = app(SaveEstimateArea::class)->handle($estimate, $estimate->options()->firstOrFail(), null, ['name' => 'Bedroom', 'shape' => 'room', 'width' => '12', 'length' => '14', 'height' => '8'], $this->sam);
    app(SaveEstimateSurface::class)->handle($estimate, $room, null, ['production_rate_id' => $this->walls->id, 'coats' => '2', 'paint_product_id' => $this->paint->id], $this->sam);

    $page = Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $estimate])
        ->assertSeeHtml('data-test="quickbooks-tax"')
        ->assertDontSeeHtml('data-test="tax-rate"')
        ->assertSee('once something is priced')
        // Any change to the estimate fetches the rate the first time.
        ->call('saveDiscounts')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="quickbooks-tax-rate"')
        ->assertSee('8.1875%')
        ->assertSee('$380.82');

    expect($this->quickBooks['estimates'])->toHaveCount(1);

    $this->quickBooks['down'] = true;

    $page->call('fetchTax')->assertHasErrors('quickBooksTax');

    expect((float) $estimate->refresh()->tax_percent)->toBe(8.1875);
});

test('a Location without QuickBooks keeps the tax list on its estimates', function () {
    $this->connection->delete();
    $estimate = pricedEstimate();

    Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $estimate])
        ->assertSeeHtml('data-test="tax-rate"')
        ->assertDontSeeHtml('data-test="quickbooks-tax"');

    expect(app(SyncEstimateToQuickBooks::class)->bringUpToDate($estimate, $this->sam))->toBeNull()
        ->and($this->quickBooks['requests'])->toBe(0);
});

test('an option removed takes its QuickBooks estimate with it', function () {
    $estimate = pricedEstimate();
    $second = app(SaveEstimateOption::class)->copy($estimate, $estimate->options()->sole(), $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);
    $gone = $second->refresh()->quickbooks_estimate_id;

    Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $estimate])
        ->call('confirmRemove', 'option', $second->id)
        ->call('remove')
        ->assertHasNoErrors();

    expect($this->quickBooks['deleted'])->toBe([$gone])
        ->and($estimate->options()->count())->toBe(1);
});

test('before signing, QuickBooks sees the estimate as it is now, and signing waits when it cannot', function () {
    $estimate = pricedEstimate();
    $sync = app(SyncEstimateToQuickBooks::class);

    expect(SyncEstimateToQuickBooks::isBehind($estimate))->toBeTrue()
        ->and($sync->bringUpToDate($estimate, $this->sam))->toBeNull()
        ->and(SyncEstimateToQuickBooks::isBehind($estimate->refresh()))->toBeFalse();

    // Up to date: nothing more is asked of QuickBooks.
    $asked = $this->quickBooks['requests'];

    expect($sync->bringUpToDate($estimate, $this->sam))->toBeNull()
        ->and($this->quickBooks['requests'])->toBe($asked);

    // A change puts QuickBooks behind; while it does not answer, signing waits.
    $this->quickBooks['down'] = true;
    $this->travel(1)->minutes();
    $room = $estimate->options()->sole()->areas()->sole();
    app(SaveEstimateArea::class)->handle($estimate, $estimate->options()->sole(), $room, ['name' => 'Bedroom', 'shape' => 'room', 'width' => '12', 'length' => '16', 'height' => '8'], $this->sam);

    expect(SyncEstimateToQuickBooks::isBehind($estimate->refresh()))->toBeTrue();

    AgreementWording::factory()->create();

    Livewire::actingAs($this->sam)->test('pages::leads.estimate-sign', ['lead' => $this->lead, 'estimate' => $estimate, 'option' => $estimate->options()->sole()])
        ->assertSeeHtml('data-test="sign-unavailable"')
        ->assertSeeHtml('data-test="retry-quickbooks-tax"')
        ->assertSet('taxWaiting', 'QuickBooks did not answer for Farmington (503).')
        // Signing is refused on the server too, whatever the page shows.
        ->call('sign', [])
        ->assertNoRedirect();

    expect($this->lead->refresh()->agreements()->count())->toBe(0);
});
