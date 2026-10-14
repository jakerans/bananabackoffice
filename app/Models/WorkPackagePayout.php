<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One amount a Work Package's payout has had (docs/slice-13-giving-work-plan.md,
 * step 13a; product-architecture.md, 7.6: "Payout revisions are versioned").
 * The latest is the payout now. Kept as it was, for good. Finance-sensitive:
 * shown only to those with `payouts.view`.
 *
 * @property int $id
 * @property int $work_package_id
 * @property int $amount_cents
 * @property string|null $reason Why it changed. Empty for the first amount.
 * @property int|null $set_by
 * @property string|null $set_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackage $package
 */
class WorkPackagePayout extends Model
{
    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A payout a package had is kept as it was. Changing the payout adds a new one.');
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
     * @return BelongsTo<WorkPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(WorkPackage::class, 'work_package_id');
    }

    /**
     * The amount as it is shown.
     */
    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }
}
