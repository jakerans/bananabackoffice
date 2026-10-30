<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Models\Discount;
use App\Models\Estimate;
use App\Models\EstimateDiscount;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Start an estimate on a Lead, Interior or Exterior
 * (docs/slice-31-estimating-plan.md, step 31c; decision 204). It is priced
 * from the Lead's Location's price book, the default until that Location is
 * customized, at the book's hourly rate for the type, and starts with one
 * empty option, the book's discounts marked to start on new estimates and
 * its default tax rate (step 35a; decision 213). The salesperson the Lead is
 * with, or a manager.
 */
class StartEstimate
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Lead $lead, ?string $type, User $actor): Estimate
    {
        Gate::forUser($actor)->authorize('create', [Estimate::class, $lead]);

        $estimateType = EstimateType::tryFrom((string) $type);

        if ($estimateType === null || ! in_array($estimateType, Estimate::offeredTypes(), true)) {
            throw ValidationException::withMessages(['estimateType' => __('Choose Interior or Exterior.')]);
        }

        $book = PriceBook::forLocation($lead->location);

        if ($book === null || ! ProductionRate::query()->where('price_book_id', $book->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['estimateType' => __('There are no production rates to estimate with yet. Owner or Leadership sets them up in Settings > Pricing Settings.')]);
        }

        return DB::transaction(function () use ($lead, $estimateType, $book, $actor): Estimate {
            $tax = TaxRate::query()->where('price_book_id', $book->id)->where('is_active', true)->where('is_default', true)->orderBy('sort_order')->orderBy('id')->first();

            $estimate = new Estimate;
            $estimate->forceFill([
                'lead_id' => $lead->id,
                'location_id' => $lead->location_id,
                'price_book_id' => $book->id,
                'estimate_type' => $estimateType,
                'hourly_rate_cents' => $book->hourlyRateFor($estimateType),
                'tax_name' => $tax?->name,
                'tax_percent' => $tax?->percent,
                'priced_at' => now(),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            (new EstimateOption)->forceFill([
                'estimate_id' => $estimate->id,
                'name' => __('Option 1'),
                'sort_order' => 1,
            ])->save();

            $discounts = Discount::query()->where('price_book_id', $book->id)->where('is_active', true)->where('is_default', true)->orderBy('sort_order')->orderBy('id')->get();

            foreach ($discounts->values() as $position => $discount) {
                (new EstimateDiscount)->forceFill([
                    'estimate_id' => $estimate->id,
                    'discount_id' => $discount->id,
                    'name' => $discount->name,
                    'kind' => $discount->kind,
                    'value' => $discount->value,
                    'sort_order' => $position,
                ])->save();
            }

            $starting = array_filter([
                'discounts' => $discounts->pluck('name')->all(),
                'tax' => $tax?->name,
            ]);

            $this->recordAuditEvent->handle(
                AuditAction::LeadEstimateStarted,
                $lead,
                actor: $actor,
                after: [
                    'estimate' => $estimateType->label(),
                    'price_book' => $book->label(),
                    'hourly_rate' => Money::format($estimate->hourly_rate_cents),
                ] + $starting,
                location: $lead->location,
            );

            return $estimate;
        });
    }
}
