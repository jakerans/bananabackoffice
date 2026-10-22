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
 * Money received from the customer against one invoice
 * (docs/slice-17-customer-payments-plan.md, step 17a; decision 162; decision
 * 133).
 *
 * Card, bank and financing payments count at once. Cash and check need a
 * photo of the receipt and count only once Finance approves them. A wrong
 * payment is reversed by Finance with a reason. Changed only to approve or
 * reverse it; never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property int $project_invoice_id
 * @property int $amount_cents
 * @property string $method One of the METHODS keys.
 * @property Carbon $received_on
 * @property string|null $reference The processor's or QuickBooks' reference.
 * @property bool $needs_approval Cash and check.
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property string|null $approved_by_name
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversed_by_name
 * @property string|null $reversed_reason
 * @property int|null $recorded_by
 * @property string|null $recorded_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read ProjectInvoice $invoice
 * @property-read Collection<int, Attachment> $receipts
 */
class ProjectPayment extends Model implements Attachable
{
    /** How a customer pays, with how each is said, in English (product-architecture.md, 9.2). */
    public const array METHODS = [
        'card' => 'Card',
        'ach' => 'Bank transfer (ACH)',
        'check' => 'Check',
        'cash' => 'Cash',
        'financing' => 'Financing',
    ];

    /** The methods that need a receipt and Finance's approval (decision 133). */
    public const array NEEDS_APPROVAL = ['check', 'cash'];

    /** What a receipt photo is called. */
    public const string RECEIPT = 'receipt';

    /** The longest reference kept. */
    public const int REFERENCE_LENGTH = 100;

    /** The longest reason kept. */
    public const int REASON_LENGTH = 1000;

    protected static function booted(): void
    {
        static::updating(function (self $payment): void {
            $allowed = ['approved_at', 'approved_by', 'approved_by_name', 'reversed_at', 'reversed_by', 'reversed_by_name', 'reversed_reason', 'updated_at'];

            if (array_diff(array_keys($payment->getDirty()), $allowed) !== [] || $payment->getOriginal('reversed_at') !== null) {
                throw new LogicException('A payment is kept as it was. Reverse it instead.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A payment is kept. Reverse it instead.');
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
            'received_on' => 'date',
            'needs_approval' => 'boolean',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
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
     * @return BelongsTo<ProjectInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ProjectInvoice::class, 'project_invoice_id');
    }

    /**
     * Its receipt photos not removed.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function receipts(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->where('kind', self::RECEIPT)->whereNull('removed_at')->orderBy('id');
    }

    /**
     * Whether it counts toward what was paid: not reversed, and approved if
     * it needed to be.
     */
    public function counts(): bool
    {
        return ! $this->isReversed() && (! $this->needs_approval || $this->approved_at !== null);
    }

    public function isAwaitingApproval(): bool
    {
        return ! $this->isReversed() && $this->needs_approval && $this->approved_at === null;
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
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
        return 'Receipt for the '.$this->methodLabel().' payment of '.$this->amount();
    }
}
