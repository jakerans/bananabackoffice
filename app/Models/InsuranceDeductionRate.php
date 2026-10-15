<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The share of payout deducted when a subcontractor uses Banana's coverage
 * instead of their own insurance (product-architecture.md, 7.2; decision
 * 133; decision 151, question 7). One company rate, effective-dated; every
 * earlier rate is kept as it was.
 *
 * @property int $id
 * @property int $basis_points 1000 is 10%.
 * @property Carbon $effective_on
 * @property int|null $set_by
 * @property string|null $set_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class InsuranceDeductionRate extends Model
{
    /** The largest rate that can be set: half the payout. */
    public const int MAX_BASIS_POINTS = 5000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A deduction rate is kept as it was. A change adds a new rate from a day.');
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
            'basis_points' => 'integer',
            'effective_on' => 'date',
        ];
    }

    /**
     * The rate in force on a day: the latest that took effect on or before it.
     */
    public static function inForceOn(CarbonInterface $day): ?self
    {
        return self::query()->where('effective_on', '<=', $day->copy()->startOfDay())->orderByDesc('effective_on')->orderByDesc('id')->first();
    }

    /**
     * The rate as it is said: "10%", "7.5%".
     */
    public function percent(): string
    {
        return rtrim(rtrim(number_format($this->basis_points / 100, 2), '0'), '.').'%';
    }

    /**
     * The deduction from a payout at this rate, in cents, rounded to the cent.
     */
    public function of(int $payoutCents): int
    {
        return intdiv($payoutCents * $this->basis_points + 5000, 10000);
    }
}
