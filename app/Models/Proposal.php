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
 * Since step 31e it is the record of an estimate that was presented: people
 * see "Estimate", and the code keeps "proposal" (decision 207, question 5).
 * Those recorded by hand before then have no estimate and are shown as
 * "Recorded by hand" (question 4).
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
 * @property Carbon|null $valid_until The last day it is valid, in its Location's calendar (decision 137). Its salesperson keeps the Lead until the last pending one expires.
 * @property Carbon|null $added_in_error_at
 * @property int|null $added_in_error_by
 * @property string|null $added_in_error_by_name
 * @property string|null $added_in_error_note
 * @property int|null $estimate_id The estimate it records; none for one recorded by hand before step 31e.
 * @property int|null $estimate_option_id The option the customer chose, once sold.
 * @property int|null $price_from_cents Its estimate's lowest option price; price_cents is the highest.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read Estimate|null $estimate
 * @property-read EstimateOption|null $option
 * @property-read Agreement|null $agreement The one signed for it, unless void.
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
            'valid_until' => 'date',
            'added_in_error_at' => 'datetime',
            'price_from_cents' => 'integer',
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
     * The estimate it records, when it was not recorded by hand.
     *
     * @return BelongsTo<Estimate, $this>
     */
    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    /**
     * The option of its estimate the customer chose, once sold.
     *
     * @return BelongsTo<EstimateOption, $this>
     */
    public function option(): BelongsTo
    {
        return $this->belongsTo(EstimateOption::class, 'estimate_option_id');
    }

    /**
     * Whether it records an estimate, rather than having been recorded by hand.
     */
    public function isFromEstimate(): bool
    {
        return $this->estimate_id !== null;
    }

    /**
     * The agreement the customer signed for it, unless it was made void
     * (docs/slice-33-agreement-plan.md, step 33b). A sale marked Sold without
     * signing in Backoffice has none.
     *
     * @return HasOne<Agreement, $this>
     */
    public function agreement(): HasOne
    {
        return $this->hasOne(Agreement::class)->whereNull('agreements.voided_at')->latestOfMany('signed_at');
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
     * The price in dollars, as it is shown. An estimate's is its options'
     * range while it is not sold, and the chosen option's once it is
     * (docs/slice-31-estimating-plan.md, step 31e).
     */
    public function price(): string
    {
        if ($this->isFromEstimate() && $this->sold_cents !== null && $this->result === ProposalResult::Sold) {
            return Money::format($this->sold_cents);
        }

        if ($this->price_from_cents !== null && $this->price_from_cents !== $this->price_cents) {
            return __(':from to :to', ['from' => Money::format($this->price_from_cents), 'to' => Money::format($this->price_cents)]);
        }

        return Money::format($this->price_cents);
    }
}
