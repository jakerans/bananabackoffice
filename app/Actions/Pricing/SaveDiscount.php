<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\DiscountKind;
use App\Enums\Permission;
use App\Models\Discount;
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
 * Add a named discount to a price book, or change, retire or bring one back
 * (docs/slice-35-pricing-settings-plan.md, step 35a; decision 213). Owner
 * and Leadership only. Nothing is deleted: an estimate keeps the copy it was
 * given. Every change is recorded with the discount's name.
 */
class SaveDiscount
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, kind?: string, value?: string, is_default?: bool, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?Discount $discount, array $input, User $actor): Discount
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($discount !== null && $discount->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['required', Rule::enum(DiscountKind::class)],
            'value' => ['required', 'string', 'max:20'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ])->validate();

        $kind = DiscountKind::from($values['kind']);
        $isActive = (bool) ($values['is_active'] ?? true);
        $isDefault = (bool) ($values['is_default'] ?? false);

        if ($isDefault && ! $isActive) {
            throw ValidationException::withMessages(['is_default' => __('A retired discount cannot start on new estimates.')]);
        }

        $attributes = [
            'name' => trim($values['name']),
            'kind' => $kind,
            'value' => $this->value($kind, $values['value']),
            'is_default' => $isDefault,
            'is_active' => $isActive,
        ];

        return DB::transaction(function () use ($book, $discount, $attributes, $actor): Discount {
            if ($discount === null) {
                $discount = new Discount;
                $discount->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) Discount::query()->where('price_book_id', $book->id)->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingDiscountAdded, $book, actor: $actor, after: $this->recorded($discount), location: $book->location);

                return $discount;
            }

            $discount = Discount::query()->lockForUpdate()->findOrFail($discount->id);
            $before = $this->recorded($discount);
            $discount->forceFill($attributes)->save();
            $after = $this->recorded($discount->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingDiscountChanged,
                    $book,
                    actor: $actor,
                    before: ['discount' => $before['discount']] + array_intersect_key($before, array_flip($changed)),
                    after: ['discount' => $after['discount']] + array_intersect_key($after, array_flip($changed)),
                    location: $book->location,
                );
            }

            return $discount;
        });
    }

    /**
     * What the discount takes off, as the database keeps it: a percentage, or dollars.
     *
     * @throws ValidationException
     */
    private function value(DiscountKind $kind, string $typed): string
    {
        if ($kind === DiscountKind::Amount) {
            $cents = Money::toCents($typed) ?? throw ValidationException::withMessages(['value' => __('Give the amount in dollars, such as 40 or 42.50.')]);

            return number_format($cents / 100, 2, '.', '');
        }

        $percent = preg_match('/^\s*\d{0,3}(\.\d{1,4})?\s*%?\s*$/', $typed) === 1 ? Decimal::normalize(rtrim(trim($typed), '% ')) : null;

        if ($percent === null || (float) $percent <= 0 || (float) $percent > 100) {
            throw ValidationException::withMessages(['value' => __('Give the percentage, more than 0 and at most 100, such as 10 or 12.5.')]);
        }

        return $percent;
    }

    /**
     * @return array<string, string|bool>
     */
    private function recorded(Discount $discount): array
    {
        return [
            'discount' => $discount->name,
            'takes_off' => $discount->kind === DiscountKind::Percentage
                ? Decimal::plain($discount->value).'%'
                : Money::format((int) round((float) $discount->value * 100)),
            'starts_on_new_estimates' => $discount->is_default,
            'is_active' => $discount->is_active,
        ];
    }
}
