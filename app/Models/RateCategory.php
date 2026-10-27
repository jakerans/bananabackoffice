<?php

namespace App\Models;

use Database\Factories\RateCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of production rates in a price book, such as "Walls" or "Exterior
 * Prep" (docs/slice-31-estimating-plan.md, step 31a).
 *
 * @property int $id
 * @property int $price_book_id
 * @property string $name
 * @property list<string> $estimate_types The estimate types it is offered on.
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key Its key in PaintScout, when imported.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 */
class RateCategory extends Model
{
    /** @use HasFactory<RateCategoryFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimate_types' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /**
     * @return HasMany<ProductionRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(ProductionRate::class)->orderBy('sort_order')->orderBy('id');
    }
}
