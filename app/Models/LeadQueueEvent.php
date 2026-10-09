<?php

namespace App\Models;

use App\Enums\QueueEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One entry in the history of the call queue.
 *
 * It records how things stood when it was written and is never changed or
 * deleted afterwards. These are operational history, not audit events.
 *
 * The step is the cadence position at that moment: the attempt that was due
 * for an event about a Lead waiting in the queue, the attempt that was used
 * up for a counted one, and the attempts made for a Lead that has left.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $lead_cadence_id
 * @property QueueEventType $type
 * @property string|null $reason
 * @property string|null $note
 * @property int $cycle
 * @property int $step
 * @property string|null $hold_token
 * @property int|null $contact_attempt_id
 * @property int|null $location_id
 * @property string|null $location_name
 * @property int|null $actor_id Empty for the background timer.
 * @property string|null $actor_name
 * @property int|null $impersonator_id
 * @property string|null $impersonator_name
 * @property int|null $affected_user_id Whose hold expired, was ended or was given up by a skip.
 * @property Carbon|null $cooldown_until Only on a skip: when that person's cooldown for this Lead ends.
 * @property Carbon $created_at
 * @property-read Lead $lead
 * @property-read LeadCadence $cadence
 * @property-read ContactAttempt|null $contactAttempt
 * @property-read Location|null $location
 * @property-read User|null $actor
 * @property-read User|null $impersonator
 * @property-read User|null $affectedUser
 */
class LeadQueueEvent extends Model
{
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
            throw new LogicException('A queue history entry cannot be changed once it has been written.');
        });

        static::deleting(function (): never {
            throw new LogicException('A queue history entry cannot be deleted.');
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
            'type' => QueueEventType::class,
            'cycle' => 'integer',
            'step' => 'integer',
            'cooldown_until' => 'datetime',
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
     * @return BelongsTo<LeadCadence, $this>
     */
    public function cadence(): BelongsTo
    {
        return $this->belongsTo(LeadCadence::class, 'lead_cadence_id');
    }

    /**
     * @return BelongsTo<ContactAttempt, $this>
     */
    public function contactAttempt(): BelongsTo
    {
        return $this->belongsTo(ContactAttempt::class);
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
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }
}
