<?php

namespace App\Models;

use App\Enums\LineItemPricing;
use App\Support\Pricing\LineItemInput;
use Database\Factories\EstimateLineItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A line of an estimate's option outside its areas, such as a trip charge
 * or color samples (docs/slice-31-estimating-plan.md, step 31c).
 *
 * @property int $id
 * @property int $estimate_option_id
 * @property int|null $line_item_template_id
 * @property string $name
 * @property string|null $description
 * @property LineItemPricing $pricing
 * @property string|null $hours
 * @property int|null $price_cents
 * @property int|null $hourly_rate_cents Its own hourly rate, when priced by hours.
 * @property string|null $quantity
 * @property string|null $hours_each
 * @property int|null $price_each_cents
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read EstimateOption $option
 */
class EstimateLineItem extends Model
{
    /** @use HasFactory<EstimateLineItemFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'pricing' => LineItemPricing::class,
            'hours' => 'decimal:2',
            'price_cents' => 'integer',
            'hourly_rate_cents' => 'integer',
            'quantity' => 'decimal:2',
            'hours_each' => 'decimal:2',
            'price_each_cents' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function toInput(): LineItemInput
    {
        return match ($this->pricing) {
            LineItemPricing::Hours => new LineItemInput($this->name, hours: $this->hours ?? '0', hourlyRateCents: $this->hourly_rate_cents),
            LineItemPricing::Price => new LineItemInput($this->name, hours: $this->hours, priceCents: $this->price_cents ?? 0),
            LineItemPricing::Quantity => new LineItemInput($this->name, quantity: $this->quantity ?? '0', hoursEach: $this->hours_each ?? '0', priceEachCents: $this->price_each_cents ?? 0),
        };
    }

    /**
     * @return BelongsTo<LineItemTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(LineItemTemplate::class, 'line_item_template_id');
    }

    /**
     * @return BelongsTo<EstimateOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(EstimateOption::class, 'estimate_option_id');
    }
}
