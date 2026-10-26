<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A Location's submitted weekly scorecard (docs/slice-27-leadership-scorecards-plan.md,
 * step 27a; decision 187; product-architecture.md, 17.3): its numbers as
 * they were when its owner submitted it, with the status they chose for
 * each. Only a line's status and note may be corrected after, with a reason.
 *
 * @property int $id
 * @property int $location_id
 * @property Carbon $week_starts_on The Monday of its week, in its Location's calendar.
 * @property string $month "2026-10": the month its Sunday is in, whose numbers so far it shows.
 * @property int|null $owner_id
 * @property string $owner_name
 * @property Carbon $submitted_at
 * @property int|null $submitted_by
 * @property string|null $submitted_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location $location
 * @property-read Collection<int, ScorecardLine> $lines
 */
class Scorecard extends Model
{
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A submitted scorecard is kept as it was. Correct a line instead.');
        });
        static::deleting(function (): never {
            throw new LogicException('A submitted scorecard is kept as it was.');
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
            'week_starts_on' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<ScorecardLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ScorecardLine::class)->orderBy('position');
    }
}
