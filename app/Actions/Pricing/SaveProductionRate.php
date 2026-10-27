<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\PaintProduct;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\RateCategory;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a production rate to a price book, or change one
 * (docs/slice-31-estimating-plan.md, step 31a; decision 197, questions 3 and
 * 4). Owner and Leadership only. Every change is recorded with its rate's
 * name and only the settings that changed. Estimates already priced keep the
 * rates they were priced with.
 */
class SaveProductionRate
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, customer_label?: string|null, rate_category_id?: int|string|null, measure?: string, calculation?: string, value_1?: string|null, value_2?: string|null, value_3?: string|null, value_4?: string|null, default_coats?: int|string, uses_coats?: bool, prep_hours?: string|null, hourly_rate?: string|null, paint_product_id?: int|string|null, estimate_types?: list<string>, excluded_from_discount?: bool, excluded_from_tax?: bool, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?ProductionRate $rate, array $input, User $actor): ProductionRate
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($rate !== null && $rate->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:200'],
            'customer_label' => ['nullable', 'string', 'max:200'],
            'rate_category_id' => ['required', 'integer', Rule::exists(RateCategory::class, 'id')->where('price_book_id', $book->id)],
            'measure' => ['required', Rule::enum(RateMeasure::class)],
            'calculation' => ['required', Rule::enum(RateCalculation::class)],
            'value_1' => ['required', ...Decimal::RULES],
            'value_2' => ['nullable', ...Decimal::RULES],
            'value_3' => ['nullable', ...Decimal::RULES],
            'value_4' => ['nullable', ...Decimal::RULES],
            'default_coats' => ['required', 'integer', 'min:1', 'max:9'],
            'uses_coats' => ['boolean'],
            'prep_hours' => ['nullable', ...Decimal::RULES],
            'hourly_rate' => ['nullable', 'string', 'max:20'],
            'paint_product_id' => ['nullable', 'integer', Rule::exists(PaintProduct::class, 'id')->where('price_book_id', $book->id)],
            'estimate_types' => ['required', 'array', 'min:1'],
            'estimate_types.*' => [Rule::in(EstimateType::values())],
            'excluded_from_discount' => ['boolean'],
            'excluded_from_tax' => ['boolean'],
            'is_active' => ['boolean'],
        ], attributes: [
            'rate_category_id' => 'category',
            'value_1' => 'first value',
            'default_coats' => 'coats',
            'prep_hours' => 'prep hours',
            'paint_product_id' => 'paint',
            'estimate_types' => 'estimate types',
        ])->validate();

        $hourlyRate = trim((string) ($values['hourly_rate'] ?? ''));
        $hourlyRateCents = $hourlyRate === '' ? null : Money::toCents($hourlyRate);

        if ($hourlyRate !== '' && $hourlyRateCents === null) {
            throw ValidationException::withMessages(['hourly_rate' => __('Give the hourly rate in dollars, such as 65, or leave it empty to use the price book\'s.')]);
        }

        $attributes = [
            'name' => trim($values['name']),
            'customer_label' => blank($values['customer_label'] ?? null) ? null : trim((string) $values['customer_label']),
            'rate_category_id' => (int) $values['rate_category_id'],
            'measure' => $values['measure'],
            'calculation' => $values['calculation'],
            'value_1' => Decimal::normalize($values['value_1']),
            'value_2' => Decimal::normalize($values['value_2'] ?? null),
            'value_3' => Decimal::normalize($values['value_3'] ?? null),
            'value_4' => Decimal::normalize($values['value_4'] ?? null),
            'default_coats' => (int) $values['default_coats'],
            'uses_coats' => (bool) ($values['uses_coats'] ?? true),
            'prep_hours' => Decimal::normalize($values['prep_hours'] ?? null),
            'hourly_rate_cents' => $hourlyRateCents,
            'paint_product_id' => blank($values['paint_product_id'] ?? null) ? null : (int) $values['paint_product_id'],
            'estimate_types' => array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $values['estimate_types'], true))),
            'excluded_from_discount' => (bool) ($values['excluded_from_discount'] ?? false),
            'excluded_from_tax' => (bool) ($values['excluded_from_tax'] ?? false),
            'is_active' => (bool) ($values['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($book, $rate, $attributes, $actor): ProductionRate {
            if ($rate === null) {
                $rate = new ProductionRate;
                $rate->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) ProductionRate::query()->where('rate_category_id', $attributes['rate_category_id'])->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingRateAdded, $book, actor: $actor, after: ['rate' => $rate->name] + $this->recorded($rate), location: $book->location);

                return $rate;
            }

            $rate = ProductionRate::query()->lockForUpdate()->findOrFail($rate->id);
            $before = $this->recorded($rate);
            $rate->forceFill($attributes)->save();
            $after = $this->recorded($rate->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingRateChanged,
                    $book,
                    actor: $actor,
                    before: ['rate' => $before['name']] + array_intersect_key($before, array_flip($changed)),
                    after: ['rate' => $rate->name] + array_intersect_key($after, array_flip($changed)),
                    location: $book->location,
                );
            }

            return $rate;
        });
    }

    /**
     * The settings recorded in the Audit Log, as plain values.
     *
     * @return array<string, string|int|bool|null>
     */
    private function recorded(ProductionRate $rate): array
    {
        return [
            'name' => $rate->name,
            'customer_label' => $rate->customer_label,
            'category' => $rate->category?->name,
            'measure' => $rate->measure->label(),
            'calculation' => $rate->calculation->label($rate->measure),
            'value_1' => Decimal::plain($rate->value_1),
            'value_2' => Decimal::plain($rate->value_2),
            'value_3' => Decimal::plain($rate->value_3),
            'value_4' => Decimal::plain($rate->value_4),
            'default_coats' => $rate->default_coats,
            'uses_coats' => $rate->uses_coats,
            'prep_hours' => Decimal::plain($rate->prep_hours),
            'hourly_rate_cents' => $rate->hourly_rate_cents,
            'paint' => $rate->paint?->name,
            'estimate_types' => implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $rate->estimate_types)),
            'excluded_from_discount' => $rate->excluded_from_discount,
            'excluded_from_tax' => $rate->excluded_from_tax,
            'is_active' => $rate->is_active,
        ];
    }
}
