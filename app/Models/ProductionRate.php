<?php

namespace App\Models;

use App\Enums\RateCalculation;
use App\Enums\RateMeasure;
use Database\Factories\ProductionRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How long, or how much, one kind of work takes in a price book, as
 * PaintScout's production rates do (docs/slice-31-estimating-plan.md, step
 * 31a; decision 196). A rate measures something (wall area, length, a count),
 * and turns it into hours or a price by its calculation. Its values are for
 * how hard the work is, easiest first; the estimate picks one.
 *
 * Decimals are kept as the database's exact strings, never as floats.
 *
 * @property int $id
 * @property int $price_book_id
 * @property int|null $rate_category_id Null for a rate that came from PaintScout without a category.
 * @property string $name
 * @property string|null $customer_label What the customer's estimate calls it, when different.
 * @property RateMeasure $measure
 * @property RateCalculation $calculation
 * @property string|null $value_1
 * @property string|null $value_2
 * @property string|null $value_3
 * @property string|null $value_4
 * @property int $default_coats
 * @property bool $uses_coats Whether coats multiply its time.
 * @property string|null $prep_hours
 * @property string|null $coverage Paint used for each one, where the rate sets it.
 * @property int|null $hourly_rate_cents Its own hourly rate, when it differs from the book's.
 * @property int|null $paint_product_id Its default paint.
 * @property list<string> $estimate_types
 * @property bool $excluded_from_discount
 * @property bool $excluded_from_tax
 * @property string|null $notes
 * @property array<string, mixed>|null $details The rest of what PaintScout keeps for it, kept for later steps.
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 * @property-read RateCategory|null $category
 * @property-read PaintProduct|null $paint
 */
class ProductionRate extends Model
{
    /** @use HasFactory<ProductionRateFactory> */
    use HasFactory;

    /** A rate has at most this many values. */
    public const int VALUES = 4;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measure' => RateMeasure::class,
            'calculation' => RateCalculation::class,
            'value_1' => 'decimal:4',
            'value_2' => 'decimal:4',
            'value_3' => 'decimal:4',
            'value_4' => 'decimal:4',
            'prep_hours' => 'decimal:2',
            'coverage' => 'decimal:4',
            'default_coats' => 'integer',
            'uses_coats' => 'boolean',
            'hourly_rate_cents' => 'integer',
            'estimate_types' => 'array',
            'excluded_from_discount' => 'boolean',
            'excluded_from_tax' => 'boolean',
            'details' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Its values in order, easiest first, empty ones as null.
     *
     * @return list<string|null>
     */
    public function values(): array
    {
        return [$this->value_1, $this->value_2, $this->value_3, $this->value_4];
    }

    /**
     * Its values up to the last one in use, so a gap in the middle still shows.
     * PaintScout fills a rate's unused values with zero, so a trailing zero
     * after the first value is not shown; it is still kept as it was.
     *
     * @return list<string|null>
     */
    public function shownValues(): array
    {
        $values = $this->values();

        while (count($values) > 1 && (end($values) === null || (float) end($values) === 0.0)) {
            array_pop($values);
        }

        return $values[0] === null && count($values) === 1 ? [] : $values;
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /**
     * @return BelongsTo<RateCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RateCategory::class, 'rate_category_id');
    }

    /**
     * @return BelongsTo<PaintProduct, $this>
     */
    public function paint(): BelongsTo
    {
        return $this->belongsTo(PaintProduct::class, 'paint_product_id');
    }
}
