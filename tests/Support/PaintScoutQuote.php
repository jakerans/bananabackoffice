<?php

namespace Tests\Support;

use App\Enums\AreaShape;
use App\Enums\DiscountKind;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Support\Pricing\AreaInput;
use App\Support\Pricing\DiscountInput;
use App\Support\Pricing\EstimateInput;
use App\Support\Pricing\LineItemInput;
use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\RateSnapshot;
use App\Support\Pricing\SurfaceInput;

/**
 * A quote saved from PaintScout, read as an estimate for the pricing engine,
 * with the figures PaintScout gave it (docs/slice-31-estimating-plan.md, step
 * 31b). The saved quotes hold Banana's real prices, so they stay outside the
 * repository; only this reader is committed.
 */
final class PaintScoutQuote
{
    /**
     * @param  array<string, mixed>  $quote
     */
    private function __construct(public readonly array $quote) {}

    public static function fromFile(string $path): self
    {
        $saved = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return new self($saved['quote']);
    }

    public function estimate(): EstimateInput
    {
        $areas = [];
        $lineItems = [];

        foreach ($this->quote['order']['area'] as $entry) {
            if ($entry['type'] === 'area') {
                $areas[] = $this->area($this->quote['areas'][$entry['key']]);
            } elseif ($entry['type'] === 'line_item') {
                $lineItems[] = $this->lineItem($this->quote['lineItems'][$entry['key']]);
            }
        }

        return new EstimateInput(
            hourlyRateCents: $this->cents($this->quote['hourly_rate']),
            areas: $areas,
            lineItems: $lineItems,
            discounts: array_map(fn (array $discount): DiscountInput => new DiscountInput(
                (string) $discount['label'],
                DiscountKind::from($discount['type']),
                (string) $discount['value'],
            ), $this->quote['discounts'] ?? []),
            taxPercent: (string) ($this->quote['tax_rate'] ?? 0),
        );
    }

    /**
     * Each area's surfaces as PaintScout keeps them, in the estimate's order.
     *
     * @return list<array{area: array<string, mixed>, surfaces: list<array<string, mixed>>}>
     */
    public function areas(): array
    {
        $areas = [];

        foreach ($this->quote['order']['area'] as $entry) {
            if ($entry['type'] === 'area') {
                $area = $this->quote['areas'][$entry['key']];
                $areas[] = ['area' => $area, 'surfaces' => $this->surfaces($area)];
            }
        }

        return $areas;
    }

    /**
     * The line items as PaintScout keeps them, in the estimate's order.
     *
     * @return list<array<string, mixed>>
     */
    public function lineItems(): array
    {
        $items = [];

        foreach ($this->quote['order']['area'] as $entry) {
            if ($entry['type'] === 'line_item') {
                $items[] = $this->quote['lineItems'][$entry['key']];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $area
     */
    private function area(array $area): AreaInput
    {
        $dimensions = $area['dimensions'];

        return new AreaInput(
            name: (string) data_get($area, 'area_label.value'),
            shape: data_get($area, 'area_type.value') === 'surface' ? AreaShape::Surface : AreaShape::Room,
            width: (string) $dimensions['width'],
            length: (string) $dimensions['length'],
            height: (string) $dimensions['height'],
            surfaces: array_map(fn (array $surface): SurfaceInput => $this->surface($surface), $this->surfaces($area)),
            isOptional: ($area['use_total'] ?? true) === false,
        );
    }

    /**
     * @param  array<string, mixed>  $area
     * @return list<array<string, mixed>>
     */
    private function surfaces(array $area): array
    {
        $byKey = [];
        foreach ($area['substrates'] as $section) {
            foreach ($section as $surface) {
                $byKey[$surface['key']] = $surface;
            }
        }

        return array_values(array_map(fn (array $entry): array => $byKey[$entry['key']], $area['substrateOrder']));
    }

    /**
     * @param  array<string, mixed>  $surface
     */
    private function surface(array $surface): SurfaceInput
    {
        $rate = $surface['rate'];
        $product = $surface['products'][0] ?? null;

        return new SurfaceInput(
            rate: new RateSnapshot(
                name: (string) $rate['label'],
                measure: match ($rate['rate_type']) {
                    'sqft_walls' => RateMeasure::WallArea,
                    'sqft_ceiling' => RateMeasure::CeilingArea,
                    'sqft_floor' => RateMeasure::FloorArea,
                    'lnft' => RateMeasure::LinearFeet,
                    'quantity' => RateMeasure::Count,
                },
                calculation: match ($rate['calculation_type']) {
                    'value_per_hour' => RateCalculation::UnitsPerHour,
                    'price_per_value' => RateCalculation::PricePerUnit,
                    'hours_per_value' => RateCalculation::HoursPerUnit,
                    'hours' => RateCalculation::FixedHours,
                },
                values: array_map(fn (mixed $value): string => (string) $value, $rate['values']),
                usesCoats: (bool) ($rate['useCoats'] ?? false),
                excludedFromDiscount: (bool) ($rate['excludeDiscount'] ?? false),
                excludedFromTax: (bool) ($rate['excludeTax'] ?? false),
            ),
            paint: $product === null ? null : new PaintSnapshot(
                name: (string) data_get($product, 'product.label'),
                priceCents: $this->cents($product['price']),
                coverageArea: (string) $product['coverage_rate_value'],
                coverageLength: (string) $product['lnft_coverage_rate_value'],
                coverageEach: (string) $product['quantity_coverage_rate_value'],
            ),
            coats: (int) $surface['coats'],
            quantity: (string) $surface['quantity'],
            isOptional: ($surface['use_total'] ?? true) === false,
            prepHours: (float) ($surface['prep'] ?? 0) > 0 ? (string) $surface['prep'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function lineItem(array $item): LineItemInput
    {
        $by = $item['calculateBy'] ?? null;
        $byHours = $by === 'hourlyRate' && ($item['hours'] ?? null) !== null;
        $byQuantity = $by === 'quantity';

        return new LineItemInput(
            name: (string) $item['name'],
            hours: $byHours ? (string) $item['hours'] : null,
            priceCents: $byHours || $byQuantity ? null : $this->cents($item['price'] ?? 0),
            hourlyRateCents: ($item['customHourlyRate'] ?? false) ? $this->cents($item['hourlyRate']) : null,
            isOptional: ($item['use_total'] ?? true) === false,
            quantity: $byQuantity ? (string) $item['quantity'] : null,
            hoursEach: $byQuantity ? (string) $item['hoursPerUnit'] : null,
            priceEachCents: $byQuantity ? $this->cents($item['pricePerUnit']) : null,
        );
    }

    private function cents(mixed $dollars): int
    {
        return (int) round(((float) $dollars) * 100);
    }
}
