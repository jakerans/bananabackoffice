<?php

namespace App\Actions\Estimates;

use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Take an option, an area (with its surfaces), a surface or a line item out
 * of an estimate while it is being built (docs/slice-31-estimating-plan.md,
 * step 31c). The estimate itself is never deleted, and it always keeps at
 * least one option.
 */
class RemoveFromEstimate
{
    public function __construct(private ChangeEstimate $changeEstimate) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, EstimateOption|EstimateArea|EstimateSurface|EstimateLineItem $part, User $actor): void
    {
        $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($part): void {
            ChangeEstimate::ensureBelongs($estimate, $part);

            if ($part instanceof EstimateOption && EstimateOption::query()->where('estimate_id', $estimate->id)->count() === 1) {
                throw ValidationException::withMessages(['option' => __('An estimate keeps at least one option.')]);
            }

            $part->delete();
        });
    }
}
