<?php

namespace App\Models;

use Database\Factories\AreaTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A kind of area an estimate starts from, such as "Bedroom" or "Front Side"
 * (docs/slice-31-estimating-plan.md, step 31a).
 *
 * @property int $id
 * @property int $price_book_id
 * @property string $name
 * @property string|null $side "interior", "exterior", or null for either.
 * @property list<string> $estimate_types
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 */
class AreaTemplate extends Model
{
    /** @use HasFactory<AreaTemplateFactory> */
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
}
