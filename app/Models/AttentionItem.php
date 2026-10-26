<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something that needs someone (docs/slice-26-needs-attention-plan.md;
 * decision 185; product-architecture.md, 19): what the exception engine
 * found for one owner, kept from the sweep that first saw it until the one
 * that no longer does. Its owner marks it useful, not useful, or dismisses
 * it.
 *
 * @property int $id
 * @property string $rule One of the Rules' keys.
 * @property string $subject_key What it is about: "project:5", "period:2026-10".
 * @property int $user_id Its owner.
 * @property int|null $location_id
 * @property string $severity One of the SEVERITIES keys.
 * @property string $title What needs doing, in plain English.
 * @property string $url Where to do it.
 * @property Carbon $opened_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $closed_at When the sweep no longer found it: fixed.
 * @property Carbon|null $notified_at When its owner's bell was rung, for an Urgent one.
 * @property string|null $feedback One of the FEEDBACK keys.
 * @property string|null $feedback_reason
 * @property Carbon|null $feedback_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Location|null $location
 */
class AttentionItem extends Model
{
    public const string URGENT = 'urgent';

    public const string ATTENTION = 'attention';

    public const string FYI = 'fyi';

    /** The severities, the most serious first, with how each is said (19). */
    public const array SEVERITIES = [
        self::URGENT => 'Urgent',
        self::ATTENTION => 'Needs Attention',
        self::FYI => 'FYI',
    ];

    public const string USEFUL = 'useful';

    public const string NOT_USEFUL = 'not_useful';

    public const string DISMISSED = 'dismissed';

    /** The feedback an owner can give (19). */
    public const array FEEDBACK = [
        self::USEFUL => 'Useful',
        self::NOT_USEFUL => 'Not useful',
        self::DISMISSED => 'Dismissed',
    ];

    public const int REASON_LENGTH = 500;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'closed_at' => 'datetime',
            'notified_at' => 'datetime',
            'feedback_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? $this->severity;
    }

    /**
     * The severity as a number to sort by, the most serious first.
     */
    public function severityRank(): int
    {
        return (int) array_search($this->severity, array_keys(self::SEVERITIES), true);
    }
}
