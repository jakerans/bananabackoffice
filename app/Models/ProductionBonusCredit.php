<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A job counted toward its Production Manager's Production Bonus
 * (docs/slice-20-compensation-plan.md, step 20b; decision 172, question 8):
 * its revenue and final gross margin, in the month it was financially
 * closed, in its Location's time. A job closed again counts the difference,
 * in the month it closes again, so a cost found after the close is an
 * adjustment in the month it is found (6.1). Kept as it was.
 *
 * @property int $id
 * @property int $user_id The Production Manager on the job when it closed.
 * @property string $user_name
 * @property int $project_id
 * @property int $project_job_costing_step_id The close it came from.
 * @property string $month "2026-11".
 * @property int $revenue_cents
 * @property int $margin_cents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProductionBonusCredit extends Model
{
    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A Production Bonus credit is kept as it was.');
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
            'revenue_cents' => 'integer',
            'margin_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
