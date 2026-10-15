<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time work running over was given a later last day, with whose cause
 * it was (docs/slice-14-work-and-closeout-plan.md, step 14a;
 * product-architecture.md, 7.9: "Distinguish contractor-caused overrun from
 * weather/customer/legitimate causes"; decision 155, question 3). Kept as it
 * was, for good.
 *
 * @property int $id
 * @property int $work_package_id
 * @property Carbon $previous_last_day
 * @property Carbon $new_last_day
 * @property string $cause One of the CAUSE_ constants.
 * @property string|null $reason Needed when the cause is "other".
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackage $package
 */
class WorkPackageExtension extends Model
{
    /** The subcontractor's doing: the only cause that will count against them. */
    public const string CAUSE_SUBCONTRACTOR = 'subcontractor';

    public const string CAUSE_WEATHER = 'weather';

    public const string CAUSE_CUSTOMER = 'customer';

    public const string CAUSE_OTHER = 'other';

    public const array CAUSES = [self::CAUSE_SUBCONTRACTOR, self::CAUSE_WEATHER, self::CAUSE_CUSTOMER, self::CAUSE_OTHER];

    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('An extension is kept as it was.');
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
            'previous_last_day' => 'date',
            'new_last_day' => 'date',
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
     * The cause as it is said, in English; the page translates it.
     */
    public static function causeLabel(string $cause): string
    {
        return match ($cause) {
            self::CAUSE_SUBCONTRACTOR => 'The subcontractor',
            self::CAUSE_WEATHER => 'The weather',
            self::CAUSE_CUSTOMER => 'The customer',
            default => 'Another reason',
        };
    }
}
