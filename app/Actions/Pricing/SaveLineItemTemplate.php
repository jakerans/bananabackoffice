<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Models\LineItemTemplate;
use App\Models\PriceBook;
use App\Models\User;
use App\Support\Money;
use App\Support\Pricing\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a line item to a price book's list, or change, retire or bring one
 * back (docs/slice-35-pricing-settings-plan.md, step 35b; decision 213): a
 * name, a description, and a price or hours at the estimate's hourly rate or
 * its own. Owner and Leadership only. An estimate keeps the line items it
 * was given. Every change is recorded, the description only by its start.
 */
class SaveLineItemTemplate
{
    public const string PRICED = 'price';

    public const string HOURS = 'hours';

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, description?: string|null, pricing?: string, price?: string|null, hours?: string|null, hourly_rate?: string|null, estimate_types?: list<string>, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?LineItemTemplate $item, array $input, User $actor): LineItemTemplate
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($item !== null && $item->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'pricing' => ['required', Rule::in([self::PRICED, self::HOURS])],
            'price' => ['nullable', 'string', 'max:20'],
            'hours' => ['nullable', ...Decimal::RULES],
            'hourly_rate' => ['nullable', 'string', 'max:20'],
            'estimate_types' => ['required', 'array', 'min:1'],
            'estimate_types.*' => [Rule::in(EstimateType::values())],
            'is_active' => ['boolean'],
        ], attributes: ['hourly_rate' => 'hourly rate', 'estimate_types' => 'estimate types'])->validate();

        $byHours = $values['pricing'] === self::HOURS;

        $attributes = [
            'name' => trim($values['name']),
            'price_cents' => $byHours ? null : $this->price($values['price'] ?? null),
            'hours' => $byHours ? $this->hours($values['hours'] ?? null) : null,
            'is_calculated' => $byHours,
            'hourly_rate_cents' => $byHours && filled($values['hourly_rate'] ?? null)
                ? (Money::toCents($values['hourly_rate']) ?? throw ValidationException::withMessages(['hourly_rate' => __('Give the hourly rate in dollars, such as 85, or leave it empty to use the estimate\'s.')]))
                : null,
            'estimate_types' => array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $values['estimate_types'], true))),
            'is_active' => (bool) ($values['is_active'] ?? true),
        ];

        $description = self::plain((string) ($values['description'] ?? ''));

        // PaintScout's formatted description is kept unless its words were changed.
        if ($item === null || $description !== self::plain($item->plainDescription())) {
            $attributes['description'] = $description === '' ? null : implode('<br>', array_map(e(...), explode("\n", $description)));
        }

        return DB::transaction(function () use ($book, $item, $attributes, $actor): LineItemTemplate {
            if ($item === null) {
                $item = new LineItemTemplate;
                $item->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) LineItemTemplate::query()->where('price_book_id', $book->id)->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingLineItemAdded, $book, actor: $actor, after: $this->recorded($item), location: $book->location);

                return $item;
            }

            $item = LineItemTemplate::query()->lockForUpdate()->findOrFail($item->id);
            $before = $this->recorded($item);
            $item->forceFill($attributes)->save();
            $after = $this->recorded($item->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingLineItemChanged,
                    $book,
                    actor: $actor,
                    before: ['line_item' => $before['line_item']] + array_intersect_key($before, array_flip($changed)),
                    after: ['line_item' => $after['line_item']] + array_intersect_key($after, array_flip($changed)),
                    location: $book->location,
                );
            }

            return $item;
        });
    }

    /**
     * Typed text as it is kept: lines trimmed, no empty lines.
     */
    public static function plain(string $typed): string
    {
        $lines = array_filter(array_map(trim(...), preg_split('/\R/', $typed) ?: []), fn (string $line): bool => $line !== '');

        return implode("\n", $lines);
    }

    /**
     * How a line item is priced, in words: "$40", "2.5 hours", "2.5 hours at $85".
     */
    public static function pricedAs(LineItemTemplate $item): string
    {
        if ($item->hours !== null && (float) $item->hours > 0) {
            $hours = __(':hours hours', ['hours' => Decimal::plain($item->hours)]);

            return $item->hourly_rate_cents === null ? $hours : __(':hours at :rate', ['hours' => $hours, 'rate' => Money::format($item->hourly_rate_cents)]);
        }

        return Money::format($item->price_cents ?? 0);
    }

    /**
     * The price in cents; zero or nothing is allowed, for a line item that only explains.
     *
     * @throws ValidationException
     */
    private function price(?string $typed): int
    {
        if (preg_match('/^\s*\$?\s*0*(\.0*)?\s*$/', (string) $typed) === 1) {
            return 0;
        }

        return Money::toCents($typed) ?? throw ValidationException::withMessages(['price' => __('Give the price in dollars, such as 42.50, or 0 for one that only explains.')]);
    }

    /**
     * @throws ValidationException
     */
    private function hours(?string $typed): string
    {
        $hours = Decimal::normalize($typed);

        if ($hours === null || (float) $hours <= 0 || preg_match('/^\d{1,6}(\.\d{1,2})?$/', $hours) !== 1) {
            throw ValidationException::withMessages(['hours' => __('Give the hours, with up to two decimals, such as 2.5.')]);
        }

        return $hours;
    }

    /**
     * @return array<string, string|bool>
     */
    private function recorded(LineItemTemplate $item): array
    {
        return [
            'line_item' => $item->name,
            'description' => Str::limit($item->plainDescription(), 120),
            'priced_as' => self::pricedAs($item),
            'estimate_types' => implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $item->estimate_types)),
            'is_active' => $item->is_active,
        ];
    }
}
