<?php

namespace App\Actions\Estimates;

use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateSurface;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\User;
use App\Support\Pricing\Decimal;
use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\RateSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Add a piece of work to an area of an estimate, or change it
 * (docs/slice-31-estimating-plan.md, step 31c): the production rate, the
 * coats where the rate uses them, how many for a rate that counts items,
 * the paint and the prep hours.
 *
 * The surface keeps a copy of the rate and the paint as they are now. A copy
 * already kept is not replaced when the same rate or paint is chosen again,
 * so the estimate keeps the prices it was built with (decision 197, question
 * 4); repricing the estimate takes today's.
 */
class SaveEstimateSurface
{
    public function __construct(private ChangeEstimate $changeEstimate) {}

    /**
     * @param  array{production_rate_id?: int|string|null, coats?: int|string|null, quantity?: string|null, paint_product_id?: int|string|null, prep_hours?: string|null}  $input
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Estimate $estimate, EstimateArea $area, ?EstimateSurface $surface, array $input, User $actor): EstimateSurface
    {
        return $this->changeEstimate->handle($estimate, $actor, function (Estimate $estimate) use ($area, $surface, $input): EstimateSurface {
            ChangeEstimate::ensureBelongs($estimate, $area);

            if ($surface !== null && $surface->estimate_area_id !== $area->id) {
                throw new AuthorizationException;
            }

            $values = Validator::make($input, [
                'production_rate_id' => ['required', 'integer'],
                'coats' => ['nullable', 'integer', 'min:1', 'max:'.ProductionRate::VALUES],
                'quantity' => ['nullable', ...ChangeEstimate::MEASUREMENT_RULES],
                'paint_product_id' => ['nullable', 'integer'],
                'prep_hours' => ['nullable', ...ChangeEstimate::MEASUREMENT_RULES],
            ], attributes: ['production_rate_id' => 'rate', 'paint_product_id' => 'paint', 'prep_hours' => 'prep hours'])->validate();

            $rate = $this->rate($estimate, (int) $values['production_rate_id'], $surface);
            $paint = filled($values['paint_product_id'] ?? null) ? $this->paint($estimate, (int) $values['paint_product_id'], $surface) : null;
            $snapshot = $surface !== null && $surface->production_rate_id === $rate->id ? $surface->rateSnapshot() : RateSnapshot::fromProductionRate($rate);
            $quantity = Decimal::normalize($values['quantity'] ?? null);

            if ($snapshot->measure === RateMeasure::Count && ($quantity === null || (float) $quantity <= 0)) {
                throw ValidationException::withMessages(['quantity' => __('Say how many.')]);
            }

            $surface ??= new EstimateSurface;
            $surface->forceFill([
                'estimate_area_id' => $area->id,
                'production_rate_id' => $rate->id,
                'rate' => $snapshot->toArray(),
                'paint_product_id' => $paint?->id,
                'paint' => match (true) {
                    $paint === null, $snapshot->calculation === RateCalculation::PricePerUnit => null,
                    $surface->paint_product_id === $paint->id && $surface->paint !== null => $surface->paint,
                    default => PaintSnapshot::fromPaintProduct($paint)->toArray(),
                },
                'coats' => $snapshot->coatsFor((int) ($values['coats'] ?? $rate->default_coats)),
                'quantity' => $snapshot->measure === RateMeasure::Count ? $quantity : '1',
                'prep_hours' => Decimal::normalize($values['prep_hours'] ?? null),
                'sort_order' => $surface->exists ? $surface->sort_order : (int) EstimateSurface::query()->where('estimate_area_id', $area->id)->max('sort_order') + 1,
            ])->save();

            return $surface;
        });
    }

    /**
     * A rate from the estimate's price book, offered for its type; the one it already has is always allowed.
     *
     * @throws ValidationException
     */
    private function rate(Estimate $estimate, int $id, ?EstimateSurface $surface): ProductionRate
    {
        $rate = ProductionRate::query()->where('price_book_id', $estimate->price_book_id)->find($id);

        $kept = $surface !== null && $surface->production_rate_id === $id;

        if ($rate === null || (! $kept && (! $rate->is_active || ! in_array($estimate->estimate_type->value, $rate->estimate_types, true)))) {
            throw ValidationException::withMessages(['production_rate_id' => __('Choose a rate from this estimate\'s price book.')]);
        }

        return $rate;
    }

    /**
     * A paint from the estimate's price book; the one it already has is always allowed.
     *
     * @throws ValidationException
     */
    private function paint(Estimate $estimate, int $id, ?EstimateSurface $surface): PaintProduct
    {
        $paint = PaintProduct::query()->where('price_book_id', $estimate->price_book_id)->find($id);

        if ($paint === null || (! $paint->is_active && $surface?->paint_product_id !== $id)) {
            throw ValidationException::withMessages(['paint_product_id' => __('Choose a paint from this estimate\'s price book.')]);
        }

        return $paint;
    }
}
