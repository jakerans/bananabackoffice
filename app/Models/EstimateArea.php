<?php

namespace App\Models;

use App\Enums\AreaShape;
use App\Support\Pricing\AreaInput;
use App\Support\Pricing\SurfaceInput;
use Database\Factories\EstimateAreaFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A room or a surface in an estimate's option, with its measurements in feet
 * (docs/slice-31-estimating-plan.md, step 31c).
 *
 * @property int $id
 * @property int $estimate_option_id
 * @property int|null $area_template_id
 * @property string $name
 * @property AreaShape $shape
 * @property string $width
 * @property string $length
 * @property string $height
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EstimateOption $option
 */
class EstimateArea extends Model
{
    /** @use HasFactory<EstimateAreaFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shape' => AreaShape::class,
            'width' => 'decimal:2',
            'length' => 'decimal:2',
            'height' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    public function toInput(): AreaInput
    {
        return new AreaInput(
            name: $this->name,
            shape: $this->shape,
            width: $this->width,
            length: $this->length,
            height: $this->height,
            surfaces: array_values($this->surfaces->map(fn (EstimateSurface $surface): SurfaceInput => $surface->toInput())->all()),
        );
    }

    /**
     * @return BelongsTo<EstimateOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(EstimateOption::class, 'estimate_option_id');
    }

    /**
     * @return HasMany<EstimateSurface, $this>
     */
    public function surfaces(): HasMany
    {
        return $this->hasMany(EstimateSurface::class)->orderBy('sort_order')->orderBy('id');
    }
}
