<?php

namespace App\Models;

use App\Enums\AssignmentEndReason;
use Database\Factories\LeadAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One period during which one person was responsible for working a Lead.
 *
 * It is open while they are the assignee and closed, with the reason, when they
 * stop. Being assigned grants nothing: the person must already have access to
 * the Lead.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $user_id
 * @property int|null $assigned_by
 * @property int|null $location_id
 * @property Carbon $assigned_at
 * @property Carbon|null $ended_at
 * @property AssignmentEndReason|null $end_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read User $user
 * @property-read User|null $assignedBy
 * @property-read Location|null $location
 */
class LeadAssignment extends Model
{
    /** @use HasFactory<LeadAssignmentFactory> */
    use HasFactory;

    /**
     * Refuse to delete an assignment: the history must stay complete.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A lead assignment is ended, never deleted.');
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
            'assigned_at' => 'datetime',
            'ended_at' => 'datetime',
            'end_reason' => AssignmentEndReason::class,
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
     * The person who was responsible for the Lead.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The person who made the assignment. Empty when it was not made by a signed-in person.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    /**
     * The location the Lead was at when the assignment was made. Empty for an unrouted Lead.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }
}
