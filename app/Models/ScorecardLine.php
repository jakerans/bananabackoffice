<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One KPI of a submitted scorecard (docs/slice-27-leadership-scorecards-plan.md,
 * step 27a; decision 187, question 5): the actual and target as they were,
 * and the Green, Yellow or Red its owner chose, with a note when it is not
 * Green. Backoffice says whether it was on target; it never chooses.
 *
 * @property int $id
 * @property int $scorecard_id
 * @property string $kpi One of ScorecardNumbers' KPI keys.
 * @property int $position
 * @property int|null $actual In cents, or hundredths of a percent for a percentage.
 * @property int|null $target
 * @property int|null $target_due The share of a monthly target due by the week's Sunday.
 * @property bool|null $on_target Nothing when there was no actual or no target.
 * @property string $status One of the STATUSES keys.
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Scorecard $scorecard
 */
class ScorecardLine extends Model
{
    public const string GREEN = 'green';

    public const string YELLOW = 'yellow';

    public const string RED = 'red';

    /** @var array<string, string> */
    public const array STATUSES = [
        self::GREEN => 'Green',
        self::YELLOW => 'Yellow',
        self::RED => 'Red',
    ];

    /** A note for a status that is not Green, or a correction's reason. */
    public const int NOTE_LENGTH = 500;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actual' => 'integer',
            'target' => 'integer',
            'target_due' => 'integer',
            'on_target' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Scorecard, $this>
     */
    public function scorecard(): BelongsTo
    {
        return $this->belongsTo(Scorecard::class);
    }
}
