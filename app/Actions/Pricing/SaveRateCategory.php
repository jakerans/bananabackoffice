<?php

namespace App\Actions\Pricing;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Enums\Permission;
use App\Models\PriceBook;
use App\Models\RateCategory;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a rate category to a price book, or rename, re-scope or switch one off
 * (docs/slice-31-estimating-plan.md, step 31a). Owner and Leadership only.
 * Switching a category off hides its rates from new estimates; nothing is
 * deleted.
 */
class SaveRateCategory
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array{name?: string, estimate_types?: list<string>, is_active?: bool}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(PriceBook $book, ?RateCategory $category, array $input, User $actor): RateCategory
    {
        Gate::forUser($actor)->authorize(Permission::ManagePricing);

        if ($category !== null && $category->price_book_id !== $book->id) {
            throw new AuthorizationException;
        }

        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:150'],
            'estimate_types' => ['required', 'array', 'min:1'],
            'estimate_types.*' => [Rule::in(EstimateType::values())],
            'is_active' => ['boolean'],
        ], attributes: ['estimate_types' => 'estimate types'])->validate();

        $attributes = [
            'name' => trim($values['name']),
            'estimate_types' => array_values(array_filter(EstimateType::values(), fn (string $type): bool => in_array($type, $values['estimate_types'], true))),
            'is_active' => (bool) ($values['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($book, $category, $attributes, $actor): RateCategory {
            if ($category === null) {
                $category = new RateCategory;
                $category->forceFill($attributes + [
                    'price_book_id' => $book->id,
                    'sort_order' => (int) RateCategory::query()->where('price_book_id', $book->id)->max('sort_order') + 1,
                ])->save();

                $this->recordAuditEvent->handle(AuditAction::PricingCategoryAdded, $book, actor: $actor, after: $this->recorded($category), location: $book->location);

                return $category;
            }

            $category = RateCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $this->recorded($category);
            $category->forceFill($attributes)->save();
            $after = $this->recorded($category);

            if ($before !== $after) {
                $this->recordAuditEvent->handle(AuditAction::PricingCategoryChanged, $book, actor: $actor, before: $before, after: $after, location: $book->location);
            }

            return $category;
        });
    }

    /**
     * @return array{category: string, estimate_types: string, is_active: bool}
     */
    private function recorded(RateCategory $category): array
    {
        return [
            'category' => $category->name,
            'estimate_types' => implode(', ', array_map(fn (string $type): string => EstimateType::from($type)->label(), $category->estimate_types)),
            'is_active' => $category->is_active,
        ];
    }
}
