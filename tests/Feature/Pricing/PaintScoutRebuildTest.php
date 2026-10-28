<?php

use App\Actions\Estimates\ChangeEstimateDiscounts;
use App\Actions\Estimates\ChangeEstimateHourlyRate;
use App\Actions\Estimates\SaveEstimateArea;
use App\Actions\Estimates\SaveEstimateLineItem;
use App\Actions\Estimates\SaveEstimateSurface;
use App\Actions\Estimates\StartEstimate;
use App\Actions\Pricing\ImportPaintScoutSettings;
use App\Enums\EstimateType;
use App\Enums\Role;
use App\Models\Discount;
use App\Models\Location;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\TaxRate;
use App\Models\User;
use Tests\Support\PaintScoutQuote;

/*
 * Banana's PaintScout test estimates built again as Backoffice estimates,
 * from Banana's own saved PaintScout settings, through the same actions the
 * estimate page uses (docs/slice-31-estimating-plan.md, step 31c): each must
 * come to PaintScout's total to the cent. Real prices stay outside the
 * repository (decision 197, question 5), so this runs only on a computer
 * that has them; everywhere else it is skipped.
 */

$reference = dirname(__DIR__, 3).'/docs/reference/paintscout';
$saved = is_file($reference.'/paintscout-abq-settings.json') ? (glob($reference.'/tests/*.json') ?: []) : [];

test('a PaintScout test estimate built in Backoffice comes to the same total', function (string $path) use ($reference): void {
    $settings = json_decode((string) file_get_contents($reference.'/paintscout-abq-settings.json'), true, 512, JSON_THROW_ON_ERROR);
    $book = app(ImportPaintScoutSettings::class)->handle($settings)['book'];

    $location = Location::factory()->create();
    $manager = User::factory()->withRoleAt(Role::LocationManager, $location)->create();
    $seller = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create();
    $lead = bookableLead($location);
    bookAppointment($lead, $seller, $manager);

    $quote = PaintScoutQuote::fromFile($path)->quote;
    $type = ($quote['int_ext']['value'] ?? 'int') === 'ext' ? EstimateType::ResidentialExterior : EstimateType::ResidentialInterior;
    $estimate = app(StartEstimate::class)->handle($lead->fresh(), $type->value, $seller);
    $option = $estimate->options()->sole();

    if ((int) round((float) $quote['hourly_rate'] * 100) !== $estimate->hourly_rate_cents) {
        app(ChangeEstimateHourlyRate::class)->handle($estimate, (string) $quote['hourly_rate'], $manager);
    }

    $readable = PaintScoutQuote::fromFile($path);

    foreach ($readable->areas() as ['area' => $area, 'surfaces' => $surfaces]) {
        if (($area['use_total'] ?? true) === false) {
            continue;
        }

        $saved = app(SaveEstimateArea::class)->handle($estimate, $option, null, [
            'name' => (string) data_get($area, 'area_label.value'),
            'shape' => data_get($area, 'area_type.value') === 'surface' ? 'surface' : 'room',
            'width' => (string) $area['dimensions']['width'],
            'length' => (string) $area['dimensions']['length'],
            'height' => (string) $area['dimensions']['height'],
        ], $manager);

        foreach ($surfaces as $surface) {
            if (($surface['use_total'] ?? true) === false) {
                continue;
            }

            $rate = ProductionRate::query()->where('price_book_id', $book->id)
                ->when(isset($surface['rate']['name']), fn ($query) => $query->where('source_key', $surface['rate']['name']), fn ($query) => $query->where('name', $surface['rate']['label']))
                ->get()
                ->first(fn (ProductionRate $candidate): bool => array_map(floatval(...), array_slice($candidate->values(), 0, count($surface['rate']['values']))) === array_map(floatval(...), $surface['rate']['values']));
            $product = $surface['products'][0]['product'] ?? null;
            $paint = $product === null ? null : PaintProduct::query()->where('price_book_id', $book->id)
                ->where(fn ($query) => $query->where('source_key', $product['name'] ?? '')->orWhere('name', $product['label']))->first();

            expect($rate)->not->toBeNull($surface['rate']['label']);

            app(SaveEstimateSurface::class)->handle($estimate, $saved, null, [
                'production_rate_id' => $rate->id,
                'coats' => (string) $surface['coats'],
                'quantity' => (string) $surface['quantity'],
                'paint_product_id' => $paint?->id,
                'prep_hours' => (float) ($surface['prep'] ?? 0) > 0 ? (string) $surface['prep'] : null,
            ], $manager);
        }
    }

    foreach ($readable->lineItems() as $item) {
        app(SaveEstimateLineItem::class)->handle($estimate, $option, null, match ($item['calculateBy'] ?? null) {
            'hourlyRate' => ['name' => $item['name'], 'pricing' => 'hours', 'hours' => (string) $item['hours'], 'hourly_rate' => ($item['customHourlyRate'] ?? false) ? (string) $item['hourlyRate'] : null],
            'quantity' => ['name' => $item['name'], 'pricing' => 'quantity', 'quantity' => (string) $item['quantity'], 'hours_each' => (string) $item['hoursPerUnit'], 'price_each' => (string) $item['pricePerUnit']],
            default => ['name' => $item['name'], 'pricing' => 'price', 'price' => (string) $item['price']],
        }, $manager);
    }

    $discounts = Discount::query()->where('price_book_id', $book->id)->whereIn('name', array_column($quote['discounts'] ?? [], 'description'))->pluck('id')->all();
    $taxName = data_get($quote, 'tax_details.0.description');
    $tax = $taxName === null ? null : TaxRate::query()->where('price_book_id', $book->id)->where('name', $taxName)->value('id');

    if ($discounts !== [] || $tax !== null) {
        app(ChangeEstimateDiscounts::class)->handle($estimate, $discounts, $tax, $manager);
    }

    expect($option->fresh()->total_cents)->toBe((int) round($quote['totals']['after_tax'] * 100));
})->with(array_combine(array_map('basename', $saved), $saved) ?: ['no saved estimates on this computer' => ''])
    ->skip($saved === [], 'Banana\'s PaintScout settings and test estimates are kept outside the repository.');
