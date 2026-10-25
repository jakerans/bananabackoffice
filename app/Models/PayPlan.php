<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A pay plan a salesperson or Production Manager is on, effective from a day
 * (docs/slice-20-compensation-plan.md, step 20a; decision 172;
 * product-architecture.md, 5.7 and 6.1). Its base and rate are kept as they
 * were set, so a later change to the plans never changes what an earlier one
 * paid. The latest effective on a day is the plan that day. Kept as it was:
 * a change is a new plan from a day.
 *
 * @property int $id
 * @property int $user_id
 * @property string $family One of the FAMILY_ constants.
 * @property string $plan One of the PLANS keys.
 * @property int $base_salary_cents The yearly base, kept for the record; Backoffice does not pay it.
 * @property int $rate_basis_points The share of gross-margin dollars, in hundredths of a percent.
 * @property int|null $bonus_floor_cents The Production Bonus's monthly floor of gross-margin dollars.
 * @property int|null $bonus_minimum_margin_percent The month's margin the Production Bonus needs.
 * @property Carbon $effective_from
 * @property string|null $reason
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
class PayPlan extends Model
{
    public const string FAMILY_SALES = 'sales';

    public const string FAMILY_PRODUCTION = 'production';

    /**
     * The plans, with how each is said and what it starts with (5.7, 6.1;
     * decision 133). Only straight commission has the advance.
     *
     * @var array<string, array{family: string, label: string, base: int, rate: int, advance: bool, floor: int|null, minimum: int|null}>
     */
    public const array PLANS = [
        'sales_base_45000' => ['family' => self::FAMILY_SALES, 'label' => '$45,000 base + 2% of gross-margin dollars', 'base' => 4_500_000, 'rate' => 200, 'advance' => false, 'floor' => null, 'minimum' => null],
        'sales_base_35000' => ['family' => self::FAMILY_SALES, 'label' => '$35,000 base + 6% of gross-margin dollars', 'base' => 3_500_000, 'rate' => 600, 'advance' => false, 'floor' => null, 'minimum' => null],
        'sales_straight' => ['family' => self::FAMILY_SALES, 'label' => 'Straight commission: 15% of gross-margin dollars', 'base' => 0, 'rate' => 1500, 'advance' => true, 'floor' => null, 'minimum' => null],
        'sales_none' => ['family' => self::FAMILY_SALES, 'label' => 'No sales commission', 'base' => 0, 'rate' => 0, 'advance' => false, 'floor' => null, 'minimum' => null],
        'production_bonus' => ['family' => self::FAMILY_PRODUCTION, 'label' => '$45,000 base + the Production Bonus', 'base' => 4_500_000, 'rate' => 1000, 'advance' => false, 'floor' => 3_750_000, 'minimum' => 55],
        'production_none' => ['family' => self::FAMILY_PRODUCTION, 'label' => 'No Production Bonus', 'base' => 0, 'rate' => 0, 'advance' => false, 'floor' => null, 'minimum' => null],
    ];

    /** The advance is this share of the commission projected at this gross margin (5.7: 3.75% of revenue on straight commission). */
    public const int ADVANCE_PERCENT = 50;

    public const int PROJECTED_MARGIN_PERCENT = 50;

    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A pay plan is kept as it was. Set a new one from a day.');
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
            'base_salary_cents' => 'integer',
            'rate_basis_points' => 'integer',
            'bonus_floor_cents' => 'integer',
            'bonus_minimum_margin_percent' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plan of a family a person is on that day, if any.
     */
    public static function onDay(int $userId, string $family, string $day): ?self
    {
        return self::query()
            ->where('user_id', $userId)
            ->where('family', $family)
            ->whereDate('effective_from', '<=', $day)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    public function label(): string
    {
        return self::PLANS[$this->plan]['label'] ?? $this->plan;
    }

    public function hasAdvance(): bool
    {
        return (self::PLANS[$this->plan]['advance'] ?? false) && $this->rate_basis_points > 0;
    }

    public function paysAnything(): bool
    {
        return $this->rate_basis_points > 0;
    }

    /**
     * A share of an amount at this plan's rate, to the nearest cent.
     */
    public function shareOf(int $cents): int
    {
        return (int) round($cents * $this->rate_basis_points / 10000);
    }

    /**
     * The rate as it is said: "15%", "2%", "10%".
     */
    public function rateWords(): string
    {
        return rtrim(rtrim(number_format($this->rate_basis_points / 100, 2), '0'), '.').'%';
    }

    public function baseWords(): string
    {
        return Money::format($this->base_salary_cents);
    }
}
