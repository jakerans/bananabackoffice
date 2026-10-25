<?php

namespace App\Models;

use App\Support\Attachments\HasPhotos;
use App\Support\Attachments\KeepsPhotos;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * A warranty case on a job (docs/slice-22-warranty-plan.md; decision 177;
 * product-architecture.md, 16): what the customer reported, its owner, and
 * where it stands. It never reopens the job. Never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $description What the customer reported.
 * @property string $reported_by Who reported it, and how: "The customer, by phone".
 * @property list<int> $original_subcontractor_ids The subcontractors who did the original work (question 7).
 * @property string $state One of the STATES keys.
 * @property int|null $owner_id
 * @property string|null $owner_name
 * @property Carbon|null $owner_assigned_at
 * @property string|null $outcome What was done, or why it was closed without work.
 * @property Carbon|null $ended_at
 * @property int|null $ended_by
 * @property string|null $ended_by_name
 * @property int|null $opened_by
 * @property string|null $opened_by_name
 * @property int|null $budget_cents The warranty budget a manager approved; zero is allowed (step 22b).
 * @property string|null $budget_reason
 * @property int|null $budget_approved_by
 * @property string|null $budget_approved_by_name
 * @property Carbon|null $budget_approved_at
 * @property Carbon|null $visit_on The day of the visit.
 * @property string|null $done_by_kind One of the DONE_BY keys.
 * @property string|null $done_by_name Who does it, when it is not a subcontractor.
 * @property int|null $subcontractor_id The subcontractor who does it, when it is one.
 * @property string|null $scheduled_by_name
 * @property Carbon|null $scheduled_at
 * @property-read Subcontractor|null $subcontractor
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class WarrantyCase extends Model implements HasPhotos
{
    use KeepsPhotos;

    public const string OPEN = 'open';

    public const string SCHEDULED = 'scheduled';

    public const string COMPLETED = 'completed';

    public const string CLOSED = 'closed';

    /** Where a case can stand, and how each is said, in English. */
    public const array STATES = [
        self::OPEN => 'Open',
        self::SCHEDULED => 'Scheduled',
        self::COMPLETED => 'Completed',
        self::CLOSED => 'Closed without work',
    ];

    /** Who can do the work (16), and how each is said, in English. */
    public const array DONE_BY = [
        'original_subcontractor' => 'The original subcontractor',
        'other_subcontractor' => 'Another subcontractor',
        'w2_painter' => 'A W-2 painter',
        'warranty_tech' => 'A warranty technician',
        'production_manager' => 'A Production Manager',
        'other' => 'Someone else',
    ];

    /** The ways of doing it that are a subcontractor. */
    public const array BY_SUBCONTRACTOR = ['original_subcontractor', 'other_subcontractor'];

    public const int DONE_BY_LENGTH = 160;

    public const int DESCRIPTION_LENGTH = 2000;

    public const int REPORTED_BY_LENGTH = 160;

    public const int OUTCOME_LENGTH = 2000;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A warranty case is kept.');
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
            'original_subcontractor_ids' => 'array',
            'owner_assigned_at' => 'datetime',
            'ended_at' => 'datetime',
            'budget_cents' => 'integer',
            'budget_approved_at' => 'datetime',
            'visit_on' => 'date',
            'scheduled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Subcontractor, $this>
     */
    public function subcontractor(): BelongsTo
    {
        return $this->belongsTo(Subcontractor::class);
    }

    public function hasBudget(): bool
    {
        return $this->budget_cents !== null;
    }

    /**
     * Who does the work, as it is said: "Sandia Brush Co. (the original
     * subcontractor)", "Riley (a W-2 painter)".
     */
    public function doneByWords(): ?string
    {
        if ($this->done_by_kind === null) {
            return null;
        }

        $who = $this->subcontractor->business_name ?? $this->done_by_name;
        $kind = lcfirst(self::DONE_BY[$this->done_by_kind] ?? $this->done_by_kind);

        return $who === null ? ucfirst($kind) : $who.' ('.$kind.')';
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    /**
     * Whether it is still being worked: open or scheduled.
     */
    public function isActive(): bool
    {
        return in_array($this->state, [self::OPEN, self::SCHEDULED], true);
    }

    /**
     * A short title for lists: the first words of what was reported.
     */
    public function summary(): string
    {
        return Str::limit($this->description, 80);
    }

    public function photoKinds(): array
    {
        return ['reported' => 'As reported', 'done' => 'After the work'];
    }

    public function photoProject(): Project
    {
        return $this->project;
    }

    public function attachmentDescription(string $kind): string
    {
        return 'Warranty case "'.Str::limit($this->description, 60).'", '.mb_strtolower($this->photoKinds()[$kind] ?? $kind);
    }
}
