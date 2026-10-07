<?php

namespace App\Models;

use App\Enums\DisqualificationCategory;
use App\Enums\LeadStatus;
use App\Enums\LeadStatusEvent;
use Database\Factories\LeadStatusChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the history of a Lead's lifecycle.
 *
 * It records how things stood when it was written and is never changed or
 * deleted afterwards. A later correction is a new entry, so the original stays
 * and the change can be explained.
 *
 * @property int $id
 * @property int $lead_id
 * @property LeadStatusEvent $event
 * @property LeadStatus|null $from_status
 * @property LeadStatus $to_status
 * @property int|null $changed_by
 * @property int|null $location_id
 * @property int|null $assigned_user_id
 * @property int|null $disqualification_reason_id
 * @property DisqualificationCategory|null $disqualification_category
 * @property int|null $duplicate_of_lead_id
 * @property Carbon|null $follow_up_on
 * @property string|null $note
 * @property Carbon $created_at
 * @property-read Lead $lead
 * @property-read User|null $changedBy
 * @property-read User|null $assignee
 * @property-read Location|null $location
 * @property-read LeadDisqualificationReason|null $disqualificationReason
 * @property-read Lead|null $duplicateOf
 */
class LeadStatusChange extends Model
{
    /** @use HasFactory<LeadStatusChangeFactory> */
    use HasFactory;

    /**
     * An entry is never changed, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    /**
     * Refuse to change or remove an entry once it has been written.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A lead status history entry cannot be changed once it has been written.');
        });

        static::deleting(function (): never {
            throw new LogicException('A lead status history entry cannot be deleted.');
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
            'event' => LeadStatusEvent::class,
            'from_status' => LeadStatus::class,
            'to_status' => LeadStatus::class,
            'disqualification_category' => DisqualificationCategory::class,
            'follow_up_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The staff identity the change was made as. When an Owner made it while
     * viewing Backoffice as that person, the audit event for the same change
     * names the Owner.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Who was responsible for the Lead when this happened.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * The location the Lead was at when this happened.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<LeadDisqualificationReason, $this>
     */
    public function disqualificationReason(): BelongsTo
    {
        return $this->belongsTo(LeadDisqualificationReason::class);
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'duplicate_of_lead_id');
    }
}
