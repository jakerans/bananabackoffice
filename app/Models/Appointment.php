<?php

namespace App\Models;

use App\Enums\AppointmentState;
use App\Enums\CancellationParty;
use App\Enums\LeadStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An appointment booked for a Lead with a salesperson. Saving one is the
 * moment the Lead is issued to Sales (rulebook, rule 3).
 *
 * It is changed only by the actions in App\Actions\Appointments. Times are
 * stored in UTC; `timezone` is the Location timezone they were entered in and
 * are shown in (decision 92).
 *
 * @property int $id
 * @property int $lead_id
 * @property int $location_id
 * @property int $account_id
 * @property int $property_id
 * @property int|null $salesperson_id
 * @property string $salesperson_name
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property int $duration_minutes
 * @property string $timezone
 * @property AppointmentState $state
 * @property int|null $booked_by
 * @property string|null $booked_by_name
 * @property LeadStatus $previous_status What the Lead was a moment before the booking.
 * @property int|null $previous_assignee_id
 * @property bool $previous_in_queue Whether the booking took the Lead out of the call queue.
 * @property Carbon|null $state_changed_at
 * @property int|null $state_changed_by
 * @property string|null $state_changed_by_name
 * @property string|null $state_note
 * @property CancellationParty|null $cancelled_by_party Who called it off, for a cancelled appointment.
 * @property int|null $cancellation_reason_id
 * @property string|null $cancellation_reason_name
 * @property int|null $follows_appointment_id The appointment this one follows, when it was booked while the Lead was still with Sales.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read Location $location
 * @property-read Account $account
 * @property-read Property $property
 * @property-read User|null $salesperson
 * @property-read LeadIssue|null $issue
 * @property-read Collection<int, AppointmentChange> $changes
 */
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    /** The length a booking starts at, in minutes (rulebook, rule 1). */
    public const int DEFAULT_MINUTES = 90;

    /** The limits every booking is checked against, on the server (decision 93). */
    public const int MINIMUM_MINUTES = 30;

    public const int MAXIMUM_MINUTES = 240;

    public const int STEP_MINUTES = 15;

    /**
     * An appointment is kept for good: it is what the Lead's issue points at.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('An appointment cannot be deleted.');
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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'duration_minutes' => 'integer',
            'state' => AppointmentState::class,
            'previous_status' => LeadStatus::class,
            'previous_in_queue' => 'boolean',
            'state_changed_at' => 'datetime',
            'cancelled_by_party' => CancellationParty::class,
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
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    /**
     * The issue this booking made: the Lead's handoff to Sales.
     *
     * @return HasOne<LeadIssue, $this>
     */
    public function issue(): HasOne
    {
        return $this->hasOne(LeadIssue::class);
    }

    /**
     * Every time it was rescheduled or moved to another salesperson, oldest
     * first (rulebook, rules 6 and 7).
     *
     * @return HasMany<AppointmentChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(AppointmentChange::class)->orderBy('id');
    }

    /**
     * Whether it was booked while the Lead was still with Sales: a rebooking
     * after a cancellation, or another appointment after one had passed. Such
     * a booking is not a new issue (decisions 99 and 113).
     */
    public function followsAnother(): bool
    {
        return $this->follows_appointment_id !== null;
    }

    public function isCancelled(): bool
    {
        return $this->state === AppointmentState::Cancelled;
    }

    /**
     * Whether the visit did not happen: cancelled, or a no-show.
     */
    public function fellThrough(): bool
    {
        return $this->state->fellThrough();
    }

    /**
     * Whether its time has come and nobody has yet said what happened: it
     * still stands and its start time has passed (decision 125).
     */
    public function awaitsCloseOutAt(CarbonInterface $moment): bool
    {
        return $this->stands() && $this->starts_at->lte($moment);
    }

    /**
     * Standing appointments whose start time has come, still waiting to be
     * marked run or no-show.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function awaitingCloseOutAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('state', AppointmentState::Booked)->where('starts_at', '<=', self::instant($moment));
    }

    /**
     * Whether the appointment still holds its time and its salesperson.
     */
    public function stands(): bool
    {
        return $this->state->stands();
    }

    /**
     * Whether the appointment stands and has not finished yet. A Lead with one
     * cannot be moved, returned to central or disqualified (decision 95).
     */
    public function isUpcomingAt(CarbonInterface $moment): bool
    {
        return $this->stands() && $this->ends_at->gt($moment);
    }

    /**
     * Appointments that stand.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->where('state', AppointmentState::Booked);
    }

    /**
     * Standing appointments that have not finished at the given moment.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function upcomingAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('state', AppointmentState::Booked)->where('ends_at', '>', self::instant($moment));
    }

    /**
     * Standing appointments of one salesperson that share any time with a
     * span. Two that only touch, one ending as the other starts, do not.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, int $salespersonId, CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        $query->where('state', AppointmentState::Booked)
            ->where('salesperson_id', $salespersonId)
            ->where('starts_at', '<', self::instant($endsAt))
            ->where('ends_at', '>', self::instant($startsAt));
    }

    /**
     * Times are stored in UTC, so a moment given in any other timezone is
     * turned into UTC before it is compared with them.
     */
    private static function instant(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->utc();
    }
}
