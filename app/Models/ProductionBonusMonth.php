<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A Production Manager's month of the Production Bonus, settled once the
 * month after it is over (docs/slice-20-compensation-plan.md, step 20b;
 * product-architecture.md, 6.1): the month's revenue and gross-margin
 * dollars, what the bonus earned, the next month's figures, and whether it
 * was payable. Kept as it was.
 *
 * @property int $id
 * @property int $user_id
 * @property string $user_name
 * @property string $month "2026-11".
 * @property int $pay_plan_id
 * @property int $revenue_cents
 * @property int $margin_cents
 * @property int $earned_cents Zero when the month did not earn it.
 * @property int $next_revenue_cents
 * @property int $next_margin_cents
 * @property bool $payable Earned, and the next month held the margin too.
 * @property int|null $compensation_entry_id The bonus entry, when payable.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PayPlan $payPlan
 */
class ProductionBonusMonth extends Model
{
    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A settled Production Bonus month is kept as it was.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revenue_cents' => 'integer',
            'margin_cents' => 'integer',
            'earned_cents' => 'integer',
            'next_revenue_cents' => 'integer',
            'next_margin_cents' => 'integer',
            'payable' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PayPlan, $this>
     */
    public function payPlan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class);
    }
}
