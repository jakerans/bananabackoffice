<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\EstimateDiscount;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Choose an estimate's discounts and its tax from its price book's named
 * ones (docs/slice-31-estimating-plan.md, step 31c). They apply to every
 * option (decision 204, question 3). Each is copied as it is now, so a later
 * change to a named discount or tax rate reaches the estimate only when it is
 * repriced. A change is recorded on the Lead.
 */
class ChangeEstimateDiscounts
{
    public function __construct(
        private ChangeEstimate $changeEstimate,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * @param  list<int|string>  $discountIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, array $discountIds, int|string|null $taxRateId, User $actor): void
    {
        $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($discountIds, $taxRateId, $actor): void {
            $ids = array_values(array_unique(array_map(intval(...), array_filter($discountIds, is_numeric(...)))));
            $kept = EstimateDiscount::query()->where('estimate_id', $estimate->id)->get();

            // A discount the estimate already has stays as it was copied; a new one comes from the book.
            $chosen = Discount::query()->where('price_book_id', $estimate->price_book_id)->whereKey($ids)->orderBy('sort_order')->orderBy('id')->get();

            if ($chosen->count() !== count($ids) || $chosen->contains(fn (Discount $discount): bool => ! $discount->is_active && ! $kept->contains('discount_id', $discount->id))) {
                throw ValidationException::withMessages(['discountIds' => __('Choose from this estimate\'s discounts.')]);
            }

            $tax = filled($taxRateId) ? TaxRate::query()->where('price_book_id', $estimate->price_book_id)->find((int) $taxRateId) : null;

            if (filled($taxRateId) && ($tax === null || (! $tax->is_active && $tax->name !== $estimate->tax_name))) {
                throw ValidationException::withMessages(['taxRateId' => __('Choose from this estimate\'s tax rates.')]);
            }

            $before = ['discounts' => $kept->pluck('name')->all(), 'tax' => $estimate->tax_name];

            EstimateDiscount::query()->where('estimate_id', $estimate->id)->whereNotIn('discount_id', $chosen->modelKeys())->delete();

            foreach ($chosen as $position => $discount) {
                if (! $kept->contains('discount_id', $discount->id)) {
                    (new EstimateDiscount)->forceFill([
                        'estimate_id' => $estimate->id,
                        'discount_id' => $discount->id,
                        'name' => $discount->name,
                        'kind' => $discount->kind,
                        'value' => $discount->value,
                        'sort_order' => $position,
                    ])->save();
                }
            }

            if ($tax?->name !== $estimate->tax_name) {
                $estimate->forceFill(['tax_name' => $tax?->name, 'tax_percent' => $tax?->percent])->save();
            }

            $after = ['discounts' => $chosen->pluck('name')->all(), 'tax' => $tax?->name];

            if ($before !== $after) {
                $this->recordAuditEvent->handle(
                    AuditAction::LeadEstimateDiscountsChanged,
                    $estimate->lead,
                    actor: $actor,
                    before: $before,
                    after: $after,
                    location: $estimate->location,
                );
            }
        });
    }
}
