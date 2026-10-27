<?php

namespace App\Models;

use App\Enums\DiscountKind;
use Database\Factories\DiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named discount an estimate can be given, such as a Brand Ambassador's
 * savings or paying by check (docs/slice-31-estimating-plan.md, step 31b).
 *
 * @property int $id
 * @property int $price_book_id
 * @property string $name
 * @property DiscountKind $kind
 * @property string $value A percentage, or dollars.
 * @property bool $is_default
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 */
class Discount extends Model
{
    /** @use HasFactory<DiscountFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DiscountKind::class,
            'value' => 'decimal:4',
            'is_default' => 'boolean',
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
