<?php

namespace App\Models;

use App\Enums\AuditAction;
use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * @property array<string, mixed>|null $values_before
 * @property array<string, mixed>|null $values_after
 */
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /**
     * An audit event is never changed, so it has no "updated at".
     */
    public const UPDATED_AT = null;

    /**
     * Refuse to change or remove an audit event once it has been recorded.
     */
    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('An audit event cannot be changed once it has been recorded.');
        });

        static::deleting(function (): never {
            throw new LogicException('An audit event cannot be deleted.');
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
            'action' => AuditAction::class,
            'values_before' => 'array',
            'values_after' => 'array',
        ];
    }

    /**
     * The person who made the change. Empty when it was made from the command line.
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The record the change was made to.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The values before the change, for the fields that were recorded.
     *
     * @return array<string, mixed>
     */
    public function before(): array
    {
        return $this->values_before ?? [];
    }

    /**
     * The values after the change, for the fields that were recorded.
     *
     * @return array<string, mixed>
     */
    public function after(): array
    {
        return $this->values_after ?? [];
    }
}
