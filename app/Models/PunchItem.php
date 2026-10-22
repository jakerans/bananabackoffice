<?php

namespace App\Models;

use App\Support\Attachments\HasPhotos;
use App\Support\Attachments\KeepsPhotos;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/**
 * Something to touch up after the customer walkthrough
 * (docs/slice-14-work-and-closeout-plan.md, step 14b; product-architecture.md,
 * 6.8; decision 155, question 7). Changed only once, to tick it done; never
 * deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $description
 * @property string $fixer Who fixes it: one of the FIXER_ constants.
 * @property Carbon|null $done_on
 * @property int|null $done_by
 * @property string|null $done_by_name
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Attachment> $photos What was found and it fixed (slice 16, step 16c).
 */
class PunchItem extends Model implements HasPhotos
{
    use KeepsPhotos;

    public const string FIXER_SUBCONTRACTOR = 'subcontractor';

    public const string FIXER_BANANA = 'banana';

    /** The longest description kept. */
    public const int DESCRIPTION_LENGTH = 500;

    protected static function booted(): void
    {
        static::updating(function (self $item): void {
            $allowed = ['done_on', 'done_by', 'done_by_name', 'updated_at'];

            if ($item->getOriginal('done_on') !== null || array_diff(array_keys($item->getDirty()), $allowed) !== []) {
                throw new LogicException('A punch item can only be ticked done, once.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A punch item cannot be deleted.');
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
            'done_on' => 'date',
        ];
    }

    public function isDone(): bool
    {
        return $this->done_on !== null;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function photoKinds(): array
    {
        return ['found' => 'As found', 'fixed' => 'Fixed'];
    }

    public function photoProject(): Project
    {
        return $this->project;
    }

    public function attachmentDescription(string $kind): string
    {
        return 'Punch item "'.Str::limit($this->description, 60).'", '.mb_strtolower($this->photoKinds()[$kind] ?? $kind);
    }
}
