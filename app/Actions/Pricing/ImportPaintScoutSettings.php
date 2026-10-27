<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\AreaTemplate;
use App\Models\LineItemTemplate;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fill the company's default price book from Banana's PaintScout settings,
 * once (docs/slice-31-estimating-plan.md, step 31a; decisions 196 and 197).
 *
 * The settings are the file saved from PaintScout outside the repository:
 * its "options" (categories, paints, area templates, line items, the hourly
 * and tax rates) and its "rates". Everything PaintScout keeps for a rate that
 * Backoffice does not use yet is kept in the rate's details for the steps that
 * follow. A rate whose category PaintScout no longer has is kept, switched
 * off, with no category.
 *
 * It refuses a default price book that already has rates, so it never mixes
 * two sets of prices.
 */
class ImportPaintScoutSettings
{
    /** The rate settings read into their own columns; the rest go into details. */
    private const array MAPPED_RATE_KEYS = [
        'name', 'label', 'client_label', 'section', 'rate_type', 'calculation_type', 'values',
        'default_applications', 'useCoats', 'prep', 'coverage', 'default_products', 'active',
        'excludeDiscount', 'excludeTax', 'notes', 'order',
    ];

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, mixed>  $settings
     * @return array{book: PriceBook, categories: int, rates: int, paints: int, areas: int, line_items: int}
     */
    public function handle(array $settings, ?User $actor = null): array
    {
        $options = $settings['options'] ?? null;
        $rates = $settings['rates'] ?? null;

        if (! is_array($options) || ! is_array($rates)) {
            throw new RuntimeException('This is not a saved PaintScout settings file: it has no options or rates.');
        }

        return DB::transaction(function () use ($options, $rates, $actor): array {
            $book = PriceBook::query()->whereNull('location_id')->lockForUpdate()->orderBy('id')->first();

            if ($book !== null && ProductionRate::query()->where('price_book_id', $book->id)->exists()) {
                throw new RuntimeException('The default price book already has rates. It is imported only once.');
            }

            $types = $this->estimateTypes($options);

            $book ??= new PriceBook;
            $book->forceFill([
                'location_id' => null,
                'hourly_rate_cents' => $this->cents(data_get($options, 'hourlyRate.value')) ?? 0,
                'estimate_type_hourly_rates' => $this->typeHourlyRates($options, $types) ?: null,
                'tax_rate_basis_points' => (int) round(((float) data_get($options, 'taxRate.value', 0)) * 100),
                'created_by' => $actor?->id,
                'created_by_name' => $actor?->name,
            ])->save();

            $categoryIds = [];
            foreach ($this->ordered($options['rateSections'] ?? []) as $position => [$key, $section]) {
                $category = new RateCategory;
                $category->forceFill([
                    'price_book_id' => $book->id,
                    'name' => Str::limit(trim((string) ($section['label'] ?? '')), 147) ?: __('Untitled category'),
                    'estimate_types' => $this->typesFor($section['quoteTypes'] ?? [], $types),
                    'is_active' => ($section['active'] ?? true) !== false,
                    'sort_order' => $position,
                    'source_key' => $key,
                ])->save();
                $categoryIds[$key] = $category->id;
            }

            $paintIds = [];
            foreach ($this->ordered($options['products'] ?? []) as $position => [$key, $product]) {
                $paint = new PaintProduct;
                $paint->forceFill([
                    'price_book_id' => $book->id,
                    'name' => Str::limit(trim((string) ($product['label'] ?? '')), 147) ?: __('Untitled paint'),
                    'unit_label' => Str::limit(trim((string) ($product['unitLabel'] ?? 'gal')), 20, '') ?: 'gal',
                    'price_cents' => $this->cents($product['price'] ?? null) ?? 0,
                    'coverage_area' => $this->decimal($product['coverage_rate'] ?? null),
                    'coverage_length' => $this->decimal($product['lnft_coverage'] ?? null),
                    'coverage_each' => $this->decimal($product['quantity_coverage'] ?? null),
                    'estimate_types' => $this->typesFor($product['quoteTypes'] ?? [], $types),
                    'manufacturer' => $this->text($product['manufacturer'] ?? null, 100),
                    'category' => $this->text($product['category'] ?? null, 100),
                    'description' => $this->text($product['description'] ?? null, 5000),
                    'is_active' => ($product['active'] ?? true) !== false,
                    'sort_order' => $position,
                    'source_key' => $key,
                ])->save();
                $paintIds[$key] = $paint->id;
            }

            $rateCount = 0;
            foreach (array_values($rates) as $position => $rate) {
                if (! is_array($rate)) {
                    continue;
                }

                $measure = $this->measure($rate['rate_type'] ?? null);
                $calculation = $this->calculation($rate['calculation_type'] ?? null);

                if ($measure === null || $calculation === null) {
                    continue;
                }

                $categoryId = $categoryIds[$rate['section'] ?? ''] ?? null;
                $values = array_pad(array_slice(array_values((array) ($rate['values'] ?? [])), 0, ProductionRate::VALUES), ProductionRate::VALUES, null);
                $paintKey = data_get($rate, 'default_products.0.productKey');

                $productionRate = new ProductionRate;
                $productionRate->forceFill([
                    'price_book_id' => $book->id,
                    'rate_category_id' => $categoryId,
                    'name' => Str::limit(trim((string) ($rate['label'] ?? '')), 197) ?: __('Untitled rate'),
                    'customer_label' => $this->text($rate['client_label'] ?? null, 200),
                    'measure' => $measure,
                    'calculation' => $calculation,
                    'value_1' => $this->decimal($values[0]),
                    'value_2' => $this->decimal($values[1]),
                    'value_3' => $this->decimal($values[2]),
                    'value_4' => $this->decimal($values[3]),
                    'default_coats' => max(1, min(9, (int) ($rate['default_applications'] ?? 1))),
                    'uses_coats' => ($rate['useCoats'] ?? true) !== false,
                    'prep_hours' => $this->decimal($rate['prep'] ?? null),
                    'coverage' => $this->decimal($rate['coverage'] ?? null),
                    'hourly_rate_cents' => null,
                    'paint_product_id' => is_string($paintKey) ? ($paintIds[$paintKey] ?? null) : null,
                    'estimate_types' => $this->rateTypes($rate, $options, $types),
                    'excluded_from_discount' => (bool) ($rate['excludeDiscount'] ?? false),
                    'excluded_from_tax' => (bool) ($rate['excludeTax'] ?? false),
                    'notes' => $this->text(strip_tags((string) ($rate['notes'] ?? '')), 5000),
                    'details' => Arr::except($rate, self::MAPPED_RATE_KEYS) + ['default_products' => $rate['default_products'] ?? null],
                    // A rate PaintScout no longer shows under any category is kept but switched off.
                    'is_active' => ($rate['active'] ?? true) !== false && $categoryId !== null,
                    'sort_order' => (int) ($rate['order'] ?? $position),
                    'source_key' => is_string($rate['name'] ?? null) ? Str::limit($rate['name'], 100, '') : null,
                ])->save();
                $rateCount++;
            }

            foreach ($this->ordered($options['areaTemplates'] ?? []) as $position => [$key, $area]) {
                (new AreaTemplate)->forceFill([
                    'price_book_id' => $book->id,
                    'name' => Str::limit(trim((string) ($area['label'] ?? '')), 147) ?: __('Untitled area'),
                    'side' => match (data_get($area, 'int_ext.value')) {
                        'int' => 'interior',
                        'ext' => 'exterior',
                        default => null,
                    },
                    'estimate_types' => $this->typesFor($area['quoteTypes'] ?? [], $types),
                    'is_active' => ($area['active'] ?? true) !== false,
                    'sort_order' => $position,
                    'source_key' => $key,
                ])->save();
            }

            foreach ($this->ordered($options['lineItems'] ?? []) as $position => [$key, $item]) {
                (new LineItemTemplate)->forceFill([
                    'price_book_id' => $book->id,
                    'name' => Str::limit(trim((string) ($item['label'] ?? '')), 197) ?: __('Untitled line item'),
                    'description' => $this->text($item['description'] ?? null, 60000),
                    'price_cents' => $this->cents($item['price'] ?? null),
                    'hours' => $this->decimal($item['hours'] ?? null),
                    'is_calculated' => (bool) ($item['calculate'] ?? false),
                    'hourly_rate_cents' => ($item['customHourlyRate'] ?? false) ? $this->cents($item['hourlyRate'] ?? null) : null,
                    'estimate_types' => $this->typesFor($item['quoteTypes'] ?? ['all'], $types),
                    'is_active' => ($item['active'] ?? true) !== false,
                    'sort_order' => $position,
                    'source_key' => $key,
                ])->save();
            }

            $counts = [
                'categories' => count($categoryIds),
                'rates' => $rateCount,
                'paints' => count($paintIds),
                'areas' => AreaTemplate::query()->where('price_book_id', $book->id)->count(),
                'line_items' => LineItemTemplate::query()->where('price_book_id', $book->id)->count(),
            ];

            $this->recordAuditEvent->handle(AuditAction::PricingImported, $book, actor: $actor, after: $counts, reason: __('From PaintScout'));

            return ['book' => $book] + $counts;
        });
    }

