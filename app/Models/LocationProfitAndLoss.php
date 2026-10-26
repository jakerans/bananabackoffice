<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Location's profit and loss for a month, as its QuickBooks company
 * reported it when Backoffice last read it (docs/slice-28-quickbooks-plan.md,
 * step 28b; decision 189, questions 5 and 6). Backoffice shows QuickBooks'
 * numbers; it never works them out again (product-architecture.md, 9.8).
 *
 * @property int $id
 * @property int $location_id
 * @property string $month "2026-10".
 * @property int $income_cents
 * @property int $cost_of_goods_cents
 * @property int $expenses_cents
 * @property int $net_operating_income_cents
 * @property int $net_income_cents
 * @property Carbon $read_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 */
class LocationProfitAndLoss extends Model
{
    protected $table = 'location_profit_and_losses';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'income_cents' => 'integer',
            'cost_of_goods_cents' => 'integer',
            'expenses_cents' => 'integer',
            'net_operating_income_cents' => 'integer',
            'net_income_cents' => 'integer',
            'read_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Net income over income, in hundredths of a percent; nothing with no income.
     */
    public function netProfitBasisPoints(): ?int
    {
        return self::percent($this->income_cents, $this->net_income_cents);
    }

    public static function percent(int $income, int $net): ?int
    {
        return $income === 0 ? null : (int) round($net * 10000 / $income);
    }
}
