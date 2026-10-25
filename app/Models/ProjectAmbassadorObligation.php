<?php

namespace App\Models;

use App\Support\Projects\AmbassadorPackage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing a customer promised for their Brand Ambassador discount
 * (docs/slice-21-brand-ambassador-plan.md, step 21b; decision 175;
 * product-architecture.md, 6.10). The Production Manager marks it
 * completed, partial, deferred to a day or waived; each change is audited
 * on the job. A deferred item may stay open after the financial close.
 *
 * @property int $id
 * @property int $project_id
 * @property string $item An AmbassadorPackage::ITEMS key.
 * @property string $state One of the STATES keys.
 * @property string|null $note The note, or why it was waived.
 * @property Carbon|null $deferred_until
 * @property string|null $done_by_name Who physically did it, when someone else did.
 * @property Carbon|null $sign_up_on The day a yard sign went up.
 * @property Carbon|null $reminded_for The due day the Production Manager was last reminded of.
 * @property int|null $handled_by
 * @property string|null $handled_by_name
 * @property Carbon|null $handled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectAmbassadorObligation extends Model
{
    public const string OPEN = 'open';

    public const string COMPLETED = 'completed';

    public const string PARTIAL = 'partial';

    public const string DEFERRED = 'deferred';

    public const string WAIVED = 'waived';

    /** Where an item can stand, and how each is said, in English. */
    public const array STATES = [
        self::OPEN => 'Not handled yet',
        self::COMPLETED => 'Completed',
        self::PARTIAL => 'Partial',
        self::DEFERRED => 'Deferred',
        self::WAIVED => 'Waived',
    ];

    public const int NOTE_LENGTH = 1000;

    public const int DONE_BY_LENGTH = 120;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deferred_until' => 'date',
            'sign_up_on' => 'date',
            'reminded_for' => 'date',
            'handled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function label(): string
    {
        return AmbassadorPackage::itemLabel($this->item);
    }

    public function stateLabel(): string
    {
        return self::STATES[$this->state] ?? $this->state;
    }

    public function isHandled(): bool
    {
        return $this->state !== self::OPEN;
    }

    public function isYardSign(): bool
    {
        return $this->item === 'yard_sign';
    }

    /**
     * Whether it is deferred to a day that has come.
     */
    public function isDue(CarbonInterface $today): bool
    {
        return $this->state === self::DEFERRED && $this->deferred_until !== null && $this->deferred_until->lessThanOrEqualTo($today);
    }
}
