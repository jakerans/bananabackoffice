<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A job-costing accuracy strike (decision 133; docs/slice-19-job-costing-plan.md,
 * step 19b; decision 170, question 7): a cost bought with no purchase order,
 * or a correction after submission above the larger of $250 or 2% of
 * revenue. Counted against the job's Production Manager; the third in a
 * rolling 90 days tells the Location's managers and Leadership. Kept as it
 * was.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $production_manager_id
 * @property string|null $production_manager_name
 * @property string $cause One of the CAUSE_ constants.
 * @property int|null $project_cost_id
 * @property int|null $amount_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ProjectAccuracyStrike extends Model
{
    public const string CAUSE_MISSING_PURCHASE_ORDER = 'missing_purchase_order';

    public const string CAUSE_LARGE_CORRECTION = 'large_correction';

    /** A correction above the larger of these is a strike (decision 133). */
    public const int CORRECTION_FLOOR_CENTS = 25000;

    public const int CORRECTION_SHARE_PERCENT = 2;

    /** The rolling window strikes are counted in, and the count that tells the managers. */
    public const int WINDOW_DAYS = 90;

    public const int NOTIFY_AT = 3;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('An accuracy strike is kept as it was.');
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

    public function causeLabel(): string
    {
        return $this->cause === self::CAUSE_MISSING_PURCHASE_ORDER ? 'No purchase order' : 'Correction after submission';
    }

    /**
     * The correction above which a change after submission is a strike: the
     * larger of $250 or 2% of revenue.
     */
    public static function thresholdCents(int $revenueCents): int
    {
        return max(self::CORRECTION_FLOOR_CENTS, intdiv($revenueCents * self::CORRECTION_SHARE_PERCENT, 100));
    }
}
