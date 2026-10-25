<?php

namespace App\Models;

use App\Support\Projects\JobCosting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One step of a job's costing (docs/slice-19-job-costing-plan.md, steps 19b
 * and 19c; decision 170): submitted by the Production Manager, approved by
 * Finance, closed, or reopened with why. Keeps the figures as they were
 * then. The latest says where the job stands. Kept as it was.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind One of the KIND_ constants.
 * @property int $revenue_cents The contract then, before tax.
 * @property int $payouts_cents
 * @property int $costs_cents
 * @property int $margin_cents
 * @property string|null $reason
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectJobCostingStep extends Model
{
    public const string KIND_SUBMITTED = 'submitted';

    public const string KIND_APPROVED = 'approved';

    public const string KIND_CLOSED = 'closed';

    public const string KIND_REOPENED = 'reopened';

    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A job costing step is kept as it was.');
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
            'payouts_cents' => 'integer',
            'costs_cents' => 'integer',
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

    public function marginPercent(): ?float
    {
        return $this->revenue_cents > 0 ? round($this->margin_cents * 100 / $this->revenue_cents, 1) : null;
    }

    /**
     * As it is said: "$3,450 (40.8%)".
     */
    public function marginWords(): string
    {
        return JobCosting::words($this->margin_cents, $this->marginPercent());
    }
}
