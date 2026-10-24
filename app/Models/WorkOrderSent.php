<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a Work Package's work order was sent to its subcontractor, and
 * what it said then (docs/slice-13-giving-work-plan.md, step 13c). Backoffice
 * sends nothing; this records that someone did. Kept as it was, for good.
 * The payout and deduction are finance-sensitive.
 *
 * @property int $id
 * @property int $work_package_id
 * @property int $work_package_award_id
 * @property ColorApprovalMethod $method How it was sent.
 * @property Carbon $sent_on
 * @property Carbon $first_day
 * @property Carbon $last_day
 * @property int $payout_cents
 * @property string|null $scope What the work was, as the work order said (slice 18, step 18b).
 * @property int $deduction_cents
 * @property int $deduction_basis_points
 * @property int|null $sent_by
 * @property string|null $sent_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackageAward $award
 */
class WorkOrderSent extends Model
{
    protected $table = 'work_orders_sent';

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A work order that was sent is kept as it was. Sending it again adds a new record.');
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
            'method' => ColorApprovalMethod::class,
            'sent_on' => 'date',
            'first_day' => 'date',
            'last_day' => 'date',
            'payout_cents' => 'integer',
            'deduction_cents' => 'integer',
            'deduction_basis_points' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<WorkPackageAward, $this>
     */
    public function award(): BelongsTo
    {
        return $this->belongsTo(WorkPackageAward::class, 'work_package_award_id');
    }
}
