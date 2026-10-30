<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Models\AreaTemplate;
use App\Models\PriceBook;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add an area to a price book's list, or rename, re-scope, retire or bring
 * one back (docs/slice-35-pricing-settings-plan.md, step 35b; decision 213).
 * Owner and Leadership only. An estimate's areas keep their own names, so
 * nothing already estimated changes. Every change is recorded.
 */
class SaveAreaTemplate
{
    /**
     * Where an area is: inside, outside, or either (null).
     *
     * @var list<string>
     */
    public const array SIDES = ['interior', 'exterior'];

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, side?: string|null, estimate_types?: list<string>, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?AreaTemplate $area, array $input, User $actor): AreaTemplate
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($area !== null && $area->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'side' => ['nullable', Rule::in(self::SIDES)],
            'estimate_types' => ['required', 'array', 'min:1'],
            'estimate_types.*' => [Rule::in(EstimateType::values())],
            'is_active' => ['boolean'],
        ], attributes: ['estimate_types' => 'estimate types'])->validate();

        $attributes = [
            'name' => trim($values['name']),
            'side' => filled($values['side'] ?? null) ? $values['side'] : null,
            'estimate_types' => array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $values['estimate_types'], true))),
            'is_active' => (bool) ($values['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($book, $area, $attributes, $actor): AreaTemplate {
            if ($area === null) {
                $area = new AreaTemplate;
                $area->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) AreaTemplate::query()->where('price_book_id', $book->id)->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingAreaAdded, $book, actor: $actor, after: $this->recorded($area), location: $book->location);

                return $area;
            }

            $area = AreaTemplate::query()->lockForUpdate()->findOrFail($area->id);
            $before = $this->recorded($area);
            $area->forceFill($attributes)->save();
            $after = $this->recorded($area->refresh());

            $changed = array_keys(array_filter($after, fn (mixed $value, string $field): bool => $value !== $before[$field], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->recordAuditEvent->handle(
                    AuditAction::PricingAreaChanged,
                    $book,
                    actor: $actor,
                    before: ['area' => $before['area']] + array_intersect_key($before, array_flip($changed)),
                    after: ['area' => $after['area']] + array_intersect_key($after, array_flip($changed)),
                    location: $book->location,
                );
            }

            return $area;
        });
    }

    /**
     * Where an area is, in words.
     */
    public static function sideLabel(?string $side): string
    {
        return match ($side) {
            'interior' => __('Inside'),
            'exterior' => __('Outside'),
            default => __('Either'),
        };
    }

    /**
     * @return array<string, string|bool>
     */
    private function recorded(AreaTemplate $area): array
    {
        return [
            'area' => $area->name,
            'inside_or_out' => self::sideLabel($area->side),
            'estimate_types' => implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $area->estimate_types)),
            'is_active' => $area->is_active,
        ];
    }
}
