<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A Work Package given to one subcontractor (docs/slice-13-giving-work-plan.md,
 * step 13b; product-architecture.md, 6.6, 7.4 and 7.8; decision 151).
 *
 * The one with no end is the award now. An award is changed only once, to
 * end it, and never deleted. The payout it was accepted at is
 * finance-sensitive: shown only with `payouts.view`.
 *
 * @property int $id
 * @property int $work_package_id
 * @property int $subcontractor_id
 * @property int $payout_cents The payout they accepted.
 * @property Carbon $first_day The days they took.
 * @property Carbon $last_day
 * @property int|null $work_package_window_id The offered window they picked, if it was one.
 * @property string $accepted_by Who accepted for the subcontractor.
 * @property ColorApprovalMethod $acceptance_method
 * @property Carbon $accepted_on
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $ended_at
 * @property string|null $ended_cause One of the ENDED_ constants.
 * @property string|null $ended_reason
 * @property int|null $ended_by
 * @property string|null $ended_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WorkPackage $package
 * @property-read Subcontractor $subcontractor
 */
class WorkPackageAward extends Model
{
    /** The subcontractor withdrew. It will count against them (decision 151, question 6). */
    public const string ENDED_SUBCONTRACTOR_WITHDREW = 'subcontractor_withdrew';

    /** The customer or Banana asked for it. It does not count against them (7.8). */
    public const string ENDED_CUSTOMER_OR_BANANA = 'customer_or_banana';

    /** The payout changed and their acceptance was recorded again, as a new award (question 2). */
    public const string ENDED_PAYOUT_CHANGED = 'payout_changed';

    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        // Only ending it may change it, and only once.
        static::updating(function (self $award): void {
            $allowed = ['ended_at', 'ended_cause', 'ended_reason', 'ended_by', 'ended_by_name', 'updated_at'];

            if ($award->getOriginal('ended_at') !== null || array_diff(array_keys($award->getDirty()), $allowed) !== []) {
                throw new LogicException('An award is kept as it was. It can only be ended, once.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('An award cannot be deleted.');
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
            'payout_cents' => 'integer',
            'first_day' => 'date',
            'last_day' => 'date',
            'accepted_on' => 'date',
            'acceptance_method' => ColorApprovalMethod::class,
            'ended_at' => 'datetime',
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
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function isCurrent(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Whose doing the end was, as it is said, in English; the page translates it.
     */
    public function endedCauseLabel(): ?string
    {
        return match ($this->ended_cause) {
            self::ENDED_SUBCONTRACTOR_WITHDREW => 'the subcontractor withdrew',
            self::ENDED_CUSTOMER_OR_BANANA => 'the customer or Banana asked',
            self::ENDED_PAYOUT_CHANGED => 'the payout changed and they accepted again',
            default => null,
        };
    }
}
