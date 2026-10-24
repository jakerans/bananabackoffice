<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A change of a Work Package's scope or payout once it was given to a
 * subcontractor (docs/slice-18-change-orders-plan.md, step 18b; decision
 * 166, question 7): what changed, why, and the customer change order it
 * comes from, if any. It waits for the subcontractor's acceptance, recorded
 * as a new award; the work order is then sent again. Kept as it was.
 *
 * @property int $id
 * @property int $work_package_id
 * @property string $kind One of the KIND_ constants.
 * @property string|null $scope_before
 * @property string|null $scope_after
 * @property int|null $payout_before_cents Finance-sensitive.
 * @property int|null $payout_after_cents
 * @property string $reason
 * @property int|null $project_change_order_id
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ProjectChangeOrder|null $changeOrder
 */
class WorkPackageRevision extends Model
{
    public const string KIND_SCOPE = 'scope';

    public const string KIND_PAYOUT = 'payout';

    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A revision is kept as it was.');
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
            'payout_before_cents' => 'integer',
            'payout_after_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProjectChangeOrder, $this>
     */
    public function changeOrder(): BelongsTo
    {
        return $this->belongsTo(ProjectChangeOrder::class, 'project_change_order_id');
    }

    /**
     * What changed, in English, with the amounts only when they may be shown.
     * Translated where it is shown.
     */
    public function words(bool $withPayout): string
    {
        if ($this->kind === self::KIND_SCOPE) {
            return 'Scope revised';
        }

        return $withPayout && $this->payout_before_cents !== null && $this->payout_after_cents !== null
            ? 'Payout revised from '.Money::format($this->payout_before_cents).' to '.Money::format($this->payout_after_cents)
            : 'Payout revised';
    }
}
