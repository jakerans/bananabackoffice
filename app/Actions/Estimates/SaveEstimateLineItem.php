<?php

namespace App\Actions\Estimates;

use App\Enums\LineItemPricing;
use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\LineItemTemplate;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a line item to an option of an estimate, or change it
 * (docs/slice-31-estimating-plan.md, step 31c): from a line item template or
 * typed in, priced by hours, a set price or a quantity, as in PaintScout.
 *
 * A line item priced by hours uses the estimate's hourly rate, or the
 * template's own. Only a manager gives it another (decision 204, question 4).
 */
class SaveEstimateLineItem
{
    public function __construct(private ChangeEstimate $changeEstimate) {}

    /**
     * @param  array{line_item_template_id?: int|string|null, name?: string, description?: string|null, pricing?: string, hours?: string|null, price?: string|null, hourly_rate?: string|null, quantity?: string|null, hours_each?: string|null, price_each?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, EstimateOption $option, ?EstimateLineItem $item, array $input, User $actor): EstimateLineItem
    {
        return $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($option, $item, $input, $actor): EstimateLineItem {
            ChangeEstimate::ensureBelongs($estimate, $option);

            if ($item !== null && $item->estimate_option_id !== $option->id) {
                throw new AuthorizationException;
            }

            $values = Validator::make($input, [
                'line_item_template_id' => ['nullable', Rule::exists('line_item_templates', 'id')->where('price_book_id', $estimate->price_book_id)],
                'name' => ['required', 'string', 'max:200'],
                'description' => ['nullable', 'string', 'max:60000'],
                'pricing' => ['required', Rule::enum(LineItemPricing::class)],
                'hours' => ['nullable', ...ChangeEstimate::MEASUREMENT_RULES],
                'price' => ['nullable', 'string', 'max:20'],
                'hourly_rate' => ['nullable', 'string', 'max:20'],
                'quantity' => ['nullable', ...ChangeEstimate::MEASUREMENT_RULES],
                'hours_each' => ['nullable', ...ChangeEstimate::MEASUREMENT_RULES],
                'price_each' => ['nullable', 'string', 'max:20'],
            ], attributes: ['line_item_template_id' => 'line item', 'hourly_rate' => 'hourly rate', 'hours_each' => 'hours each', 'price_each' => 'price each'])->validate();

            $pricing = LineItemPricing::from($values['pricing']);
            $template = filled($values['line_item_template_id'] ?? null) ? LineItemTemplate::query()->findOrFail((int) $values['line_item_template_id']) : null;

            $attributes = [
                'hours' => null,
                'price_cents' => null,
                'hourly_rate_cents' => null,
                'quantity' => null,
                'hours_each' => null,
                'price_each_cents' => null,
            ];

            if ($pricing === LineItemPricing::Hours) {
                $attributes['hours'] = Decimal::normalize($values['hours'] ?? null) ?? throw ValidationException::withMessages(['hours' => __('Give the hours.')]);
                $attributes['hourly_rate_cents'] = $this->hourlyRate($estimate, $item, $template, $values['hourly_rate'] ?? null, $actor);
            } elseif ($pricing === LineItemPricing::Price) {
                $attributes['price_cents'] = $this->cents($values['price'] ?? null, 'price');
            } else {
                $attributes['quantity'] = Decimal::normalize($values['quantity'] ?? null) ?? throw ValidationException::withMessages(['quantity' => __('Say how many.')]);
                $attributes['hours_each'] = Decimal::normalize($values['hours_each'] ?? null) ?? '0';
                $attributes['price_each_cents'] = $this->cents($values['price_each'] ?? null, 'price_each');
            }

            $item ??= new EstimateLineItem;
            $item->forceFill($attributes + [
                'estimate_option_id' => $option->id,
                'line_item_template_id' => $template?->id,
                'name' => trim($values['name']),
                'description' => filled($values['description'] ?? null) ? trim($values['description']) : null,
                'pricing' => $pricing,
                'sort_order' => $item->exists ? $item->sort_order : (int) EstimateLineItem::query()->where('estimate_option_id', $option->id)->max('sort_order') + 1,
            ])->save();

            return $item;
        });
    }

    /**
     * Dollars as typed, in cents; zero is allowed, for a line item that only explains.
     *
     * @throws ValidationException
     */
    private function cents(?string $typed, string $field): int
    {
        if (preg_match('/^\s*\$?\s*0*(\.0*)?\s*$/', (string) $typed) === 1 && trim((string) $typed) !== '') {
            return 0;
        }

        return Money::toCents($typed) ?? throw ValidationException::withMessages([$field => __('Give the price in dollars, such as 42.50.')]);
    }

    /**
     * The line item's own hourly rate, in cents, or null for the estimate's.
     * A rate the line item already has, or its template's, is kept; only a
     * manager types another.
     *
     * @throws ValidationException
     */
    private function hourlyRate(Estimate $estimate, ?EstimateLineItem $item, ?LineItemTemplate $template, ?string $typed, User $actor): ?int
    {
        $current = $item !== null ? $item->hourly_rate_cents : $template?->hourly_rate_cents;

        if (blank($typed)) {
            return Gate::forUser($actor)->allows('changeHourlyRate', $estimate) ? null : $current;
        }

        $cents = Money::toCents($typed) ?? throw ValidationException::withMessages(['hourly_rate' => __('Give the hourly rate in dollars, such as 85.')]);

        if ($cents !== $current && $cents !== $estimate->hourly_rate_cents && Gate::forUser($actor)->denies('changeHourlyRate', $estimate)) {
            throw ValidationException::withMessages(['hourly_rate' => __('Only a manager changes the hourly rate.')]);
        }

        return $cents === $estimate->hourly_rate_cents ? null : $cents;
    }
}
