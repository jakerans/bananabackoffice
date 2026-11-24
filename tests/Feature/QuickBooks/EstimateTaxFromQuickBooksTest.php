<?php

use App\Actions\Agreements\SignAgreement;
use App\Actions\Estimates\ChangeEstimateDeposit;
use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\RepriceEstimate;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateOption;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Actions\Projects\RecordPayments;
use App\Actions\Proposals\MarkLeadLost;
use App\Actions\Proposals\RecordProposalResult;
use App\Actions\Proposals\UndoLeadLost;
use App\Actions\QuickBooks\FinalizeSaleInQuickBooks;
use App\Actions\QuickBooks\MakeFinalInvoiceInQuickBooks;
use App\Actions\QuickBooks\ReadPaymentsFromQuickBooks;
use App\Actions\QuickBooks\SyncEstimateToQuickBooks;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Agreement;
use App\Models\AgreementWording;
use App\Models\AuditEvent;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Location;
use App\Models\Objection;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\Project;
use App\Models\ProjectChangeOrder;
use App\Models\ProjectInvoice;
use App\Models\ProjectPayment;
use App\Models\QuickBooksConnection;
use App\Models\RateCategory;
use App\Models\SalesLossReason;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Agreements\AgreementDetails;
use App\Support\Projects\Payments;
use App\Support\Queue\CallingHours;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
    $this->fran = User::factory()->withRoleAt(Role::Finance, $this->farmington)->create(['name' => 'Fran Finance']);
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
    $company = new ArrayObject(['estimates' => [], 'customers' => [], 'deleted' => [], 'down' => false, 'next' => 100, 'requests' => 0, 'existingEmail' => null, 'invoices' => [], 'emailed' => [], 'added' => [], 'hasDepositItem' => true, 'payments' => [], 'voided' => [], 'removedPayments' => []]);

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
                    ...($company['hasDepositItem'] ? [['Id' => '5', 'Name' => 'Deposit for Painting', 'Type' => 'Service', 'Taxable' => false]] : []),
                    ['Id' => '1', 'Name' => 'Services', 'Type' => 'Service', 'Taxable' => false],
                    ['Id' => '4', 'Name' => 'Change orders', 'Type' => 'Service', 'Taxable' => true],
                    ['Id' => '3', 'Name' => 'Painting services', 'Type' => 'Service', 'Taxable' => true],
                ]]]),
                str_contains($query['query'], 'from PaymentMethod') => Http::response(['QueryResponse' => ['PaymentMethod' => [
                    ['Id' => '1', 'Name' => 'Cash'], ['Id' => '2', 'Name' => 'Check'], ['Id' => '3', 'Name' => 'Visa'], ['Id' => '8', 'Name' => 'Financing'],
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

        if (preg_match('#/(invoice|estimate)/(\d+)/send$#', $path, $found) === 1) {
            $company['emailed'][] = [$found[1], $found[2], $query['sendTo'] ?? null];

            return Http::response([ucfirst($found[1]) => ['Id' => $found[2], 'EmailStatus' => 'EmailSent']]);
        }

        if (preg_match('#/invoice/(\d+)$#', $path, $found) === 1) {
            if (! isset($company['invoices'][$found[1]])) {
                return Http::response(['Fault' => ['Error' => [['Message' => 'Object Not Found']]]], 400);
            }

            // The payments against it, as QuickBooks lists them on the invoice.
            $links = collect($company['payments'])
                ->filter(fn (array $payment): bool => collect($payment['Line'])->pluck('LinkedTxn')->flatten(1)->contains('TxnId', $found[1]))
                ->map(fn (array $payment): array => ['TxnId' => $payment['Id'], 'TxnType' => 'Payment'])
                ->values()->all();

            return Http::response(['Invoice' => [...$company['invoices'][$found[1]], 'LinkedTxn' => $links]]);
        }

        if (str_ends_with($path, '/invoice') && ($query['operation'] ?? null) === 'void') {
            $company['voided'][] = $request['Id'];

            return Http::response(['Invoice' => ['Id' => $request['Id'], 'TotalAmt' => 0]]);
        }

        if (str_ends_with($path, '/invoice')) {
            $id = (string) $company['next']++;
            $taxable = collect($request['Line'])->where('SalesItemLineDetail.TaxCodeRef.value', 'TAX')->sum('Amount');
            $tax = array_sum(array_map(fn (float $percent): float => round($taxable * $percent / 100, 2), [4.875, 1.25, 2.0625]));
            $company['invoices'][$id] = [...$request->data(), 'Id' => $id, 'SyncToken' => '0', 'DocNumber' => '10'.$id, 'TotalAmt' => round(collect($request['Line'])->sum('Amount') + $tax, 2), 'TxnTaxDetail' => ['TotalTax' => $tax]];

            return Http::response(['Invoice' => $company['invoices'][$id]]);
        }

        if (preg_match('#/payment/(\d+)$#', $path, $found) === 1) {
            return isset($company['payments'][$found[1]])
                ? Http::response(['Payment' => $company['payments'][$found[1]]])
                : Http::response(['Fault' => ['Error' => [['Message' => 'Object Not Found']]]], 400);
        }

        if (str_ends_with($path, '/payment') && ($query['operation'] ?? null) === 'delete') {
            $company['removedPayments'][] = $request['Id'];
            unset($company['payments'][$request['Id']]);

            return Http::response(['Payment' => ['Id' => $request['Id'], 'status' => 'Deleted']]);
        }

        if (str_ends_with($path, '/payment')) {
            $id = (string) $company['next']++;
            $company['payments'][$id] = [...$request->data(), 'Id' => $id, 'SyncToken' => '0'];

            return Http::response(['Payment' => $company['payments'][$id]]);
        }

        if (str_ends_with($path, '/account') || str_ends_with($path, '/item')) {
            $kind = str_ends_with($path, '/account') ? 'Account' : 'Item';
            $id = (string) $company['next']++;
            $company['added'][] = [$kind, $request['Name']];

            return Http::response([$kind => ['Id' => $id, ...$request->data()]]);
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
            // A change of status alone keeps the lines QuickBooks already has.
            $data = ['TxnStatus' => 'Pending', ...($company['estimates'][$id] ?? []), ...$request->data()];
            $taxable = collect($data['Line'])->where('SalesItemLineDetail.TaxCodeRef.value', 'TAX')->sum('Amount');
            $net = collect($data['Line'])->sum('Amount');
            $parts = array_map(fn (float $percent): array => [
                'Amount' => round($taxable * $percent / 100, 2),
                'DetailType' => 'TaxLineDetail',
                'TaxLineDetail' => ['PercentBased' => true, 'TaxPercent' => $percent, 'NetAmountTaxable' => $taxable],
            ], $taxable > 0 ? [4.875, 1.25, 2.0625] : []);
            $tax = array_sum(array_column($parts, 'Amount'));

            $company['estimates'][$id] = [
                ...$data,
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

/*
|--------------------------------------------------------------------------
| Step 38c: the deposit, and the sale settled in QuickBooks
|--------------------------------------------------------------------------
*/

/**
 * The customer signs for the estimate's first option, at the appointment.
 */
function signAtTheAppointment(Estimate $estimate): Agreement
{
    AgreementWording::factory()->create();
    test()->travelTo(now()->timezone(CallingHours::timezoneFor(test()->farmington))->addDay()->setTime(15, 30));
    // A day on, the connection's key is renewed in real use; here it is simply still good.
    test()->connection->forceFill(['access_token_expires_at' => now()->addHour()])->save();

    return app(SignAgreement::class)->handle(
        test()->lead->fresh(),
        $estimate,
        $estimate->options()->firstOrFail(),
        'Pat Painter',
        true,
        [array_map(fn (int $step): array => [round(0.1 + $step * 0.05, 2), round(0.5 + ($step % 2) * 0.1, 2)], range(0, 9)), [[0.7, 0.4], [0.8, 0.6]]],
        test()->sam,
    );
}

test('the salesperson chooses another percent, a flat deposit or none, on the price before tax', function () {
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);
    $estimate->refresh();
    $option = $estimate->options()->sole();
    $details = fn (): array => app(AgreementDetails::class)->for($this->lead, $estimate->refresh(), $option->refresh(), null);

    // The Location's 50% of $352, not of the $380.82 with tax.
    expect($details())->toMatchArray(['total_cents' => 38082, 'price_before_tax_cents' => 35200, 'deposit_cents' => 17600, 'deposit_percent' => 50])
        ->and(AgreementDetails::depositLine($details()))->toBe('Deposit due on acceptance: $176 (50% of the price before tax).');

    $change = app(ChangeEstimateDeposit::class);

    $change->handle($estimate, 'percent', '30', $this->sam);
    expect($details())->toMatchArray(['deposit_cents' => 10560, 'deposit_percent' => 30]);

    $change->handle($estimate, 'flat', '100', $this->sam);
    expect($details())->toMatchArray(['deposit_cents' => 10000, 'deposit_percent' => null])
        ->and(AgreementDetails::depositLine($details()))->toBe('Deposit due on acceptance: $100.');

    $change->handle($estimate, 'none', null, $this->sam);
    expect($details())->toMatchArray(['deposit_cents' => 0])
        ->and(AgreementDetails::depositLine($details()))->toBe('No deposit is due on acceptance.');

    $change->handle($estimate, 'policy', null, $this->sam);
    expect($estimate->refresh()->depositIsChosen())->toBeFalse()
        ->and(AuditEvent::query()->where('action', AuditAction::LeadEstimateDepositChanged)->count())->toBe(4)
        ->and(fn () => $change->handle($estimate, 'percent', '0', $this->sam))->toThrow(ValidationException::class)
        ->and(fn () => $change->handle($estimate, 'flat', 'lots', $this->sam))->toThrow(ValidationException::class)
        ->and(fn () => $change->handle($estimate, 'percent', '30', $this->riley))->toThrow(AuthorizationException::class);
});

test('the deposit form on the estimate warns that a small deposit pays no commission advance', function () {
    $estimate = pricedEstimate();

    Livewire::actingAs($this->sam)->test('pages::leads.estimate', ['lead' => $this->lead, 'estimate' => $estimate])
        ->assertSeeHtml('data-test="estimate-deposit"')
        ->assertDontSeeHtml('data-test="deposit-no-advance"')
        ->set('depositKind', 'percent')
        ->set('depositValue', '20')
        ->call('saveDeposit')
        ->assertHasNoErrors()
        ->assertSeeHtml('data-test="deposit-no-advance"')
        ->set('depositValue', 'half')
        ->call('saveDeposit')
        ->assertHasErrors('depositValue');

    expect($estimate->refresh()->deposit_percent)->toBe(20);
});

test('signing sells the price before tax, accepts the chosen option in QuickBooks, removes the other, and QuickBooks emails a deposit invoice with no tax', function () {
    $estimate = pricedEstimate();
    $other = app(SaveEstimateOption::class)->copy($estimate, $estimate->options()->sole(), $this->sam);
    app(ChangeEstimateDeposit::class)->handle($estimate->refresh(), 'percent', '30', $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);
    $chosen = $estimate->options()->firstOrFail();
    $otherQuickBooksId = $other->refresh()->quickbooks_estimate_id;

    $agreement = signAtTheAppointment($estimate->refresh());

    $project = Project::query()->where('lead_id', $this->lead->id)->sole();
    $invoice = $project->invoices()->sole();
    $kept = collect($this->quickBooks['invoices'])->sole();

    // Sold for $352, the price before tax; the customer signed for $380.82 with it.
    expect($project->sold_cents)->toBe(35200)
        ->and($agreement->total_cents)->toBe(38082)
        ->and($agreement->deposit_cents)->toBe(10560)
        ->and(Payments::for($project)->requiredDepositCents())->toBe(10560)
        ->and($this->quickBooks['estimates'][$chosen->quickbooks_estimate_id]['TxnStatus'])->toBe('Accepted')
        ->and($this->quickBooks['deleted'])->toBe([$otherQuickBooksId])
        ->and($other->refresh()->quickbooks_estimate_id)->toBeNull()
        // The deposit invoice: $105.60 on the company's own deposit item, not taxed, payable online.
        ->and($kept['Line'][0]['Amount'])->toEqual(105.6)
        ->and($kept['Line'][0]['SalesItemLineDetail'])->toMatchArray(['ItemRef' => ['value' => '5'], 'TaxCodeRef' => ['value' => 'NON']])
        ->and($kept)->toMatchArray(['AllowOnlineCreditCardPayment' => true, 'AllowOnlineACHPayment' => true, 'CustomerRef' => ['value' => '100']])
        ->and($this->quickBooks['emailed'])->toBe([['invoice', $kept['Id'], 'pat@example.test']])
        ->and($this->quickBooks['added'])->toBeEmpty()
        ->and($invoice)->kind->toBe(ProjectInvoice::KIND_DEPOSIT)->number->toBe($kept['DocNumber'])->amount_cents->toBe(10560)->quickbooks_invoice_id->toBe($kept['Id'])
        ->and($invoice->quickbooks_emailed_at)->not->toBeNull()
        ->and($this->connection->refresh()->deposit_item_name)->toBe('Deposit for Painting');

    // Asked again, nothing is made or sent twice.
    app(FinalizeSaleInQuickBooks::class)->handle($project, $this->manager);

    expect($this->quickBooks['invoices'])->toHaveCount(1)
        ->and($this->quickBooks['emailed'])->toHaveCount(1);
});

test('a sale stands when QuickBooks does not answer, and the deposit invoice is sent later from the Project', function () {
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);
    $this->quickBooks['hasDepositItem'] = false;
    $this->quickBooks['down'] = true;

    // Nothing changed since QuickBooks last saw it, so signing does not wait for it.
    signAtTheAppointment($estimate->refresh());

    $project = Project::query()->where('lead_id', $this->lead->id)->sole();

    expect($project->invoices()->count())->toBe(0);

    $page = Livewire::actingAs($this->manager)->test('pages::projects.payments', ['project' => $project])
        ->assertSeeHtml('data-test="quickbooks-deposit"')
        ->call('sendDepositInvoice')
        ->assertHasErrors('quickBooksDeposit');

    $this->quickBooks['down'] = false;

    $page->call('sendDepositInvoice')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="quickbooks-deposit"');

    // A company with no deposit item gets one, on a liability account, so a deposit is not income.
    expect($project->invoices()->sole()->amount_cents)->toBe(17600)
        ->and($this->quickBooks['added'])->toBe([['Account', 'Customer Deposits'], ['Item', 'Customer deposit']])
        ->and($this->quickBooks['emailed'])->toHaveCount(1);

    // The salesperson does not record payments, so may not ask for it there.
    expect(fn () => app(FinalizeSaleInQuickBooks::class)->handle($project, $this->sam))->toThrow(AuthorizationException::class);
});

test('no deposit invoice is made when the deposit is waived', function () {
    $estimate = pricedEstimate();
    app(ChangeEstimateDeposit::class)->handle($estimate, 'none', null, $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);

    signAtTheAppointment($estimate->refresh());

    $project = Project::query()->where('lead_id', $this->lead->id)->sole();

    expect($this->quickBooks['invoices'])->toBeEmpty()
        ->and(Payments::for($project)->requiredDepositCents())->toBe(0)
        ->and(Payments::for($project)->depositEarnsAdvance())->toBeFalse();
});

/*
 * Step 38d: payments both ways, the final invoice, and what became of an
 * estimate followed in QuickBooks.
 */

/**
 * A job sold at $352 before tax with the Location's 50% deposit, $176,
 * invoiced in QuickBooks.
 *
 * @return array{0: Project, 1: ProjectInvoice}
 */
function soldWithDepositInvoice(): array
{
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, test()->sam);
    signAtTheAppointment($estimate->refresh());

    $project = Project::query()->where('lead_id', test()->lead->id)->sole();

    return [$project, $project->invoices()->sole()];
}

/**
 * The customer pays in QuickBooks, online: QuickBooks keeps a payment against the invoice.
 */
function paidInQuickBooks(ProjectInvoice $invoice, float $amount, string $methodId = '3', string $id = '900'): void
{
    test()->quickBooks['payments'][$id] = [
        'Id' => $id, 'SyncToken' => '0', 'TotalAmt' => $amount, 'TxnDate' => now()->toDateString(), 'PaymentMethodRef' => ['value' => $methodId],
        'Line' => [['Amount' => $amount, 'LinkedTxn' => [['TxnId' => $invoice->quickbooks_invoice_id, 'TxnType' => 'Invoice']]]],
    ];
}

test('a payment the customer made in QuickBooks is read onto the job, counts at once, and is reversed when QuickBooks removes it', function () {
    [$project, $deposit] = soldWithDepositInvoice();
    paidInQuickBooks($deposit, 176.00);

    $this->artisan('backoffice:quickbooks-payments')->assertSuccessful();
    $this->artisan('backoffice:quickbooks-payments')->assertSuccessful();

    $payment = $project->payments()->sole();

    expect($payment)->amount_cents->toBe(17600)->method->toBe('card')->from_quickbooks->toBeTrue()->quickbooks_payment_id->toBe('900')->recorded_by_name->toBe('QuickBooks')
        ->and($payment->counts())->toBeTrue()
        ->and(Payments::for($project)->state())->toBe(Payments::DEPOSIT_PAID)
        ->and($project->refresh()->deposit_paid_at)->not->toBeNull()
        // Nothing was sent back to QuickBooks for a payment that came from it.
        ->and($this->quickBooks['payments'])->toHaveCount(1);

    $recorded = AuditEvent::query()->where('action', AuditAction::ProjectPaymentRecorded)->sole();

    expect($recorded->values_after)->toMatchArray(['amount_cents' => 17600, 'read_from' => 'QuickBooks']);

    // Finance does not reverse it here: it is removed in QuickBooks, and Backoffice follows.
    expect(fn () => app(RecordPayments::class)->reversePayment($project, $payment->id, 'Wrong customer', $this->fran))->toThrow(ValidationException::class);

    unset($this->quickBooks['payments']['900']);

    $found = app(ReadPaymentsFromQuickBooks::class)->handle($project, $this->manager);

    expect($found)->toBe(['read' => 0, 'removed' => 1, 'sent' => 0])
        ->and($payment->refresh())->reversed_by_name->toBe('QuickBooks')->reversed_reason->toBe('Removed in QuickBooks.')
        ->and(Payments::for($project)->state())->toBe(Payments::DEPOSIT_DUE)
        ->and($project->refresh()->deposit_paid_at)->toBeNull();

    // Only someone who records this job's payments may ask.
    expect(fn () => app(ReadPaymentsFromQuickBooks::class)->handle($project, $this->riley))->toThrow(AuthorizationException::class);
});

test('a payment QuickBooks has no method name for is kept as paid in QuickBooks, at what was put toward this invoice', function () {
    [$project, $deposit] = soldWithDepositInvoice();
    paidInQuickBooks($deposit, 100.00, methodId: '77');
    // One payment split across two invoices: only this invoice's part is this job's.
    $this->quickBooks['payments']['900']['TotalAmt'] = 250.00;
    $this->quickBooks['payments']['900']['Line'][] = ['Amount' => 150.00, 'LinkedTxn' => [['TxnId' => '4444', 'TxnType' => 'Invoice']]];

    app(ReadPaymentsFromQuickBooks::class)->handle($project, $this->manager);

    expect($project->payments()->sole())->amount_cents->toBe(10000)->method->toBe(ProjectPayment::QUICKBOOKS)
        ->and($project->payments()->sole()->methodLabel())->toBe('Paid in QuickBooks');
});

test('a payment taken another way goes to QuickBooks once it counts, and a reversal takes it back out', function () {
    Storage::fake('files');
    [$project, $deposit] = soldWithDepositInvoice();
    $record = app(RecordPayments::class);
    $today = now()->toDateString();

    // A card run through Square counts at once.
    $card = $record->recordPayment($project, $deposit->id, '100', 'card', $today, 'SQ-7781', null, $this->manager);
    $sent = $this->quickBooks['payments'][$card->refresh()->quickbooks_payment_id];

    expect($sent)->toMatchArray(['CustomerRef' => ['value' => '100'], 'TotalAmt' => 100, 'TxnDate' => $today, 'PaymentRefNum' => 'SQ-7781', 'PaymentMethodRef' => ['value' => '3']])
        ->and($sent['Line'][0]['LinkedTxn'][0])->toBe(['TxnId' => $deposit->quickbooks_invoice_id, 'TxnType' => 'Invoice'])
        ->and($sent['PrivateNote'])->toContain('Backoffice payment #'.$card->id);

    // A check waits for Finance, and goes to QuickBooks only once approved.
    $check = $record->recordPayment($project, $deposit->id, '76', 'check', $today, '2231', UploadedFile::fake()->image('Check.jpg'), $this->manager);

    expect($check->refresh()->quickbooks_payment_id)->toBeNull()
        ->and($this->quickBooks['payments'])->toHaveCount(1);

    $record->approvePayment($project, $check->id, $this->fran);

    expect($this->quickBooks['payments'][$check->refresh()->quickbooks_payment_id])->toMatchArray(['TotalAmt' => 76, 'PaymentMethodRef' => ['value' => '2']]);

    // Reading QuickBooks afterwards finds its own two payments again, and adds nothing.
    expect(app(ReadPaymentsFromQuickBooks::class)->handle($project, $this->manager))->toBe(['read' => 0, 'removed' => 0, 'sent' => 0])
        ->and($project->payments()->count())->toBe(2);

    $cardId = $card->quickbooks_payment_id;
    $record->reversePayment($project, $card->id, 'The card was declined.', $this->fran);

    expect($this->quickBooks['removedPayments'])->toBe([$cardId])
        ->and($card->refresh()->quickbooks_payment_id)->toBeNull();
});

test('a payment recorded while QuickBooks does not answer stands, and is sent when QuickBooks is checked', function () {
    [$project, $deposit] = soldWithDepositInvoice();
    $this->quickBooks['down'] = true;

    $payment = app(RecordPayments::class)->recordPayment($project, $deposit->id, '176', 'financing', now()->toDateString(), 'LN-20931', null, $this->manager);

    expect($payment->refresh()->quickbooks_payment_id)->toBeNull()
        ->and(Payments::for($project)->state())->toBe(Payments::DEPOSIT_PAID);

    $page = Livewire::actingAs($this->manager)->test('pages::projects.payments', ['project' => $project])
        ->assertSee('not in QuickBooks yet')
        ->call('checkQuickBooks')
        ->assertHasErrors('quickBooksCheck');

    $this->quickBooks['down'] = false;

    $page->call('checkQuickBooks')->assertHasNoErrors()->assertDontSee('not in QuickBooks yet');

    expect($this->quickBooks['payments'][$payment->refresh()->quickbooks_payment_id])->toMatchArray(['TotalAmt' => 176, 'PaymentMethodRef' => ['value' => '8']]);
});

test('the final invoice is made in QuickBooks: the work and each signed change order taxed in full, the deposit taken off, and emailed', function () {
    [$project, $deposit] = soldWithDepositInvoice();
    (new ProjectChangeOrder)->forceFill(['project_id' => $project->id, 'number' => 1, 'description' => 'Paint the closet too', 'price_change_cents' => 4800, 'status' => ProjectChangeOrder::STATUS_SIGNED])->save();
    (new ProjectChangeOrder)->forceFill(['project_id' => $project->id, 'number' => 2, 'description' => 'Never agreed', 'price_change_cents' => 9900, 'status' => ProjectChangeOrder::STATUS_DRAFT])->save();
    $chosen = $project->proposal->estimate_option_id;
    $estimateId = EstimateOption::query()->findOrFail($chosen)->quickbooks_estimate_id;

    Livewire::actingAs($this->manager)->test('pages::projects.payments', ['project' => $project])
        ->assertSeeHtml('data-test="make-final-invoice"')
        ->assertDontSeeHtml('data-test="record-final-invoice"')
        ->call('openFinal')
        ->call('makeFinalInvoice')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="make-final-invoice"');

    $final = $project->invoices()->where('kind', ProjectInvoice::KIND_FINAL)->sole();
    $kept = $this->quickBooks['invoices'][$final->quickbooks_invoice_id];

    // $352 and $48 of work: $400 taxed at 8.1875% is $32.75. Less the $176 deposit: $256.75.
    expect($kept['Line'])->toHaveCount(3)
        ->and($kept['Line'][0])->toMatchArray(['Amount' => 352, 'SalesItemLineDetail' => ['ItemRef' => ['value' => '3'], 'Qty' => 1, 'UnitPrice' => 352, 'TaxCodeRef' => ['value' => 'TAX']]])
        ->and($kept['Line'][1]['Amount'])->toEqual(48)
        ->and($kept['Line'][1]['Description'])->toBe('Change order 1: Paint the closet too')
        ->and($kept['Line'][2])->toMatchArray(['Amount' => -176, 'SalesItemLineDetail' => ['ItemRef' => ['value' => '5'], 'Qty' => 1, 'UnitPrice' => -176, 'TaxCodeRef' => ['value' => 'NON']]])
        ->and($kept['LinkedTxn'])->toBe([['TxnId' => $estimateId, 'TxnType' => 'Estimate']])
        ->and($kept['ShipAddr']['City'])->toBe('Farmington')
        ->and($kept)->toMatchArray(['AllowOnlineCreditCardPayment' => true, 'AllowOnlineACHPayment' => true, 'DueDate' => now()->timezone(CallingHours::timezoneFor($this->farmington))->addDays(7)->toDateString()])
        ->and($kept)->not->toHaveKey('TxnTaxDetail.TxnTaxCodeRef')
        ->and($final)->amount_cents->toBe(25675)->number->toBe($kept['DocNumber'])
        ->and($final->quickbooks_emailed_at)->not->toBeNull()
        ->and($this->quickBooks['emailed'])->toContain(['invoice', $kept['Id'], 'pat@example.test'])
        // Everything invoiced is the work and its tax: $432.75.
        ->and(Payments::for($project)->invoicedCents())->toBe(43275)
        ->and(Payments::for($project)->state())->toBe(Payments::BALANCE_DUE);

    expect(AuditEvent::query()->where('action', AuditAction::ProjectInvoiceRecorded)->latest('id')->firstOrFail()->values_after)
        ->toMatchArray(['amount_cents' => 25675, 'tax_cents' => 3275, 'made_in' => 'QuickBooks']);

    // Not twice, and not by someone who does not record this job's payments.
    expect(fn () => app(MakeFinalInvoiceInQuickBooks::class)->handle($project, $this->manager))->toThrow(QuickBooksUnavailable::class)
        ->and(fn () => app(MakeFinalInvoiceInQuickBooks::class)->handle($project, $this->riley))->toThrow(AuthorizationException::class)
        ->and($this->quickBooks['invoices'])->toHaveCount(2);
});

test('a final invoice stands when QuickBooks cannot email it, and is emailed later from the job', function () {
    [$project] = soldWithDepositInvoice();
    $this->lead->contact?->forceFill(['email' => null])->save();
    $this->lead->forceFill(['inquiry_email' => null])->save();

    $final = app(MakeFinalInvoiceInQuickBooks::class)->handle($project, $this->manager);

    expect($final->quickbooks_emailed_at)->toBeNull();

    $this->lead->forceFill(['inquiry_email' => 'pat@example.test'])->save();

    Livewire::actingAs($this->manager)->test('pages::projects.payments', ['project' => $project])
        ->assertSeeHtml('data-test="email-invoice"')
        ->call('emailInvoice', $final->id)
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-test="email-invoice"');

    expect($final->refresh()->quickbooks_emailed_at)->not->toBeNull();
});

test('a job whose deposit invoice was recorded by hand keeps its final invoice by hand', function () {
    $estimate = pricedEstimate();
    app(SyncEstimateToQuickBooks::class)->handle($estimate, $this->sam);
    $this->quickBooks['down'] = true;
    signAtTheAppointment($estimate->refresh());
    $this->quickBooks['down'] = false;

    $project = Project::query()->where('lead_id', $this->lead->id)->sole();
    app(RecordPayments::class)->recordInvoice($project, 'deposit', '1001', '176', now()->toDateString(), $this->manager);

    Livewire::actingAs($this->manager)->test('pages::projects.payments', ['project' => $project])
        ->assertDontSeeHtml('data-test="make-final-invoice"')
        ->assertSeeHtml('data-test="record-final-invoice"');

    expect(fn () => app(MakeFinalInvoiceInQuickBooks::class)->handle($project, $this->manager))->toThrow(QuickBooksUnavailable::class)
        ->and($this->quickBooks['invoices'])->toBeEmpty();
});

test('voiding an invoice made in QuickBooks voids it there, and it is not voided here when QuickBooks cannot', function () {
    [$project, $deposit] = soldWithDepositInvoice();
    $this->quickBooks['down'] = true;

    expect(fn () => app(RecordPayments::class)->voidInvoice($project, $deposit->id, 'Wrong amount', $this->manager))->toThrow(ValidationException::class)
        ->and($deposit->refresh()->isVoided())->toBeFalse();

    $this->quickBooks['down'] = false;

    // Without a reason nothing is voided anywhere.
    expect(fn () => app(RecordPayments::class)->voidInvoice($project, $deposit->id, '', $this->manager))->toThrow(ValidationException::class)
        ->and($this->quickBooks['voided'])->toBeEmpty();

    app(RecordPayments::class)->voidInvoice($project, $deposit->id, 'Wrong amount', $this->manager);

    expect($this->quickBooks['voided'])->toBe([$deposit->quickbooks_invoice_id])
        ->and($deposit->refresh()->isVoided())->toBeTrue();
});

test('a lost lead\'s QuickBooks estimates are marked rejected, not deleted, and pending again when the lead is taken back', function () {
    $estimate = pricedEstimate();
    $this->travelTo(now()->timezone(CallingHours::timezoneFor($this->farmington))->addDay()->setTime(15, 30));
    $this->connection->forceFill(['access_token_expires_at' => now()->addHour()])->save();
    runAppointment($this->lead->fresh(), $this->sam);
    presentEstimate($estimate->refresh(), $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);
    $quickBooksId = $estimate->options()->sole()->quickbooks_estimate_id;

    $lost = ['lossReasonId' => SalesLossReason::query()->where('name', 'Price')->sole()->id, 'objectionId' => Objection::query()->where('name', 'Wants to think it over')->sole()->id];
    app(MarkLeadLost::class)->handle($this->lead->fresh(), $lost, $this->sam);

    expect($this->quickBooks['estimates'][$quickBooksId]['TxnStatus'])->toBe('Rejected')
        ->and($this->quickBooks['deleted'])->toBeEmpty();

    app(UndoLeadLost::class)->handle($this->lead->fresh(), 'They called back.', $this->manager);

    expect($this->quickBooks['estimates'][$quickBooksId]['TxnStatus'])->toBe('Pending');

    // QuickBooks not answering never stops a lead being marked lost.
    $this->quickBooks['down'] = true;
    app(MarkLeadLost::class)->handle($this->lead->fresh(), $lost, $this->sam);

    expect($this->lead->fresh()->status)->toBe(LeadStatus::Lost);
});

test('a sale marked on the lead\'s card is settled in QuickBooks too', function () {
    $estimate = pricedEstimate();
    $this->travelTo(now()->timezone(CallingHours::timezoneFor($this->farmington))->addDay()->setTime(15, 30));
    $this->connection->forceFill(['access_token_expires_at' => now()->addHour()])->save();
    runAppointment($this->lead->fresh(), $this->sam);
    $proposal = presentEstimate($estimate->refresh(), $this->sam);
    app(SyncEstimateToQuickBooks::class)->handle($estimate->refresh(), $this->sam);
    $option = $estimate->options()->sole();

    app(RecordProposalResult::class)->handle($this->lead->fresh(), $proposal, 'sold', ['optionId' => $option->id], $this->sam);

    $project = Project::query()->where('lead_id', $this->lead->id)->sole();

    expect($this->quickBooks['estimates'][$option->quickbooks_estimate_id]['TxnStatus'])->toBe('Accepted')
        ->and($project->invoices()->sole())->kind->toBe(ProjectInvoice::KIND_DEPOSIT)->amount_cents->toBe(17600)
        ->and($this->quickBooks['emailed'])->toHaveCount(1);
});
