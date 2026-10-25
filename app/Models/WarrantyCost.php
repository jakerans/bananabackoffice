<?php

namespace App\Models;

use App\Support\Attachments\Attachable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A cost of a warranty case (docs/slice-22-warranty-plan.md, step 22c;
 * decision 177, question 5; product-architecture.md, 10.1 and 16). It rolls
 * into the original job's lifetime gross margin, and counts against the
 * job's Production Manager's Production Bonus in the month it is recorded.
 * A subcontractor's payout may be $0. Changed only once, to void it; never
 * deleted.
 *
 * @property int $id
 * @property int $warranty_case_id
 * @property int $project_id
 * @property string $kind One of the KINDS keys.
 * @property int $amount_cents
 * @property string $paid_to
 * @property Carbon $spent_on
 * @property string|null $note
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property string|null $voided_reason
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read WarrantyCase $warrantyCase
 * @property-read Project $project
 * @property-read Collection<int, Attachment> $receipts
 */
class WarrantyCost extends Model implements Attachable
{
    /** The kinds of warranty cost, with how each is said, in English. */
    public const array KINDS = [
        'materials' => 'Materials',
        'payout' => 'Subcontractor payout',
        'labour' => 'W-2 labour',
        'other' => 'Other',
    ];

    /** The one kind that may be $0: a subcontractor returning to correct their own work (16). */
    public const string MAY_BE_NOTHING = 'payout';

    public const string RECEIPT = 'receipt';

    public const int PAID_TO_LENGTH = 120;

    public const int NOTE_LENGTH = 1000;

    protected static function booted(): void
    {
        static::updating(function (self $cost): void {
            $allowed = ['voided_at', 'voided_by', 'voided_by_name', 'voided_reason', 'updated_at'];

            if (array_diff(array_keys($cost->getDirty()), $allowed) !== [] || $cost->getOriginal('voided_at') !== null) {
                throw new LogicException('A warranty cost is kept as it was. Void it and record it again.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A warranty cost is kept. Void it instead.');
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
            'spent_on' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WarrantyCase, $this>
     */
    public function warrantyCase(): BelongsTo
    {
        return $this->belongsTo(WarrantyCase::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Its receipts not removed.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function receipts(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->where('kind', self::RECEIPT)->whereNull('removed_at')->orderBy('id');
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function amount(): string
    {
        return Money::format($this->amount_cents);
    }

    public function attachmentAuditSubject(): Model
    {
        return $this->project;
    }

    public function attachmentAuditLocation(): ?Location
    {
        return $this->project->location;
    }

    public function attachmentDescription(string $kind): string
    {
        return 'Receipt for warranty '.mb_strtolower($this->kindLabel()).', '.$this->paid_to.', '.$this->amount();
    }
}
