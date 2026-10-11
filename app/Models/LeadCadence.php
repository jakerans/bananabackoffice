<?php

namespace App\Models;

use App\Enums\CadenceState;
use App\Enums\CallQueue;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A Lead's place in the phone cadence: the one attempt that is due, and who,
 * if anyone, has been given the Lead to call.
 *
 * It is changed only by the actions in App\Actions\Queue. A stored due time is
 * an instant and is never recalculated. A hold is not assignment: nothing here
 * reads or changes who the Lead is assigned to.
 *
 * @property int $id
 * @property int $lead_id
 * @property CadenceState $state
 * @property CallQueue $queue Which "Next" button hands the Lead out (decision 117).
 * @property int $cycle 1 at first; a restart adds 1.
 * @property int $attempts_made Counted attempts in this cycle.
 * @property Carbon|null $due_at The one attempt due. Empty unless in the queue.
 * @property Carbon|null $last_counted_attempt_at
 * @property Carbon $entered_at When this cycle began.
 * @property Carbon|null $ended_at
 * @property int|null $held_by_user_id
 * @property string|null $hold_token Identifies one hold, so it can be told from a later one.
 * @property Carbon|null $held_at
 * @property Carbon|null $held_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read User|null $holder
 */
class LeadCadence extends Model
{
    /**
     * A cadence is ended, never removed: the queue history points at it.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A lead cadence cannot be deleted.');
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
            'state' => CadenceState::class,
            'queue' => CallQueue::class,
            'cycle' => 'integer',
            'attempts_made' => 'integer',
            'due_at' => 'datetime',
            'last_counted_attempt_at' => 'datetime',
            'entered_at' => 'datetime',
            'ended_at' => 'datetime',
            'held_at' => 'datetime',
            'held_until' => 'datetime',
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
     * The person the Lead has been given to, while it is held.
     *
     * @return BelongsTo<User, $this>
     */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by_user_id');
    }

    /**
     * @return HasMany<LeadQueueEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(LeadQueueEvent::class)->orderBy('id');
    }

    public function isInQueue(): bool
    {
        return $this->state === CadenceState::InQueue;
    }

    /**
     * Whether a hold is recorded on the row, whether or not its time has run out.
     */
    public function hasHoldRecorded(): bool
    {
        return $this->held_by_user_id !== null;
    }

    /**
     * Whether someone holds the Lead at this moment. A hold ends by its own
     * stored time, with no timer needed.
     */
    public function isHeldAt(CarbonInterface $moment): bool
    {
        return $this->held_by_user_id !== null && $this->held_until !== null && $this->held_until->gt($moment);
    }

    /**
     * The number of the attempt that is due next in this cycle.
     */
    public function nextStep(): int
    {
        return $this->attempts_made + 1;
    }
}
