<?php

namespace App\Actions\Estimates;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\User;
use App\Support\Pricing\EstimatePricing;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Make one change to an estimate (docs/slice-31-estimating-plan.md, step
 * 31c): the person must be allowed to build it, the estimate is locked while
 * the change is saved, and every option's total is worked out again, all
 * together or not at all. Each action that changes an estimate goes through
 * here.
 */
class ChangeEstimate
{
    /** Feet, hours or a count, as typed: up to 999,999.99. */
    public const array MEASUREMENT_RULES = ['string', 'max:12', 'regex:/^\s*\d{0,6}(\.\d{1,2})?\s*$/', 'not_regex:/^\s*\.?\s*$/'];

    public function __construct(private EstimatePricing $pricing) {}

    /**
     * @template TResult
     *
     * @param  Closure(Estimate): TResult  $change  Given the locked estimate.
     * @return TResult
     *
     * @throws AuthorizationException
     */
    public function handle(Estimate $estimate, User $actor, Closure $change, string $ability = 'update'): mixed
    {
        Gate::forUser($actor)->authorize($ability, $estimate);

        return DB::transaction(function () use ($estimate, $change): mixed {
            $locked = Estimate::query()->lockForUpdate()->findOrFail($estimate->id);
            $result = $change($locked);

            $locked->touch();
            $this->pricing->refreshTotals($locked);

            return $result;
        });
    }

    /**
     * Refuse an option, area, surface or line item that is not this estimate's.
     *
     * @throws AuthorizationException
     */
    public static function ensureBelongs(Estimate $estimate, EstimateOption|EstimateArea|EstimateSurface|EstimateLineItem $part): void
    {
        $optionId = match (true) {
            $part instanceof EstimateOption => $part->id,
            $part instanceof EstimateArea, $part instanceof EstimateLineItem => $part->estimate_option_id,
            $part instanceof EstimateSurface => EstimateArea::query()->whereKey($part->estimate_area_id)->value('estimate_option_id'),
        };

        if (! EstimateOption::query()->whereKey($optionId)->where('estimate_id', $estimate->id)->exists()) {
            throw new AuthorizationException;
        }
    }
}
