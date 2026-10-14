<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A window of dates offered to the customer for a Work Package: its first and
 * its last working day (docs/slice-11-schedule-plan.md, step 11a;
 * product-architecture.md, 6.5; decision 144, question 3). An offer, not a
 * booking. Proposed and withdrawn only by App\Actions\Projects\ChangeWorkPackages.
 *
 * @property int $id
 * @property int $work_package_id
 * @property Carbon $first_day
 * @property Carbon $last_day
 * @property int|null $proposed_by
 * @property string|null $proposed_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackage $package
 */
class WorkPackageWindow extends Model
{
    /** The most calendar days one window can cover. */
    public const int MAX_LENGTH_DAYS = 180;

    /** How far ahead a window can start, in days. */
    public const int MAX_DAYS_AHEAD = 730;

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
     * How many working days it holds, Monday to Friday. Public holidays are
     * not known and count as working days.
     */
    public function workingDays(): int
    {
        return self::workingDaysBetween($this->first_day, $this->last_day);
    }

    /**
     * The window as it is said: "Mon, Nov 16 – Fri, Nov 20, 2026".
     */
    public function words(): string
    {
        return self::describe($this->first_day, $this->last_day);
    }

    /**
     * Two days as a window is said: "Mon, Nov 16 – Fri, Nov 20, 2026", or
     * "Tue, Jan 12, 2027" when it is one day.
     */
    public static function describe(CarbonInterface $first, CarbonInterface $last): string
    {
        if ($first->isSameDay($last)) {
            return $last->format('D, M j, Y');
        }

        return $first->format($first->year === $last->year ? 'D, M j' : 'D, M j, Y').' – '.$last->format('D, M j, Y');
    }

    /**
     * The working days from one day to another, both counted, Monday to Friday.
     */
    public static function workingDaysBetween(CarbonInterface $first, CarbonInterface $last): int
    {
        $count = 0;

        for ($day = $first->copy()->startOfDay(); $day->lessThanOrEqualTo($last); $day = $day->addDay()) {
            if (! $day->isWeekend()) {
                $count++;
            }
        }

        return $count;
    }
}
