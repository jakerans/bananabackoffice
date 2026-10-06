<?php

namespace App\Models;

use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Something a staff member wrote about a record, in their own words.
 *
 * A note is not an audit event: it is operational information, such as
 * "customer prefers Tuesdays". What it says is never changed after saving, and
 * it is never deleted. It can only be removed from view.
 *
 * @property int $id
 * @property string $notable_type
 * @property int $notable_id
 * @property string $body
 * @property int|null $author_id
 * @property int|null $impersonator_id
 * @property Carbon|null $removed_at
 * @property int|null $removed_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Model $notable
 * @property-read User|null $author
 * @property-read User|null $impersonator
 * @property-read User|null $removedBy
 */
class Note extends Model
{
    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    /**
     * Refuse to change what a note says or who wrote it, and refuse to delete one.
     */
    protected static function booted(): void
    {
        static::updating(function (Note $note): void {
            if ($note->isDirty(['body', 'author_id', 'impersonator_id', 'notable_type', 'notable_id', 'created_at'])) {
                throw new LogicException('A note cannot be changed after it has been saved.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A note is removed from view, never deleted.');
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
            'removed_at' => 'datetime',
        ];
    }

    /**
     * The record the note is about.
     *
     * @return MorphTo<Model, $this>
     */
    public function notable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The staff member the note is from.
     *
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The Owner who actually typed the note, when it was written while viewing
     * Backoffice as the author. Empty for an ordinary note.
     *
     * Screens never show this: a note reads as its author's. It is kept so the
     * note agrees with the audit event recorded when it was written.
     *
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * The person who removed the note from view.
     *
     * @return BelongsTo<User, $this>
     */
    public function removedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /**
     * Limit the query to notes that have not been removed from view.
     *
     * @param  Builder<Note>  $query
     */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->whereNull('removed_at');
    }

    public function isRemoved(): bool
    {
        return $this->removed_at !== null;
    }

    public function wasWrittenBy(User $user): bool
    {
        return $this->author_id === $user->id;
    }
}
