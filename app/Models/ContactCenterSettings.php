<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One saved version of the Contact Center settings: the call cadence, calling
 * hours and days, and hold length. The current settings are the highest version.
 *
 * A version is never changed or deleted. Saving writes the next one.
 *
 * @property int $id
 * @property int $version
 * @property list<array{type: string, waits_minutes?: list<int>, attempts?: int}> $cadence
 * @property int $calling_opens_at Minutes after midnight, in each Location's local time.
 * @property int $calling_closes_at Minutes after midnight, in each Location's local time.
 * @property list<int> $calling_days ISO weekday numbers, 1 (Monday) to 7 (Sunday).
 * @property int $hold_minutes
 * @property int|null $changed_by
 * @property string|null $changed_by_name
 * @property Carbon|null $created_at
 */
class ContactCenterSettings extends Model
{
    /** The limits every save is checked against, on the server. */
    public const int EARLIEST_OPENING = 480;

    public const int LATEST_CLOSING = 1260;

    public const int HOURS_STEP = 15;

    public const int MINIMUM_WINDOW = 60;

    public const int MAXIMUM_CADENCE_DAYS = 14;

    public const int MAXIMUM_ATTEMPTS_PER_DAY = 6;

    public const int MINIMUM_WAIT = 5;

    public const int MAXIMUM_WAIT = 720;

    public const int MINIMUM_HOLD = 1;

    public const int MAXIMUM_HOLD = 30;

    protected $table = 'contact_center_settings';

    /**
     * A settings version is never changed, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    /**
     * Refuse to change or remove a version once it has been saved.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A saved version of the Contact Center settings cannot be changed.');
        });

        static::deleting(function (): never {
            throw new LogicException('A saved version of the Contact Center settings cannot be deleted.');
        });
    }

    /**
     * The settings in force now: the highest saved version.
     */
    public static function current(): self
    {
        return self::query()->orderByDesc('version')->firstOrFail();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'cadence' => 'array',
            'calling_opens_at' => 'integer',
            'calling_closes_at' => 'integer',
            'calling_days' => 'array',
            'hold_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * How many attempts the whole cadence holds.
     *
     * @param  list<array{type: string, waits_minutes?: list<int>, attempts?: int}>|null  $cadence
     */
    public function totalAttempts(?array $cadence = null): int
    {
        return self::countAttempts($cadence ?? $this->cadence);
    }

    /**
     * @param  list<array{type: string, waits_minutes?: list<int>, attempts?: int}>  $cadence
     */
    public static function countAttempts(array $cadence): int
    {
        return array_sum(array_map(
            fn (array $day): int => $day['type'] === 'timed' ? count($day['waits_minutes'] ?? []) : (int) ($day['attempts'] ?? 0),
            $cadence,
        ));
    }
}
