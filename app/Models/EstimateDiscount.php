<?php

namespace App\Models;

use App\Enums\DiscountKind;
use App\Support\Pricing\DiscountInput;
use Database\Factories\EstimateDiscountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A discount an estimate was given, copied from the price book's named
 * discount as it was (docs/slice-31-estimating-plan.md, step 31c).
 *
 * @property int $id
 * @property int $estimate_id
 * @property int|null $discount_id The named discount it was copied from.
 * @property string $name
 * @property DiscountKind $kind
 * @property string $value A percentage, or dollars.
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Estimate $estimate
 */
class EstimateDiscount extends Model
{
    /** @use HasFactory<EstimateDiscountFactory> */
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
            'sort_order' => 'integer',
        ];
    }

    public function toInput(): DiscountInput
    {
        return new DiscountInput($this->name, $this->kind, $this->value);
    }

    /**
     * @return BelongsTo<Discount, $this>
     */
    public function discount(): BelongsTo
    {
        return $this->belongsTo(Discount::class);
    }

    /**
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }
}
