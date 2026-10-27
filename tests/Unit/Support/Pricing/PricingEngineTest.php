<?php

use App\Enums\AreaShape;
use App\Enums\DiscountKind;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Support\Pricing\AreaInput;
use App\Support\Pricing\DiscountInput;
use App\Support\Pricing\EstimateInput;
use App\Support\Pricing\LineItemInput;
use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\PricingEngine;
use App\Support\Pricing\RateSnapshot;
use App\Support\Pricing\SurfaceInput;

/*
 * The pricing engine's rules, step 31b (docs/slice-31-estimating-plan.md),
 * with made-up rates: Banana's real prices never enter the repository
 * (decision 197, question 5). PaintScoutMatchTest checks the same rules
 * against Banana's own PaintScout estimates on a computer that has them.
 */

/**
 * @param  list<string|null>  $values
 */
function sampleRate(RateMeasure $measure, RateCalculation $calculation, array $values, bool $usesCoats = false, array $changes = []): RateSnapshot
{
    return new RateSnapshot(...['name' => 'Sample rate', 'measure' => $measure, 'calculation' => $calculation, 'values' => $values, 'usesCoats' => $usesCoats, ...$changes]);
}

function samplePaint(): PaintSnapshot
{
    // $30.50 a gallon, 250 sq ft or 400 ft of trim to the gallon, a fifth of a gallon an item.
    return new PaintSnapshot('Sample paint', 3050, '250', '400', '0.2');
}

/**
 * @param  list<SurfaceInput>  $surfaces
 */
function sampleRoom(array $surfaces, AreaShape $shape = AreaShape::Room, bool $isOptional = false): AreaInput
{
    return new AreaInput('Sample room', $shape, '13.58', '10.25', '9', $surfaces, $isOptional);
}

test('a room is measured as PaintScout measures it, without rounding', function () {
    $priced = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([]), sampleRoom([], AreaShape::Surface)]));

    [$room, $surface] = $priced->areas;

    expect($room->measurements)
        ->linearFeet->toBe(47.66)
        ->wallArea->toBe(428.93999999999994)
        ->ceilingArea->toBe(139.195)
        ->floorArea->toBe(139.195)
        ->and($surface->measurements)
        ->linearFeet->toBe(10.25)
        ->wallArea->toBe(92.25)
        ->ceilingArea->toBe(139.195);
});

test('a surface\'s hours are rounded up to the whole hour and its paint cost up to the whole dollar', function () {
    $walls = new SurfaceInput(sampleRate(RateMeasure::WallArea, RateCalculation::UnitsPerHour, ['150', '90', '60'], usesCoats: true), samplePaint(), coats: 2);

    $surface = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$walls])]))->areas[0]->surfaces[0];

    // 428.94 sq ft at 90 an hour for two coats is 4.77 hours, so 5; two coats over 250 sq ft a gallon is 3.43 gallons, $104.65, so $105.
    expect($surface->value)->toBe(90.0)
        ->and($surface)
        ->hours->toBe(5.0)
        ->workCents->toBe(25000)
        ->gallons->toBe(428.93999999999994 * 2 / 250)
        ->materialsCents->toBe(10500)
        ->and($surface->totalCents())->toBe(35500);
});

test('each way a rate is counted gives its own hours, and coats count only for a rate that uses them', function () {
    $estimate = new EstimateInput(5000, [sampleRoom([
        // Trim by the length around: 47.66 ft at 20 an hour is 2.38 hours, so 3; paint 47.66 / 400 a gallon.
        new SurfaceInput(sampleRate(RateMeasure::LinearFeet, RateCalculation::UnitsPerHour, ['20']), samplePaint()),
        // A count always multiplies, even counted "per hour": 4 doors at 0.33 is 1.32 hours, so 2.
        new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::UnitsPerHour, ['0.33', '0.2']), samplePaint(), coats: 2, quantity: '4'),
        // Hours for each: 3 repairs at 1.5 hours.
        new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::HoursPerUnit, ['1.5']), quantity: '3'),
        // Hours per square foot of ceiling: 139.195 at 0.01 is 1.39 hours, so 2.
        new SurfaceInput(sampleRate(RateMeasure::CeilingArea, RateCalculation::HoursPerUnit, ['0.01'])),
    ])]);

    [$trim, $doors, $repairs, $ceiling] = (new PricingEngine)->price($estimate)->areas[0]->surfaces;

    expect([$trim->hours, $doors->hours, $repairs->hours, $ceiling->hours])->toBe([3.0, 2.0, 5.0, 2.0])
        ->and($trim->gallons)->toBe(47.66 / 400)
        ->and($trim->materialsCents)->toBe(400)
        // The doors' rate does not use coats, so two coats are one: its first value, and paint for one coat.
        ->and($doors->coats)->toBe(1)
        ->and($doors->gallons)->toBe(0.8)
        ->and($doors->materialsCents)->toBe(2500)
        ->and($repairs->materialsCents)->toBe(0);
});

test('a rate priced per item has no hours and no paint', function () {
    $cabinets = new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::PricePerUnit, ['125.5']), samplePaint(), quantity: '6');

    $surface = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$cabinets])]))->areas[0]->surfaces[0];

    expect($surface)->hours->toBe(0.0)->workCents->toBe(75300)->materialsCents->toBe(0);
});

