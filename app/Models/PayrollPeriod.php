<?php

namespace App\Models;

use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A pay period Finance finalized for payroll
 * (docs/slice-20-compensation-plan.md, step 20c; decision 172; decision 133):
 * its entries are **scheduled** from then, and **paid** once Finance marks
 * it paid with payroll's reference. A finalized period is kept as it was: an
 * entry made later is due in the next open period. Changed only once, to
 * mark it paid; never deleted.
 *
 * @property int $id
 * @property string $period "2026-11".
 * @property int $total_cents What its entries came to when it was finalized.
 * @property int $entries_count
 * @property Carbon $finalized_at
 * @property int|null $finalized_by
 * @property string|null $finalized_by_name
 * @property Carbon|null $paid_at
 * @property int|null $paid_by
 * @property string|null $paid_by_name
 * @property string|null $payroll_reference
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayrollPeriod extends Model
{
    public const string STATE_SCHEDULED = 'scheduled';

    public const string STATE_PAID = 'paid';

    public const int REFERENCE_LENGTH = 100;

    protected static function booted(): void
    {
        static::updating(function (self $period): void {
            $allowed = ['paid_at', 'paid_by', 'paid_by_name', 'payroll_reference', 'updated_at'];

            if (array_diff(array_keys($period->getDirty()), $allowed) !== [] || $period->getOriginal('paid_at') !== null) {
                throw new LogicException('A finalized pay period is kept as it was.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A finalized pay period is kept.');
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_cents' => 'integer',
            'entries_count' => 'integer',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function state(): string
    {
        return $this->paid_at === null ? self::STATE_SCHEDULED : self::STATE_PAID;
    }

    public function words(): string
    {
        return PayPeriods::words($this->period);
    }

    public function total(): string
    {
        return Money::format($this->total_cents);
    }
}
