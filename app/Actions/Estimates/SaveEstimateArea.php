<?php

namespace App\Actions\Estimates;

use App\Enums\AreaShape;
use App\Models\AreaTemplate;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateOption;
use App\Models\User;
use App\Support\Pricing\Decimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Add a room or a surface to an option of an estimate, or change its name,
 * shape or measurements (docs/slice-31-estimating-plan.md, step 31c).
 */
class SaveEstimateArea
{
    public function __construct(private ChangeEstimate $changeEstimate) {}

    /**
     * @param  array{area_template_id?: int|string|null, name?: string, shape?: string, width?: string, length?: string, height?: string}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, EstimateOption $option, ?EstimateArea $area, array $input, User $actor): EstimateArea
    {
        return $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($option, $area, $input): EstimateArea {
            ChangeEstimate::ensureBelongs($estimate, $option);

            if ($area !== null && $area->estimate_option_id !== $option->id) {
                throw new AuthorizationException;
            }

            $values = Validator::make($input, [
                'area_template_id' => ['nullable', Rule::exists('area_templates', 'id')->where('price_book_id', $estimate->price_book_id)],
                'name' => ['required', 'string', 'max:150'],
                'shape' => ['required', Rule::enum(AreaShape::class)],
                'width' => ['required', ...ChangeEstimate::MEASUREMENT_RULES],
                'length' => ['required', ...ChangeEstimate::MEASUREMENT_RULES],
                'height' => ['required', ...ChangeEstimate::MEASUREMENT_RULES],
            ], attributes: ['area_template_id' => 'area'])->validate();

            $area ??= new EstimateArea;
            $area->forceFill([
                'estimate_option_id' => $option->id,
                'area_template_id' => filled($values['area_template_id'] ?? null) ? AreaTemplate::query()->findOrFail((int) $values['area_template_id'])->id : null,
                'name' => trim($values['name']),
                'shape' => AreaShape::from($values['shape']),
                'width' => Decimal::normalize($values['width']),
                'length' => Decimal::normalize($values['length']),
                'height' => Decimal::normalize($values['height']),
                'sort_order' => $area->exists ? $area->sort_order : (int) EstimateArea::query()->where('estimate_option_id', $option->id)->max('sort_order') + 1,
            ])->save();

            return $area;
        });
    }
}
