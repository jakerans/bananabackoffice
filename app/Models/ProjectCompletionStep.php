<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One step of a job's completion (docs/slice-14-work-and-closeout-plan.md,
 * step 14b; product-architecture.md, 6.8): the internal inspection, the
 * customer walkthrough, or the customer's signoff, or their saying they are
 * not fully satisfied. Kept as it was; the latest of each kind counts.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind One of the KIND_ constants.
 * @property Carbon $day
 * @property string $person Who did it: the inspector, who conducted the walkthrough, or the customer who signed.
 * @property int|null $subcontractor_id The subcontractor who conducted the walkthrough, when one did.
 * @property ColorApprovalMethod|null $method How the customer signed off.
 * @property string|null $note
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subcontractor|null $subcontractor
 */
class ProjectCompletionStep extends Model
{
    public const string KIND_INSPECTION = 'inspection';

    public const string KIND_WALKTHROUGH = 'walkthrough';

    public const string KIND_SIGNOFF = 'signoff';

    public const string KIND_NOT_SATISFIED = 'not_satisfied';

    /** The longest note kept. */
    public const int NOTE_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A completion step is kept as it was.');
        };

        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'date',
            'method' => ColorApprovalMethod::class,
        ];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }
}
