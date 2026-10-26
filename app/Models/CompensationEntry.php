<?php

namespace App\Models;

use App\Support\Compensation\PayPeriods;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry of a person's variable-compensation ledger
 * (docs/slice-20-compensation-plan.md, step 20a; decision 172; decision 133;
 * product-architecture.md, 5.7 and 9.4): projected at the sale, the
 * advance, the final commission, a gross-margin adjustment after a job is
 * closed again, a clawback, a manual adjustment, or a Production Bonus.
 *
 * Each is due in a pay period, a calendar month, except a projection, which
 * is never paid. A change is a new entry, never an edit. Kept as it was.
 *
 * @property int $id
 * @property int $user_id
 * @property string $user_name
 * @property int|null $project_id
 * @property string $kind One of the KINDS keys.
 * @property int $amount_cents Below zero for a clawback or a deduction.
 * @property string|null $period The pay period it is due in, "2026-11"; empty for a projection.
 * @property int|null $pay_plan_id
 * @property int|null $project_job_costing_step_id The financial close it came from.
 * @property string $explanation How it was worked out, in plain English.
 * @property string|null $reason Why, for a manual adjustment.
 * @property int|null $recorded_by Who did what made it; empty when Backoffice made it on its own.
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Project|null $project
 * @property-read PayPlan|null $payPlan
 */
class CompensationEntry extends Model
{
    public const string KIND_PROJECTED = 'projected';

    public const string KIND_ADVANCE = 'advance';

    public const string KIND_FINAL = 'final';

    public const string KIND_MARGIN_ADJUSTMENT = 'margin_adjustment';

    public const string KIND_CLAWBACK = 'clawback';

    public const string KIND_ADJUSTMENT = 'adjustment';

    public const string KIND_BONUS = 'bonus';

    /** Commission taken back on a balance written off as bad debt (slice 24, step 24c). */
    public const string KIND_BAD_DEBT = 'bad_debt';

    /** How each kind is said, in English. */
    public const array KINDS = [
        self::KIND_PROJECTED => 'Projected commission',
        self::KIND_ADVANCE => 'Commission advance',
        self::KIND_FINAL => 'Final commission',
        self::KIND_MARGIN_ADJUSTMENT => 'Gross-margin adjustment',
        self::KIND_CLAWBACK => 'Clawback',
        self::KIND_ADJUSTMENT => 'Manual adjustment',
        self::KIND_BONUS => 'Production Bonus',
        self::KIND_BAD_DEBT => 'Commission on bad debt',
    ];

    /** The kinds a job's commission is worked out from; a manual adjustment stands on its own. */
    public const array COMMISSION_KINDS = [self::KIND_ADVANCE, self::KIND_FINAL, self::KIND_MARGIN_ADJUSTMENT, self::KIND_CLAWBACK];

    public const int EXPLANATION_LENGTH = 500;

    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A ledger entry is kept as it was. Record an adjustment instead.');
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<PayPlan, $this>
     */
    public function payPlan(): BelongsTo
    {
        return $this->belongsTo(PayPlan::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }

    public function isPayable(): bool
    {
        return $this->period !== null;
    }

    /**
     * The pay period as it is said: "November 2026".
     */
    public function periodWords(): ?string
    {
        return $this->period === null ? null : PayPeriods::words($this->period);
    }
}