    /**
     * PaintScout's keys for its estimate types, matched to Backoffice's.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, list<string>>
     */
    private function estimateTypes(array $options): array
    {
        $types = ['all' => EstimateType::values(), 'int' => [EstimateType::ResidentialInterior->value], 'ext' => [EstimateType::ResidentialExterior->value]];

        foreach ((array) data_get($options, 'intExtOptions.values', []) as $key => $type) {
            $label = Str::lower((string) data_get($type, 'label'));

            if (str_contains($label, 'commercial')) {
                $types[(string) $key] = [str_contains($label, 'exterior') ? EstimateType::CommercialExterior->value : EstimateType::CommercialInterior->value];
            }
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, list<string>>  $types
     * @return array<string, int>
     */
    private function typeHourlyRates(array $options, array $types): array
    {
        $rates = [];

        foreach ((array) data_get($options, 'intExtOptions.values', []) as $key => $type) {
            $cents = $this->cents(data_get($type, 'hourlyRate'));

            if ($cents !== null && $cents > 0 && count($types[(string) $key] ?? []) === 1) {
                $rates[$types[(string) $key][0]] = $cents;
            }
        }

        return $rates;
    }

    /**
     * @param  array<string, list<string>>  $types
     * @return list<string>
     */
    private function typesFor(mixed $keys, array $types): array
    {
        $found = [];

        foreach ((array) $keys as $key) {
            array_push($found, ...($types[(string) $key] ?? []));
        }

        $found = array_values(array_unique($found));

        return $found === [] ? EstimateType::values() : array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $found, true)));
    }

    /**
     * A rate is offered on its category's estimate types; one without a category, on its own side's.
     *
     * @param  array<string, mixed>  $rate
     * @param  array<string, mixed>  $options
     * @param  array<string, list<string>>  $types
     * @return list<string>
     */
    private function rateTypes(array $rate, array $options, array $types): array
    {
        $section = data_get($options, 'rateSections.values.'.($rate['section'] ?? ''));

        if (is_array($section)) {
            return $this->typesFor($section['quoteTypes'] ?? [], $types);
        }

        return $this->typesFor([$rate['int_ext'] ?? 'all'], $types);
    }

    /**
     * A PaintScout list's entries in its own order, then any it left out.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function ordered(mixed $list): array
    {
        $values = (array) data_get($list, 'values', []);
        $order = array_values(array_filter((array) data_get($list, 'order', []), fn (mixed $key): bool => is_string($key) && is_array($values[$key] ?? null)));
        $keys = [...$order, ...array_diff(array_keys($values), $order)];

        return array_values(array_map(fn (string|int $key): array => [(string) $key, (array) $values[$key]], array_filter($keys, fn (string|int $key): bool => is_array($values[$key] ?? null))));
    }

    private function measure(mixed $type): ?RateMeasure
    {
        return match ($type) {
            'sqft_walls', 'sqft' => RateMeasure::WallArea,
            'sqft_ceiling' => RateMeasure::CeilingArea,
            'sqft_floor' => RateMeasure::FloorArea,
            'lnft' => RateMeasure::LinearFeet,
            'quantity' => RateMeasure::Count,
            default => null,
        };
    }

    private function calculation(mixed $type): ?RateCalculation
    {
        return match ($type) {
            'value_per_hour' => RateCalculation::UnitsPerHour,
            'price_per_value' => RateCalculation::PricePerUnit,
            'hours_per_value' => RateCalculation::HoursPerUnit,
            'hours' => RateCalculation::FixedHours,
            default => null,
        };
    }

    /**
     * An exact decimal, to four places, from a number or a numeric string such as ".5"; null for none.
     */
    private function decimal(mixed $value): ?string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $fixed = number_format((float) $value, 4, '.', '');

        return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
    }

    /**
     * Dollars, as a number or numeric string, in cents; null for none.
     */
    private function cents(mixed $dollars): ?int
    {
        if ($dollars === null || $dollars === '' || ! is_numeric($dollars)) {
            return null;
        }

        return (int) round(((float) $dollars) * 100);
    }

    private function text(mixed $value, int $limit): ?string
    {
        $text = trim((string) $value);

        return $text === '' || $text === '<p></p>' ? null : Str::limit($text, $limit, '');
    }
}
