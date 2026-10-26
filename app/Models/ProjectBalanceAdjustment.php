<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A credit, refund or bad-debt write-off on a job
 * (docs/slice-24-refunds-credits-plan.md; decision 181;
 * product-architecture.md, 10.5 and 10.7). Asked for by one person and
 * approved or declined by another. An approved credit lowers what the
 * customer owes and the job's revenue; an approved refund, once paid, gives
 * money back; an approved write-off closes the balance, below gross margin.
 * Changed only to decide it and to record a refund paid; never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind One of the KINDS keys.
 * @property int $amount_cents
 * @property string $reason
 * @property string $status One of the STATUS_ constants.
 * @property int|null $requested_by
 * @property string|null $requested_by_name
 * @property int|null $decided_by
 * @property string|null $decided_by_name
 * @property Carbon|null $decided_at
 * @property string|null $declined_reason
 * @property string|null $quickbooks_reference The credit memo, refund or write-off in QuickBooks.
 * @property Carbon|null $paid_on A refund: the day it was paid back.
 * @property string|null $paid_method One of ProjectPayment::METHODS.
 * @property string|null $paid_reference
 * @property int|null $paid_recorded_by
 * @property string|null $paid_recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 */
class ProjectBalanceAdjustment extends Model
{
    public const string CREDIT = 'credit';

    public const string REFUND = 'refund';

    public const string WRITE_OFF = 'write_off';

    /** The kinds, with how each is said, in English. */
    public const array KINDS = [
        self::CREDIT => 'Credit',
        self::REFUND => 'Refund',
        self::WRITE_OFF => 'Bad-debt write-off',
    ];

    public const string STATUS_REQUESTED = 'requested';

    public const string STATUS_APPROVED = 'approved';

    public const string STATUS_DECLINED = 'declined';

    public const int REASON_LENGTH = 1000;

    public const int REFERENCE_LENGTH = 60;

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            $deciding = $adjustment->getOriginal('status') === self::STATUS_REQUESTED;
            $paying = $adjustment->kind === self::REFUND && $adjustment->getOriginal('status') === self::STATUS_APPROVED && $adjustment->getOriginal('paid_on') === null;
            $allowed = [
                ...($deciding ? ['status', 'decided_by', 'decided_by_name', 'decided_at', 'declined_reason', 'quickbooks_reference'] : []),
                ...($paying ? ['paid_on', 'paid_method', 'paid_reference', 'paid_recorded_by', 'paid_recorded_by_name', 'quickbooks_reference'] : []),
                'updated_at',
            ];

            if (array_diff(array_keys($adjustment->getDirty()), $allowed) !== []) {
                throw new LogicException('A credit, refund or write-off is kept as it was once decided.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A credit, refund or write-off is kept.');
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
            'amount_cents' => 'integer',
            'decided_at' => 'datetime',
            'paid_on' => 'date',
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
     * What approved credits have taken off a job's revenue, in cents.
     */
    public static function creditedCents(int $projectId): int
    {
        return (int) self::query()->where('project_id', $projectId)->where('kind', self::CREDIT)->where('status', self::STATUS_APPROVED)->sum('amount_cents');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRequested(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }

    /**
     * Whether it counts toward the balance now: an approved credit or
     * write-off, or a refund paid.
     */
    public function counts(): bool
    {
        return $this->isApproved() && ($this->kind !== self::REFUND || $this->paid_on !== null);
    }
}
