<?php

namespace App\Actions\Estimates;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\EstimateType;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Models\PriceBook;
use App\Models\ProductionRate;
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
 * empty option. The salesperson the Lead is with, or a manager.
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
            $estimate = new Estimate;
            $estimate->forceFill([
                'lead_id' => $lead->id,
                'location_id' => $lead->location_id,
                'price_book_id' => $book->id,
                'estimate_type' => $estimateType,
                'hourly_rate_cents' => $book->hourlyRateFor($estimateType),
                'priced_at' => now(),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            (new EstimateOption)->forceFill([
                'estimate_id' => $estimate->id,
                'name' => __('Option 1'),
                'sort_order' => 1,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::LeadEstimateStarted,
                $lead,
                actor: $actor,
                after: [
                    'estimate' => $estimateType->label(),
                    'price_book' => $book->label(),
                    'hourly_rate' => Money::format($estimate->hourly_rate_cents),
                ],
                location: $lead->location,
            );

            return $estimate;
        });
    }
}
