<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The deposit or the final invoice of a Project, as QuickBooks sent it
 * (docs/slice-17-customer-payments-plan.md, step 17a; decision 162).
 *
 * Its total includes the tax QuickBooks worked out, so it is recorded as
 * QuickBooks shows it. Changed only once, to void it; never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind One of the KIND_ constants.
 * @property string $number QuickBooks' invoice number.
 * @property int $amount_cents The total, with tax.
 * @property Carbon $sent_on
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property string|null $voided_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, ProjectPayment> $payments
 */
class ProjectInvoice extends Model
{
    public const string KIND_DEPOSIT = 'deposit';

    public const string KIND_FINAL = 'final';

    /** The kinds, with how each is said, in English. */
    public const array KINDS = [
        self::KIND_DEPOSIT => 'Deposit invoice',
        self::KIND_FINAL => 'Final invoice',
    ];

    /** The longest invoice number kept. */
    public const int NUMBER_LENGTH = 40;

    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        static::updating(function (self $invoice): void {
            $allowed = ['voided_at', 'voided_by', 'voided_by_name', 'voided_reason', 'updated_at'];

            if (array_diff(array_keys($invoice->getDirty()), $allowed) !== [] || $invoice->getOriginal('voided_at') !== null) {
                throw new LogicException('An invoice is kept as it was. Void it and record it again.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('An invoice is kept. Void it instead.');
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
            'sent_on' => 'date',
            'voided_at' => 'datetime',
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
     * @return HasMany<ProjectPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ProjectPayment::class)->orderBy('id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function label(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }
}
