<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A file attached to something: a compliance item, a piece of work, a punch
 * item (docs/slice-16-document-storage-plan.md, step 16a; decision 33).
 *
 * The file is stored privately under a random name and opened only by a
 * short-lived link, after a permission check on what it belongs to. Made,
 * removed and purged only by the actions in App\Actions\Attachments. The row
 * is never deleted; only its file, 30 days after it is removed. The bucket
 * keeps the file's earlier version as the backup, and deletes it by its own
 * rule 30 days after that (security-notes.md, "Restoring a file").
 *
 * @property int $id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property string $kind What the file is, in the words of what it belongs to: "certificate", "before"...
 * @property string $disk
 * @property string $path Where it is stored: a random name, never the uploaded one.
 * @property string $original_name The name it was uploaded with, for the person who downloads it.
 * @property string $mime_type
 * @property int $size_bytes
 * @property int|null $uploaded_by
 * @property string|null $uploaded_by_name
 * @property Carbon|null $removed_at Hidden from then on.
 * @property int|null $removed_by
 * @property string|null $removed_by_name
 * @property string|null $removed_reason
 * @property Carbon|null $purged_at When its file was deleted from storage.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $attachable
 */
class Attachment extends Model
{
    /** The types of file accepted, by extension (decision 157, question 5). */
    public const array TYPES = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'heic' => 'image/heic',
    ];

    /** The largest file accepted: 20 MB. */
    public const int MAX_BYTES = 20 * 1024 * 1024;

    /** How long a link to open a file works (question 7). */
    public const int LINK_MINUTES = 5;

    /** How long a removed file is kept before it is deleted (question 10). */
    public const int KEEP_REMOVED_DAYS = 30;

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('An attachment record is kept. Remove the attachment instead.');
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
            'size_bytes' => 'integer',
            'removed_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Attachments not removed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function shown(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    /**
     * Whether it is an image that can be shown in a page.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/') && $this->mime_type !== 'image/heic';
    }

    /**
     * The size as it is said: "2.4 MB", "820 KB".
     */
    public function size(): string
    {
        return $this->size_bytes >= 1024 * 1024
            ? number_format($this->size_bytes / 1024 / 1024, 1).' MB'
            : max(1, (int) round($this->size_bytes / 1024)).' KB';
    }
}
