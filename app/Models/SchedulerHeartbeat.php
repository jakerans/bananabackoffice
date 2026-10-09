<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * When a background timer last ran. One row per timer.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $last_ran_at
 */
class SchedulerHeartbeat extends Model
{
    /** The every-minute queue tick. */
    public const string QUEUE_TICK = 'queue_tick';

    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_ran_at' => 'datetime',
        ];
    }

    /**
     * When the named timer last ran, or null when it never has.
     */
    public static function lastRanAt(string $name): ?CarbonInterface
    {
        return self::query()->where('name', $name)->first()?->last_ran_at;
    }
}
