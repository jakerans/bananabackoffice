<?php

namespace App\Models;

use Database\Factories\PaintProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A paint or primer in a price book, with its price and how far one unit goes
 * (docs/slice-31-estimating-plan.md, step 31a).
 *
 * @property int $id
 * @property int $price_book_id
 * @property string $name
 * @property string $unit_label Usually "gal".
 * @property int $price_cents For one unit.
 * @property string|null $coverage_area Square feet one unit covers.
 * @property string|null $coverage_length Feet of trim one unit covers.
 * @property string|null $coverage_each Units used for each item counted.
 * @property list<string> $estimate_types
 * @property string|null $manufacturer
 * @property string|null $category
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 */
class PaintProduct extends Model
{
    /** @use HasFactory<PaintProductFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'coverage_area' => 'decimal:3',
            'coverage_length' => 'decimal:3',
            'coverage_each' => 'decimal:4',
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
}
