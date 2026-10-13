<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One rough timeframe a Project has had (docs/slice-10-pre-production-plan.md,
 * step 10a): the earliest and the latest day the work was expected to start,
 * who set it, when and, for a change, why.
 *
 * A row is written once and kept, so a changed timeframe keeps what it was.
 * The one in force is also on the Project itself. Written only by
 * App\Actions\Projects\SetProjectTimeframe.
 *
 * @property int $id
 * @property int $project_id
 * @property Carbon $earliest
 * @property Carbon $latest
 * @property string|null $note
 * @property string|null $reason Why it replaced the one before. Empty on the first.
 * @property int|null $set_by
 * @property string|null $set_by_name
 * @property Carbon $created_at
 */
class ProjectTimeframe extends Model
{
    /**
     * A timeframe is written once, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A timeframe is history and cannot be edited.');
        });

        static::deleting(function (): never {
            throw new LogicException('A timeframe cannot be deleted.');
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
            'earliest' => 'date',
            'latest' => 'date',
            'created_at' => 'datetime',
        ];
    }
}
