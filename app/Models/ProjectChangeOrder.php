<?php

namespace App\Models;

use App\Enums\ColorApprovalMethod;
use App\Support\Attachments\Attachable;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A customer change order: an amendment to what the customer agreed
 * (docs/slice-18-change-orders-plan.md, step 18a; decision 166;
 * product-architecture.md, 6.7). The original agreement is kept; the change
 * order says what changes and by how much.
 *
 * A draft until it is signed; a draft can be withdrawn. Once signed or
 * withdrawn it is kept as it was: a mistake is put right by another change
 * order. Never deleted.
 *
 * @property int $id
 * @property int $project_id
 * @property int $number Its number on the job: 1, 2, 3.
 * @property string $description What changes, in words.
 * @property int $price_change_cents Before tax; below zero when the price comes down.
 * @property int $working_days_added
 * @property string|null $reason Why the price comes down: a manager's, required then.
 * @property string $status One of the STATUS_ constants.
 * @property string|null $signed_by_person Who signed for the customer.
 * @property ColorApprovalMethod|null $signed_method
 * @property Carbon|null $signed_on
 * @property Carbon|null $signed_recorded_at
 * @property int|null $signed_recorded_by
 * @property string|null $signed_recorded_by_name
 * @property Carbon|null $withdrawn_at
 * @property int|null $withdrawn_by
 * @property string|null $withdrawn_by_name
 * @property string|null $withdrawn_reason
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Project $project
 * @property-read Collection<int, Attachment> $signedCopies
 */
class ProjectChangeOrder extends Model implements Attachable
{
    public const string STATUS_DRAFT = 'draft';

    public const string STATUS_SIGNED = 'signed';

    public const string STATUS_WITHDRAWN = 'withdrawn';

    /** What the signed copy is called. */
    public const string SIGNED_COPY = 'signed_copy';

    /** How a customer can sign one: with a signed copy, so not by phone (decision 166, question 2). */
    public const array SIGNING_METHODS = [ColorApprovalMethod::InPerson, ColorApprovalMethod::Email, ColorApprovalMethod::Text];

    public const int DESCRIPTION_LENGTH = 2000;

    public const int REASON_LENGTH = 1000;

    public const int PERSON_LENGTH = 120;

    /** The most working days one change order adds. */
    public const int MOST_DAYS = 60;

    protected static function booted(): void
    {
        static::updating(function (self $order): void {
            if ($order->getOriginal('status') !== self::STATUS_DRAFT) {
                throw new LogicException('A signed or withdrawn change order is kept as it was. Add another change order.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('A change order is kept. Withdraw a draft instead.');
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
            'number' => 'integer',
            'price_change_cents' => 'integer',
            'working_days_added' => 'integer',
            'signed_method' => ColorApprovalMethod::class,
            'signed_on' => 'date',
            'signed_recorded_at' => 'datetime',
            'withdrawn_at' => 'datetime',
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
     * Its signed copies not removed.
     *
     * @return MorphMany<Attachment, $this>
     */
    public function signedCopies(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->where('kind', self::SIGNED_COPY)->whereNull('removed_at')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function isWithdrawn(): bool
    {
        return $this->status === self::STATUS_WITHDRAWN;
    }

    /**
     * The price change as it is said: "+$1,200", "-$300", "No change".
     */
    public function priceChange(): string
    {
        return match (true) {
            $this->price_change_cents > 0 => '+'.Money::format($this->price_change_cents),
            $this->price_change_cents < 0 => Money::format($this->price_change_cents),
            default => 'No price change',
        };
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
        return 'Change order '.$this->number.', signed copy';
    }
}
