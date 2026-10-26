<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A Location's monthly sold-revenue target, effective from a month
 * (docs/slice-25-marketing-and-executive-boards-plan.md, step 25a; decision
 * 183, question 6; product-architecture.md, 18). The latest effective in a
 * month is that month's. Kept as it was.
 *
 * @property int $id
 * @property int $location_id
 * @property string $month_from "2026-11".
 * @property int $amount_cents
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LocationSalesTarget extends Model
{
    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A sales target is kept as it was. Set a new one from a month.');
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
            'amount_cents' => 'integer',
        ];
    }

    /**
     * A Location's target for a month, in cents; nothing when none is set.
     */
    public static function for(int $locationId, string $month): ?int
    {
        return self::query()
            ->where('location_id', $locationId)
            ->where('month_from', '<=', $month)
            ->orderByDesc('month_from')
            ->orderByDesc('id')
            ->value('amount_cents');
    }
}
