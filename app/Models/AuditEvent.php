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
 * @property string|null $actor_name
 * @property string|null $impersonator_name
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
     * The Owner who was really signed in, when the change was made while viewing
     * Backoffice as the actor. Empty for an ordinary change.
     *
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    public function wasImpersonated(): bool
    {
        return $this->getAttribute('impersonator_id') !== null || $this->impersonator_name !== null;
    }

    /**
     * Who made the change, in words, never crediting an impersonated change to
     * the staff member alone: "Jacob Ransom, impersonating Roseanne Smith".
     */
    public function madeBy(): string
    {
        $actor = $this->actor_name ?? __('The command line');

        return $this->wasImpersonated()
            ? __(':owner, impersonating :staff', ['owner' => $this->impersonator_name, 'staff' => $actor])
            : $actor;
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
