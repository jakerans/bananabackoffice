<?php

namespace App\Models;

use App\Support\Attachments\Attachable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a compliance item was received from a subcontractor, or that they
 * use Banana's coverage instead (docs/slice-12-subcontractors-plan.md, step
 * 12b). The latest for an item is the one in force. Renewing adds a record;
 * none is changed or deleted. The document itself can be attached to it
 * (step 16b), except the W-9, which carries a tax ID number (decision 157,
 * question 4), and Banana's coverage, which has none.
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
 * @property-read Collection<int, Attachment> $documents
 */
class SubcontractorComplianceRecord extends Model implements Attachable
{
    /** What its attached files are called. */
    public const string DOCUMENT = 'document';

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

    /**
     * The documents attached to it and not removed, the earliest first.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function documents(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->where('kind', self::DOCUMENT)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * Whether a document may be attached: not to the W-9, whose file is not
     * kept (decision 157, question 4), nor to Banana's coverage.
     */
    public function takesDocuments(): bool
    {
        return ! $this->uses_banana_coverage && $this->requirement->base_key !== 'w9';
    }

    public function attachmentAuditSubject(): Model
    {
        return $this->subcontractor;
    }

    public function attachmentAuditLocation(): ?Location
    {
        return $this->subcontractor->locations->first();
    }

    public function attachmentDescription(string $kind): string
    {
        return $this->requirement->name;
    }
}
