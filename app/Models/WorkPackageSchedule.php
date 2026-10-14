<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One set of dates a Work Package has had: confirmed by the customer, or
 * changed later with a reason (docs/slice-11-schedule-plan.md, step 11b;
 * product-architecture.md, 6.5: "rescheduled with reason/history"). The
 * latest is the package's schedule now. Kept as it was, for good.
 *
 * @property int $id
 * @property int $work_package_id
 * @property Carbon $first_day
 * @property Carbon $last_day
 * @property int|null $work_package_window_id The offered window it came from, if it did.
 * @property string $confirmed_by_customer Who said yes.
 * @property ColorApprovalMethod $confirmation_method How they said it.
 * @property Carbon $confirmed_on
 * @property string|null $reason Why the dates were changed. Empty for the first confirmation.
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackage $package
 */
class WorkPackageSchedule extends Model
{
    /** The longest the name of who confirmed can be. */
    public const int NAME_LENGTH = 120;

    /** The longest a reason for changing the dates can be. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('Dates a job had are kept as they were. Rescheduling adds new ones.');
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
            'first_day' => 'date',
            'last_day' => 'date',
            'confirmed_on' => 'date',
            'confirmation_method' => ColorApprovalMethod::class,
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
     * The dates as they are said.
     */
    public function words(): string
    {
        return WorkPackageWindow::describe($this->first_day, $this->last_day);
    }
}
