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
 * A direct cost of a job (docs/slice-19-job-costing-plan.md, step 19a;
 * decision 170, question 2; product-architecture.md, 10.1). Subcontractor
 * payouts are not here: they come from the Work Packages.
 *
 * Its receipt is an attachment; a cost without one is listed as missing it
 * (question 3). Changed only once, to void it; never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property string $kind One of the KINDS keys.
 * @property int $amount_cents
 * @property string $vendor
 * @property Carbon $spent_on
 * @property string|null $purchase_order
 * @property bool $after_submission Recorded after job costing was submitted: a correction (step 19b).
 * @property bool $by_finance Recorded by Finance in its reconciliation (step 19c).
 * @property string|null $reason Why, for a correction.
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $voided_by_name
 * @property string|null $voided_reason
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Attachment> $receipts
 */
class ProjectCost extends Model implements Attachable
{
    /** The kinds of direct cost, with how each is said, in English (question 2). */
    public const array KINDS = [
        'materials' => 'Materials',
        'rental' => 'Equipment rental',
        'permit' => 'Permit',
        'disposal' => 'Dump or disposal',
        'labour' => 'W-2 labour',
        'warranty' => 'Warranty or callback',
        'other' => 'Other',
    ];

    /** The kinds bought on a purchase order, so one without it is an accuracy strike (decision 133). */
    public const array NEEDS_PURCHASE_ORDER = ['materials', 'rental'];

    public const string RECEIPT = 'receipt';

    public const int VENDOR_LENGTH = 120;

    public const int PURCHASE_ORDER_LENGTH = 60;

    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        static::updating(function (self $cost): void {
            $allowed = ['voided_at', 'voided_by', 'voided_by_name', 'voided_reason', 'updated_at'];

            if (array_diff(array_keys($cost->getDirty()), $allowed) !== [] || $cost->getOriginal('voided_at') !== null) {
                throw new LogicException('A cost is kept as it was. Void it and record it again.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A cost is kept. Void it instead.');
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
            'after_submission' => 'boolean',
            'by_finance' => 'boolean',
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

    /**
     * Whether it is bought on a purchase order and has none.
     */
    public function lacksPurchaseOrder(): bool
    {
        return in_array($this->kind, self::NEEDS_PURCHASE_ORDER, true) && $this->purchase_order === null;
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
        return 'Receipt for '.$this->kindLabel().', '.$this->vendor.', '.$this->amount();
    }
}
