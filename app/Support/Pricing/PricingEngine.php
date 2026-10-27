<?php

namespace App\Support\Pricing;

use App\Enums\DiscountKind;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;

/**
 * Works out an estimate's price exactly as PaintScout does, to the cent
 * (docs/slice-31-estimating-plan.md, step 31b; decision 196).
 *
 * The rules, each matched against Banana's own PaintScout estimates:
 *
 * - An area's amounts come from its measurements (AreaMeasurements).
 * - A rate counted per hour divides the area's amount by its value; one
 *   counted in hours multiplies. For a count of items both multiply: a door
 *   rate of 0.5 is half an hour for each door.
 * - A surface's hours are rounded up to the whole hour, and its labor is
 *   those hours times the hourly rate (its own, or the estimate's).
 * - Its paint is worked out in exact gallons (the amount times the coats over
 *   how far a gallon goes, or a set amount for each item), and the paint's
 *   cost is rounded up to the whole dollar.
 * - A rate priced per item has no hours and no paint.
 * - A line item's price is rounded up to the whole dollar, however it is
 *   priced; its hours are kept as typed, except a line item priced by
 *   quantity, whose hours are rounded up to the whole hour.
 * - A percentage discount is rounded up to the whole dollar.
 * - Optional work is priced but left out of the total.
 *
 * It works in the same floating-point arithmetic PaintScout uses, in the same
 * order, so even a figure that lands exactly on a whole hour or dollar rounds
 * the same way. Money comes out in whole cents.
 *
 * - Prep hours are not rounded and are counted beside the painting hours; a
 *   surface's labor is both times the hourly rate.
 * - Several discounts are each worked out on the same amount, then added up.
 * - Tax is charged on the total after the discount, rounded to the cent.
 *
 * Not yet matched against PaintScout, as no test estimate shows them: a
 * fixed-hours rate and a rate's own hourly rate; no ABQ rate uses either.
 */
class PricingEngine
{
    public function price(EstimateInput $estimate): PricedEstimate
    {
        $areas = array_map(fn (AreaInput $area): PricedArea => $this->priceArea($area, $estimate->hourlyRateCents), $estimate->areas);
        $lineItems = array_map(fn (LineItemInput $item): PricedLineItem => $this->priceLineItem($item, $estimate->hourlyRateCents), $estimate->lineItems);

        $counted = [];
        foreach ($areas as $area) {
            foreach ($area->surfaces as $surface) {
                if ($surface->isCounted) {
                    $counted[] = $surface;
                }
            }
        }
        $countedLineItems = array_values(array_filter($lineItems, fn (PricedLineItem $item): bool => $item->isCounted));

        $hours = array_sum(array_map(fn (PricedSurface $surface): float => $surface->hours, $counted))
            + array_sum(array_map(fn (PricedLineItem $item): float => $item->hours, $countedLineItems));
        $workCents = array_sum(array_map(fn (PricedSurface $surface): int => $surface->workCents, $counted));
        $materialsCents = array_sum(array_map(fn (PricedSurface $surface): int => $surface->materialsCents, $counted));
        $lineItemsCents = array_sum(array_map(fn (PricedLineItem $item): int => $item->priceCents, $countedLineItems));
        $subtotalCents = $workCents + $materialsCents + $lineItemsCents;

        $discountableCents = $subtotalCents - array_sum(array_map(
            fn (PricedSurface $surface): int => $surface->input->rate->excludedFromDiscount ? $surface->totalCents() : 0,
            $counted,
        ));

        $discounts = [];
        foreach ($estimate->discounts as $discount) {
            $discounts[] = ['discount' => $discount, 'cents' => $this->discountCents($discount, $discountableCents)];
        }
        $discountCents = min($subtotalCents, array_sum(array_column($discounts, 'cents')));

        $taxCents = $this->taxCents($estimate->taxPercent, $subtotalCents, $discountCents, $counted);

        $optionalCents = array_sum(array_map(fn (PricedArea $area): int => $area->optionalCents, $areas))
            + array_sum(array_map(fn (PricedLineItem $item): int => $item->isCounted ? 0 : $item->priceCents, $lineItems));

        return new PricedEstimate(
            areas: $areas,
            lineItems: $lineItems,
            hours: $hours,
            prepHours: array_sum(array_map(fn (PricedSurface $surface): float => $surface->prepHours, $counted)),
            workCents: $workCents,
            materialsCents: $materialsCents,
            lineItemsCents: $lineItemsCents,
            subtotalCents: $subtotalCents,
            discounts: $discounts,
            discountCents: $discountCents,
            taxCents: $taxCents,
            totalCents: $subtotalCents - $discountCents + $taxCents,
            optionalCents: $optionalCents,
        );
    }

