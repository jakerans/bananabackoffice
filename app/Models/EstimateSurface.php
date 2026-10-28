<?php

namespace App\Models;

use App\Support\Pricing\PaintSnapshot;
use App\Support\Pricing\RateSnapshot;
use App\Support\Pricing\SurfaceInput;
use Database\Factories\EstimateSurfaceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One piece of work in an estimate's area, priced by a production rate
 * (docs/slice-31-estimating-plan.md, step 31c). It keeps a copy of the rate
 * and the paint as they were when it was added or last repriced, so a later
 * change in the price book never changes it (decision 197, question 4).
 *
 * @property int $id
 * @property int $estimate_area_id
 * @property int|null $production_rate_id The rate it was copied from.
 * @property int|null $paint_product_id The paint it was copied from.
 * @property array<string, mixed> $rate See RateSnapshot::toArray().
 * @property array<string, mixed>|null $paint See PaintSnapshot::toArray().
 * @property int $coats
 * @property string $quantity
 * @property string|null $prep_hours
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EstimateArea $area
 */
class EstimateSurface extends Model
{
    /** @use HasFactory<EstimateSurfaceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'array',
            'paint' => 'array',
            'coats' => 'integer',
            'quantity' => 'decimal:2',
            'prep_hours' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function rateSnapshot(): RateSnapshot
    {
        return RateSnapshot::fromArray($this->rate);
    }

    public function paintSnapshot(): ?PaintSnapshot
    {
        return $this->paint === null ? null : PaintSnapshot::fromArray($this->paint);
    }

    public function toInput(): SurfaceInput
    {
        return new SurfaceInput(
            rate: $this->rateSnapshot(),
            paint: $this->paintSnapshot(),
            coats: $this->coats,
            quantity: $this->quantity,
            prepHours: $this->prep_hours,
        );
    }

    /**
     * @return BelongsTo<EstimateArea, $this>
     */
    public function area(): BelongsTo
    {
        return $this->belongsTo(EstimateArea::class, 'estimate_area_id');
    }

    /**
     * @return BelongsTo<ProductionRate, $this>
     */
    public function productionRate(): BelongsTo
    {
        return $this->belongsTo(ProductionRate::class);
    }

    /**
     * @return BelongsTo<PaintProduct, $this>
     */
    public function paintProduct(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class);
    }
}
