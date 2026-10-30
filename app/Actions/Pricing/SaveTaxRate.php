<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\PriceBook;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Pricing\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Add a named tax rate to a price book, or change, retire or bring one back
 * (docs/slice-35-pricing-settings-plan.md, step 35a; decision 213). Owner
 * and Leadership only. A book has at most one default, which new estimates
 * start with; making one the default takes it from the other. Nothing is
 * deleted: an estimate keeps the rate it was charged.
 */
class SaveTaxRate
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, percent?: string, is_default?: bool, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?TaxRate $taxRate, array $input, User $actor): TaxRate
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($taxRate !== null && $taxRate->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'percent' => ['required', 'string', 'max:20'],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],
        ])->validate();

        $percent = preg_match('/^\s*\d{0,2}(\.\d{1,4})?\s*%?\s*$/', $values['percent']) === 1 ? Decimal::normalize(rtrim(trim($values['percent']), '% ')) : null;

        if ($percent === null || (float) $percent <= 0) {
            throw ValidationException::withMessages(['percent' => __('Give the tax as a percent, more than 0 and under 100, with up to four decimals, such as 7.625.')]);
        }

        $isActive = (bool) ($values['is_active'] ?? true);
        $isDefault = (bool) ($values['is_default'] ?? false);

        if ($isDefault && ! $isActive) {
            throw ValidationException::withMessages(['is_default' => __('A retired tax rate cannot be the one new estimates start with.')]);
        }

        $attributes = [
            'name' => trim($values['name']),
            'percent' => $percent,
            'is_default' => $isDefault,
            'is_active' => $isActive,
        ];

        return DB::transaction(function () use ($book, $taxRate, $attributes, $actor): TaxRate {
            // Lock the book's rates, so two people cannot each make a different one the default.
            $rates = TaxRate::query()->where('price_book_id', $book->id)->lockForUpdate()->get();
            $previousDefault = $attributes['is_default']
                ? $rates->first(fn (TaxRate $rate): bool => $rate->is_default && $rate->id !== $taxRate?->id)
                : null;

            if ($previousDefault !== null) {
                $previousDefault->forceFill(['is_default' => false])->save();
            }

            $replaces = $previousDefault === null ? [] : ['replaces_default' => $previousDefault->name];

            if ($taxRate === null) {
                $taxRate = new TaxRate;
                $taxRate->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) $rates->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingTaxRateAdded, $book, actor: $actor, after: $this->recorded($taxRate) + $replaces, location: $book->location);

                return $taxRate;
            }

            $taxRate = $rates->firstWhere('id', $taxRate->id) ?? TaxRate::query()->findOrFail($taxRate->id);
            $before = $this->recorded($taxRate);
            $taxRate->forceFill($attributes)->save();
            $after = $this->recorded($taxRate->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingTaxRateChanged,
                    $book,
                    actor: $actor,
                    before: ['tax_rate' => $before['tax_rate']] + array_intersect_key($before, array_flip($changed)),
                    after: ['tax_rate' => $after['tax_rate']] + array_intersect_key($after, array_flip($changed)) + $replaces,
                    location: $book->location,
                );
            }

            return $taxRate;
        });
    }

    /**
     * @return array<string, string|bool>
     */
    private function recorded(TaxRate $taxRate): array
    {
        return [
            'tax_rate' => $taxRate->name,
            'percent' => Decimal::plain($taxRate->percent).'%',
            'starts_on_new_estimates' => $taxRate->is_default,
            'is_active' => $taxRate->is_active,
        ];
    }
}
