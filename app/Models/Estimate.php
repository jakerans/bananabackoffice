<?php

namespace App\Models;

use App\Enums\EstimateType;
use App\Enums\ProposalResult;
use Database\Factories\EstimateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An estimate built on a Lead in the Sales Workspace
 * (docs/slice-31-estimating-plan.md, step 31c; decision 204). It has one or
 * more options, each a set of areas and line items of its own; the
 * discounts, the tax and the hourly rate are the estimate's and apply to
 * every option. It keeps the prices it was built with: a change in the price
 * book reaches it only when someone reprices it.
 *
 * Once presented it has a proposal, the record of what became of it (step
 * 31e; decision 207). While that is pending it can still be changed; once
 * sold or lost it cannot.
 *
 * @property int $id
 * @property int $lead_id
 * @property int $location_id
 * @property int|null $price_book_id The price book it was priced from.
 * @property EstimateType $estimate_type
 * @property int $hourly_rate_cents
 * @property string|null $tax_name
 * @property string|null $tax_percent Null for no tax.
 * @property Carbon $priced_at When its prices were last taken from the price book.
 * @property int|null $created_by
 * @property string $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lead $lead
 * @property-read Location $location
 * @property-read PriceBook|null $priceBook
 * @property-read Proposal|null $proposal
 */
class Estimate extends Model
{
    /** @use HasFactory<EstimateFactory> */
    use HasFactory;

    /**
     * The estimate types an estimate can be started for. Commercial
     * estimates wait (docs/slice-31-estimating-plan.md).
     *
     * @return list<EstimateType>
     */
    public static function offeredTypes(): array
    {
        return [EstimateType::ResidentialInterior, EstimateType::ResidentialExterior];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimate_type' => EstimateType::class,
            'hourly_rate_cents' => 'integer',
            'tax_percent' => 'decimal:4',
            'priced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<PriceBook, $this>
     */
    public function priceBook(): BelongsTo
    {
        return $this->belongsTo(PriceBook::class);
    }

    /**
     * @return HasMany<EstimateOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(EstimateOption::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<EstimateDiscount, $this>
     */
    public function discounts(): HasMany
    {
        return $this->hasMany(EstimateDiscount::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The record of it having been presented, and of what became of it: the
     * one a manager has not marked "added in error". None until presented.
     *
     * @return HasOne<Proposal, $this>
     */
    public function proposal(): HasOne
    {
        return $this->hasOne(Proposal::class)->whereNull('proposals.added_in_error_at')->latestOfMany();
    }

    /**
     * Whether it can no longer be changed: sold, or lost (decision 207).
     */
    public function isSettled(): bool
    {
        return $this->proposal !== null && $this->proposal->result !== ProposalResult::Pending;
    }
}