test('a surface with its own hourly rate is charged at it', function () {
    $ceiling = new SurfaceInput(sampleRate(RateMeasure::CeilingArea, RateCalculation::UnitsPerHour, ['100'], changes: ['hourlyRateCents' => 7500]));

    expect((new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$ceiling])]))->areas[0]->surfaces[0]->workCents)->toBe(15000);
});

test('optional work is priced but left out of the total', function () {
    $walls = fn (bool $isOptional = false): SurfaceInput => new SurfaceInput(sampleRate(RateMeasure::WallArea, RateCalculation::UnitsPerHour, ['150']), samplePaint(), isOptional: $isOptional);

    $priced = (new PricingEngine)->price(new EstimateInput(5000, [
        sampleRoom([$walls(), $walls(isOptional: true)]),
        sampleRoom([$walls()], isOptional: true),
    ], [new LineItemInput('Sample extra', priceCents: 9900, isOptional: true)]));

    // Each walls surface is 3 hours ($150) and $53 of paint.
    expect($priced->areas[0])->hours->toBe(3.0)->workCents->toBe(15000)->materialsCents->toBe(5300)->optionalCents->toBe(20300)
        ->and($priced->areas[1])->hours->toBe(0.0)->optionalCents->toBe(20300)
        ->and($priced)->hours->toBe(3.0)->subtotalCents->toBe(20300)->totalCents->toBe(20300)->optionalCents->toBe(50500);
});

test('a line item\'s price is rounded up to the whole dollar, however it is priced', function () {
    $priced = (new PricingEngine)->price(new EstimateInput(5000, [], [
        // 1.75 hours at its own $210.50 an hour is $368.375, so $369; the hours stay as typed.
        new LineItemInput('Sample consultation', hours: '1.75', hourlyRateCents: 21050),
        // A set price of $249.40 is charged as $250.
        new LineItemInput('Sample samples', priceCents: 24940),
        // 3 at 0.4 hours and $33.33 each: 1.2 hours, so 2, and $99.99, so $100.
        new LineItemInput('Sample patches', quantity: '3', hoursEach: '0.4', priceEachCents: 3333),
    ]));

    expect(array_map(fn ($item): array => [$item->hours, $item->priceCents], $priced->lineItems))->toBe([[1.75, 36900], [0.0, 25000], [2.0, 10000]])
        ->and($priced)->hours->toBe(3.75)->lineItemsCents->toBe(71900)->subtotalCents->toBe(71900);
});

test('a percentage discount is rounded up to the whole dollar and leaves out what may not be discounted', function () {
    $walls = new SurfaceInput(sampleRate(RateMeasure::WallArea, RateCalculation::UnitsPerHour, ['150']), samplePaint());
    $repairs = new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::HoursPerUnit, ['1'], changes: ['excludedFromDiscount' => true]), quantity: '2');

    $priced = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$walls, $repairs])], [], [
        new DiscountInput('Sample savings', DiscountKind::Percentage, '7'),
        new DiscountInput('Sample cash savings', DiscountKind::Amount, '25'),
    ]));

    // $203 can be discounted, the repairs' $100 cannot: 7% of $203 is $14.21, so $15; then $25 off.
    expect($priced)->subtotalCents->toBe(30300)->discountCents->toBe(4000)->totalCents->toBe(26300)
        ->and(array_column($priced->discounts, 'cents'))->toBe([1500, 2500]);
});

test('tax is charged on the total after the discount, rounded to the cent, leaving out what is not taxed', function () {
    $walls = new SurfaceInput(sampleRate(RateMeasure::WallArea, RateCalculation::UnitsPerHour, ['150']), samplePaint());
    $repairs = new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::HoursPerUnit, ['1'], changes: ['excludedFromTax' => true]), quantity: '2');

    $priced = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$walls, $repairs])], [], [
        new DiscountInput('Sample cash savings', DiscountKind::Amount, '3'),
    ], taxPercent: '6.122'));

    // $303 less $3 is $300, of which $200 is taxed: $12.244, so $12.24, never rounded up.
    expect($priced)->taxCents->toBe(1224)->totalCents->toBe(31224);
});

test('prep hours are not rounded and are counted beside the painting hours', function () {
    $doors = new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::HoursPerUnit, ['0.75'], changes: ['prepHours' => '0.5']), quantity: '3');
    $typedPrep = new SurfaceInput(sampleRate(RateMeasure::Count, RateCalculation::HoursPerUnit, ['0.75'], changes: ['prepHours' => '0.5']), quantity: '3', prepHours: '1.5');

    $priced = (new PricingEngine)->price(new EstimateInput(5000, [sampleRoom([$doors, $typedPrep])]));
    [$fromRate, $fromEstimate] = $priced->areas[0]->surfaces;

    // 2.25 painting hours round up to 3; the prep the estimate typed replaces the rate's.
    expect([$fromRate->hours, $fromRate->prepHours, $fromRate->workCents])->toBe([3.0, 0.5, 17500])
        ->and([$fromEstimate->hours, $fromEstimate->prepHours, $fromEstimate->workCents])->toBe([3.0, 1.5, 22500])
        ->and($priced->areas[0])->hours->toBe(6.0)->prepHours->toBe(2.0)
        ->and($priced)->hours->toBe(6.0)->prepHours->toBe(2.0);
});
