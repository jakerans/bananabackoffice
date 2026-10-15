<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A note added to a scorecard, the way a scorecard is corrected
 * (docs/slice-14-work-and-closeout-plan.md, step 14c). Kept as it was.
 *
 * @property int $id
 * @property int $subcontractor_scorecard_id
 * @property string $note
 * @property int|null $written_by
 * @property string|null $written_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SubcontractorScorecardNote extends Model
{
    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A scorecard note is kept as it was.');
        };

        static::updating($guard);
        static::deleting($guard);
    }
}
