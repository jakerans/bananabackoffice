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
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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