    private function priceArea(AreaInput $area, int $hourlyRateCents): PricedArea
    {
        $measurements = AreaMeasurements::of($area);
        $surfaces = array_map(
            fn (SurfaceInput $surface): PricedSurface => $this->priceSurface($surface, $measurements, $hourlyRateCents, ! $area->isOptional),
            $area->surfaces,
        );

        $counted = array_values(array_filter($surfaces, fn (PricedSurface $surface): bool => $surface->isCounted));

        return new PricedArea(
            input: $area,
            measurements: $measurements,
            surfaces: $surfaces,
            hours: array_sum(array_map(fn (PricedSurface $surface): float => $surface->hours, $counted)),
            prepHours: array_sum(array_map(fn (PricedSurface $surface): float => $surface->prepHours, $counted)),
            workCents: array_sum(array_map(fn (PricedSurface $surface): int => $surface->workCents, $counted)),
            materialsCents: array_sum(array_map(fn (PricedSurface $surface): int => $surface->materialsCents, $counted)),
            optionalCents: array_sum(array_map(fn (PricedSurface $surface): int => $surface->isCounted ? 0 : $surface->totalCents(), $surfaces)),
        );
    }

    private function priceSurface(SurfaceInput $surface, AreaMeasurements $measurements, int $hourlyRateCents, bool $areaCounts): PricedSurface
    {
        $rate = $surface->rate;
        $coats = $rate->coatsFor($surface->coats);
        $value = $rate->valueFor($surface->coats);
        $quantity = (float) $surface->quantity;
        $amount = $measurements->amountFor($rate->measure, $quantity);
        $hourlyRate = $rate->hourlyRateCents ?? $hourlyRateCents;

        if ($rate->calculation === RateCalculation::PricePerUnit) {
            return new PricedSurface(
                input: $surface,
                amount: $amount,
                coats: $coats,
                value: $value,
                hours: 0.0,
                prepHours: 0.0,
                workCents: (int) round($amount * $value * 100),
                gallons: 0.0,
                materialsCents: 0,
                isCounted: $areaCounts && ! $surface->isOptional,
            );
        }

        $hours = (float) ceil($this->hours($rate->measure, $rate->calculation, $amount, $value));
        $prepHours = (float) ($surface->prepHours ?? $rate->prepHours ?? 0);
        $gallons = $this->gallons($surface->paint, $rate->measure, $amount, $coats);
        $materialsCents = $surface->paint === null ? 0 : (int) ceil($gallons * ($surface->paint->priceCents / 100)) * 100;

        return new PricedSurface(
            input: $surface,
            amount: $amount,
            coats: $coats,
            value: $value,
            hours: $hours,
            prepHours: $prepHours,
            workCents: (int) round(($hours + $prepHours) * $hourlyRate),
            gallons: $gallons,
            materialsCents: $materialsCents,
            isCounted: $areaCounts && ! $surface->isOptional,
        );
    }

    /**
     * Unrounded hours. A count of items always multiplies, whichever way the rate is counted.
     */
    private function hours(RateMeasure $measure, RateCalculation $calculation, float $amount, float $value): float
    {
        return match (true) {
            $calculation === RateCalculation::FixedHours => $value,
            $calculation === RateCalculation::HoursPerUnit, $measure === RateMeasure::Count => $amount * $value,
            $value == 0.0 => 0.0,
            default => $amount / $value,
        };
    }

    private function gallons(?PaintSnapshot $paint, RateMeasure $measure, float $amount, int $coats): float
    {
        if ($paint === null) {
            return 0.0;
        }

        if ($measure === RateMeasure::Count) {
            return $amount * (float) ($paint->coverageEach ?? 0);
        }

        $coverage = (float) ($measure === RateMeasure::LinearFeet ? $paint->coverageLength : $paint->coverageArea);

        return $coverage == 0.0 ? 0.0 : $amount * $coats / $coverage;
    }

    private function priceLineItem(LineItemInput $item, int $hourlyRateCents): PricedLineItem
    {
        if ($item->quantity !== null) {
            $quantity = (float) $item->quantity;
            $hours = (float) ceil($quantity * (float) ($item->hoursEach ?? 0));
            $dollars = $quantity * (($item->priceEachCents ?? 0) / 100);
        } else {
            $hours = (float) ($item->hours ?? 0);
            $dollars = $item->priceCents !== null
                ? $item->priceCents / 100
                : $hours * (($item->hourlyRateCents ?? $hourlyRateCents) / 100);
        }

        return new PricedLineItem($item, $hours, (int) ceil($dollars) * 100, ! $item->isOptional);
    }

    private function discountCents(DiscountInput $discount, int $discountableCents): int
    {
        if ($discount->kind === DiscountKind::Amount) {
            return (int) round((float) $discount->value * 100);
        }

        return (int) ceil(($discountableCents / 100) * (float) $discount->value / 100) * 100;
    }

    /**
     * @param  list<PricedSurface>  $counted
     */
    private function taxCents(?string $percent, int $subtotalCents, int $discountCents, array $counted): int
    {
        $percent = (float) ($percent ?? 0);

        if ($percent == 0.0) {
            return 0;
        }

        $untaxedCents = array_sum(array_map(
            fn (PricedSurface $surface): int => $surface->input->rate->excludedFromTax ? $surface->totalCents() : 0,
            $counted,
        ));
        $taxableCents = max(0, $subtotalCents - $discountCents - $untaxedCents);

        return (int) round($taxableCents * $percent / 100);
    }
}
