<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Models\PaintProduct;
use App\Models\PriceBook;
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
 * Add a paint to a price book, or change its price, coverage or whether it is
 * offered (docs/slice-31-estimating-plan.md, step 31a). Owner and Leadership
 * only. Every change is recorded with the paint's name.
 */
class SavePaintProduct
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, unit_label?: string, price?: string, coverage_area?: string|null, coverage_length?: string|null, coverage_each?: string|null, estimate_types?: list<string>, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?PaintProduct $paint, array $input, User $actor): PaintProduct
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($paint !== null && $paint->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'unit_label' => ['required', 'string', 'max:20'],
            'price' => ['required', 'string', 'max:20'],
            'coverage_area' => ['nullable', ...Decimal::RULES],
            'coverage_length' => ['nullable', ...Decimal::RULES],
            'coverage_each' => ['nullable', ...Decimal::RULES],
            'estimate_types' => ['required', 'array', 'min:1'],
            'estimate_types.*' => [Rule::in(EstimateType::values())],
            'is_active' => ['boolean'],
        ], attributes: ['unit_label' => 'unit', 'coverage_area' => 'area covered', 'coverage_length' => 'length covered', 'coverage_each' => 'used for each item', 'estimate_types' => 'estimate types'])->validate();

        $priceCents = Money::toCents($values['price']);

        if ($priceCents === null) {
            throw ValidationException::withMessages(['price' => __('Give the price in dollars, such as 42.50.')]);
        }

        $attributes = [
            'name' => trim($values['name']),
            'unit_label' => trim($values['unit_label']),
            'price_cents' => $priceCents,
            'coverage_area' => Decimal::normalize($values['coverage_area'] ?? null),
            'coverage_length' => Decimal::normalize($values['coverage_length'] ?? null),
            'coverage_each' => Decimal::normalize($values['coverage_each'] ?? null),
            'estimate_types' => array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $values['estimate_types'], true))),
            'is_active' => (bool) ($values['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($book, $paint, $attributes, $actor): PaintProduct {
            if ($paint === null) {
                $paint = new PaintProduct;
                $paint->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) PaintProduct::query()->where('price_book_id', $book->id)->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingPaintAdded, $book, actor: $actor, after: $this->recorded($paint), location: $book->location);

                return $paint;
            }

            $paint = PaintProduct::query()->lockForUpdate()->findOrFail($paint->id);
            $before = $this->recorded($paint);
            $paint->forceFill($attributes)->save();
            $after = $this->recorded($paint->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingPaintChanged,
                    $book,
                    actor: $actor,
                    before: ['paint' => $before['paint']] + array_intersect_key($before, array_flip($changed)),
                    after: ['paint' => $after['paint']] + array_intersect_key($after, array_flip($changed)),
                    location: $book->location,
                );
            }

            return $paint;
        });
    }

    /**
     * @return array<string, string|int|bool|null>
     */
    private function recorded(PaintProduct $paint): array
    {
        return [
            'paint' => $paint->name,
            'unit_label' => $paint->unit_label,
            'price_cents' => $paint->price_cents,
            'coverage_area' => Decimal::plain($paint->coverage_area),
            'coverage_length' => Decimal::plain($paint->coverage_length),
            'coverage_each' => Decimal::plain($paint->coverage_each),
            'estimate_types' => implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $paint->estimate_types)),
            'is_active' => $paint->is_active,
        ];
    }
}
