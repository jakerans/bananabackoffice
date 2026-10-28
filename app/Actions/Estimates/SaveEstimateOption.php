<?php

namespace App\Actions\Estimates;

use App\Models\Estimate;
use App\Models\EstimateLineItem;
use App\Models\EstimateOption;
use App\Models\EstimateSurface;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Name an option of an estimate and describe it for the customer, or start a
 * new option as a copy of another, with all its areas, surfaces and line
 * items (docs/slice-31-estimating-plan.md, step 31c; decision 204, question 1).
 */
class SaveEstimateOption
{
    public function __construct(private ChangeEstimate $changeEstimate) {}

    /**
     * @param  array{name?: string, description?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, EstimateOption $option, array $input, User $actor): EstimateOption
    {
        return $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($option, $input): EstimateOption {
            ChangeEstimate::ensureBelongs($estimate, $option);

            $values = Validator::make($input, [
                'name' => ['required', 'string', 'max:150'],
                'description' => ['nullable', 'string', 'max:5000'],
            ])->validate();

            $option->forceFill([
                'name' => trim($values['name']),
                'description' => filled($values['description'] ?? null) ? trim($values['description']) : null,
            ])->save();

            return $option;
        });
    }

    /**
     * A new option, a copy of this one, placed after the last.
     *
     * @throws AuthorizationException
     */
    public function copy(Estimate $estimate, EstimateOption $option, User $actor): EstimateOption
    {
        return $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($option): EstimateOption {
            ChangeEstimate::ensureBelongs($estimate, $option);

            $copy = $option->replicate(['total_cents']);
            $copy->forceFill([
                'name' => __(':name (copy)', ['name' => $option->name]),
                'sort_order' => (int) EstimateOption::query()->where('estimate_id', $estimate->id)->max('sort_order') + 1,
            ])->save();

            foreach ($option->areas()->with('surfaces')->get() as $area) {
                $areaCopy = $area->replicate();
                $areaCopy->forceFill(['estimate_option_id' => $copy->id])->save();

                $area->surfaces->each(fn (EstimateSurface $surface) => $surface->replicate()->forceFill(['estimate_area_id' => $areaCopy->id])->save());
            }

            $option->lineItems()->get()->each(fn (EstimateLineItem $item) => $item->replicate()->forceFill(['estimate_option_id' => $copy->id])->save());

            return $copy;
        });
    }
}
