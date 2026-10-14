<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a compliance item was received from a subcontractor, or that they
 * use Banana's coverage instead (docs/slice-12-subcontractors-plan.md, step
 * 12b). The latest for an item is the one in force. Renewing adds a record;
 * none is changed or deleted. It records that the item was received, not
 * the document.
 *
 * @property int $id
 * @property int $subcontractor_id
 * @property int $compliance_requirement_id
 * @property Carbon|null $received_on Empty when they use Banana's coverage.
 * @property Carbon|null $expires_on
 * @property bool $uses_banana_coverage
 * @property string|null $note
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Subcontractor $subcontractor
 * @property-read ComplianceRequirement $requirement
 */
class SubcontractorComplianceRecord extends Model
{
    /** The longest note kept. */
    public const int NOTE_LENGTH = 500;

    protected static function booted(): void
    {
        $guard = function (): never {
            throw new LogicException('A compliance record is kept as it was. Renewing adds a new one.');
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
            'received_on' => 'date',
            'expires_on' => 'date',
            'uses_banana_coverage' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    /**
     * @return BelongsTo<ComplianceRequirement, $this>
     */
    public function requirement(): BelongsTo
    {
        return $this->belongsTo(ComplianceRequirement::class, 'compliance_requirement_id');
    }
}
