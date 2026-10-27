<?php

use App\Support\Pricing\PricingEngine;
use Tests\Support\PaintScoutQuote;

/*
 * The pricing engine against Banana's own PaintScout test estimates, to the
 * cent (docs/slice-31-estimating-plan.md, step 31b; decision 197, question 5).
 * They hold real prices, so they are kept outside the repository, in
 * docs/reference/paintscout/tests/, and these tests run only on a computer
 * that has them. Everywhere else they are skipped.
 */

$saved = glob(dirname(__DIR__, 3).'/docs/reference/paintscout/tests/*.json') ?: [];

test('a saved PaintScout estimate prices the same in Backoffice', function (string $path): void {
    $quote = PaintScoutQuote::fromFile($path);
    $priced = app(PricingEngine::class)->price($quote->estimate());
    $totals = $quote->quote['totals'];

    foreach ($quote->areas() as $index => ['area' => $area, 'surfaces' => $surfaces]) {
        $pricedArea = $priced->areas[$index];
        $name = data_get($area, 'area_label.value');

        foreach ($surfaces as $position => $surface) {
            $pricedSurface = $pricedArea->surfaces[$position];
            $label = $name.' / '.$surface['rate']['label'];

            expect($pricedSurface->hours)->toEqual((float) $surface['default_hours'], $label.': hours')
                ->and($pricedSurface->workCents)->toBe((int) round($surface['default_price'] * 100), $label.': labor or price');

            if (isset($surface['products'][0])) {
                expect($pricedSurface->gallons)->toBe((float) $surface['products'][0]['volume'], $label.': gallons')
                    ->and($pricedSurface->materialsCents)->toBe((int) round($surface['products'][0]['totalPrice'] * 100), $label.': paint');
            }
        }

        expect($pricedArea->hours)->toEqual((float) $area['totals']['hours'], $name.': hours')
            ->and($pricedArea->workCents)->toBe((int) round($area['totals']['price'] * 100), $name.': labor')
            ->and($pricedArea->materialsCents)->toBe((int) round($area['totals']['materials'] * 100), $name.': paint');
    }

    expect($priced->hours)->toEqual((float) $totals['hours'], 'hours')
        ->and($priced->materialsCents)->toBe((int) round($totals['materials'] * 100), 'paint')
        ->and($priced->subtotalCents)->toBe((int) round($totals['price'] * 100), 'price before discount')
        ->and($priced->discountCents)->toBe((int) round($totals['total_discount'] * 100), 'discount')
        ->and($priced->taxCents)->toBe((int) round($totals['total_tax'] * 100), 'tax')
        ->and($priced->totalCents)->toBe((int) round($totals['after_tax'] * 100), 'total');
})->with(array_combine(array_map('basename', $saved), $saved) ?: ['no saved estimates on this computer' => ''])
    ->skip($saved === [], 'Banana\'s PaintScout test estimates are kept outside the repository.');
