<?php

namespace App\Models;

use App\Enums\ProposalResult;
use App\Support\Money;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * What a salesperson pitched to a customer and for how much
 * (docs/slice-7-appointment-results-plan.md, step 7b). It belongs to one Lead
 * and was presented at one or more of that Lead's run appointments (decisions
 * 121 and 125).
 *
 * It is added only by the actions in App\Actions\Proposals and is never
 * deleted. A run appointment with at least one proposal counts for Demo Rate
 * (decision 124).
 *
 * @property int $id
 * @property int $lead_id
 * @property int $location_id
 * @property int $account_id
 * @property int $property_id
 * @property string $name What was pitched, in the salesperson's words.
 * @property int $price_cents
 * @property int|null $created_by
 * @property string $created_by_name
 * @property ProposalResult $result
 * @property Carbon|null $result_at When its result, or its objection while pending, was last recorded.
 * @property int|null $result_by
 * @property string|null $result_by_name
 * @property int|null $sold_cents
 * @property int|null $loss_reason_id
 * @property string|null $loss_reason_name
 * @property string|null $lost_to The competitor, when the reason asks for one.
 * @property int|null $objection_id
 * @property string|null $objection_name
 * @property string|null $result_note
 * @property Carbon|null $added_in_error_at
 * @property int|null $added_in_error_by
 * @property string|null $added_in_error_by_name
 * @property string|null $added_in_error_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 */
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    /** The longest name a proposal can have. */
    public const int NAME_LENGTH = 120;

    /**
     * Mirrors the column default so a newly created model reads as pending.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'result' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => ProposalResult::class,
            'result_at' => 'datetime',
            'added_in_error_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A proposal cannot be deleted.');
        });
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The one property of the customer it is for (decision 132). Its Project
     * takes the same one.
     *
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The Project made for it when it was sold (decision 130). A proposal
     * that was never sold has none.
     *
     * @return HasOne<Project, $this>
     */
    public function project(): HasOne
    {
        return $this->hasOne(Project::class);
    }

    /**
     * The appointments it was presented at, earliest first, each with who
     * recorded that and when.
     *
     * @return BelongsToMany<Appointment, $this>
     */
    public function appointments(): BelongsToMany
    {
        return $this->belongsToMany(Appointment::class)
            ->withPivot(['added_by', 'added_by_name', 'created_at'])
            ->orderBy('appointments.starts_at');
    }

    /**
     * Proposals that count: every one a manager has not marked "added in
     * error" (decision 128).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function counted(Builder $query): void
    {
        $query->whereNull('proposals.added_in_error_at');
    }

    /**
     * Whether a manager marked it "added in error". It then counts for
     * nothing and has no result.
     */
    public function isAddedInError(): bool
    {
        return $this->added_in_error_at !== null;
    }

    /**
     * Whether it is still open and can be given a result, edited or marked
     * "added in error".
     */
    public function isOpen(): bool
    {
        return ! $this->isAddedInError() && $this->result === ProposalResult::Pending;
    }

    /**
     * What it sold for in dollars, as it is shown, when it was sold.
     */
    public function soldFor(): ?string
    {
        return $this->sold_cents === null ? null : Money::format($this->sold_cents);
    }

    /**
     * The price in dollars, as it is shown.
     */
    public function price(): string
    {
        return Money::format($this->price_cents);
    }
}
