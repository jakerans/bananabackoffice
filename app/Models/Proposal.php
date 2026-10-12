<?php

namespace App\Models;

use App\Support\Money;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * The price in dollars, as it is shown.
     */
    public function price(): string
    {
        return Money::format($this->price_cents);
    }
}
