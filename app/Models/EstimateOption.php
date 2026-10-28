<?php

namespace App\Models;

use Database\Factories\EstimateOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One option of an estimate, such as "Whole interior" or "Bedrooms only"
 * (docs/slice-31-estimating-plan.md, step 31c; decision 204): its own areas
 * and line items, usually started as a copy of another option.
 *
 * @property int $id
 * @property int $estimate_id
 * @property string $name
 * @property string|null $description For the customer.
 * @property int $sort_order
 * @property int $total_cents Its total as last priced, kept for lists.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Estimate $estimate
 */
class EstimateOption extends Model
{
    /** @use HasFactory<EstimateOptionFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'total_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * @return HasMany<EstimateArea, $this>
     */
    public function areas(): HasMany
    {
        return $this->hasMany(EstimateArea::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EstimateLineItem, $this>
     */
    public function lineItems(): HasMany
    {
        return $this->hasMany(EstimateLineItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
