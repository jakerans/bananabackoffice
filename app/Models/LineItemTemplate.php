<?php

namespace App\Models;

use Database\Factories\LineItemTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line an estimate can carry: a written section such as "Interior
 * Preparation", or a priced item such as "Time and Materials"
 * (docs/slice-31-estimating-plan.md, step 31a).
 *
 * @property int $id
 * @property int $price_book_id
 * @property string $name
 * @property string|null $description As PaintScout wrote it, formatted.
 * @property int|null $price_cents
 * @property string|null $hours
 * @property bool $is_calculated Whether its price is worked out from its hours.
 * @property int|null $hourly_rate_cents Its own hourly rate, when it differs.
 * @property list<string> $estimate_types
 * @property bool $is_active
 * @property int $sort_order
 * @property string|null $source_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PriceBook $priceBook
 */
class LineItemTemplate extends Model
{
    /** @use HasFactory<LineItemTemplateFactory> */
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
            'hours' => 'decimal:2',
            'is_calculated' => 'boolean',
            'hourly_rate_cents' => 'integer',
            'estimate_types' => 'array',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Its description as plain text.
     */
    public function plainDescription(): string
    {
        $text = html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '<br>', '<br/>', '<br />'], "\n", (string) $this->description)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{2,}/", "\n", $text));
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }
}
