<?php

namespace App\Models;

use App\Enums\ProjectProductionState;
use App\Support\Money;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A sold job (docs/slice-8-projects-plan.md, step 8a). One is made the moment
 * a proposal is Sold, and each sold proposal has one of its own (rulebook,
 * rule 13; decision 130).
 *
 * It belongs to a Location, which decides who may see it; whose it is grants
 * nothing (decision 31). It is made and cancelled only by the actions in
 * App\Actions\Projects, and is never deleted.
 *
 * @property int $id
 * @property int $proposal_id
 * @property int $lead_id
 * @property int $location_id
 * @property int $account_id
 * @property int|null $contact_id
 * @property int $property_id
 * @property string $title
 * @property string|null $description
 * @property int|null $project_size_id
 * @property int|null $project_size_min_value
 * @property int|null $project_size_max_value
 * @property int $sold_cents What the proposal sold for, kept as it was at the sale.
 * @property Carbon $sold_at
 * @property int|null $salesperson_id The salesperson the Lead was with at the sale.
 * @property string|null $salesperson_name
 * @property int|null $created_by Who recorded the sale.
 * @property string|null $created_by_name
 * @property ProjectProductionState $production_state
 * @property Carbon|null $cancelled_at When its sale was corrected; empty while the sale stands.
 * @property int|null $cancelled_by
 * @property string|null $cancelled_by_name
 * @property string|null $cancellation_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proposal $proposal
 * @property-read Lead $lead
 * @property-read Location $location
 * @property-read Account $account
 * @property-read Contact|null $contact
 * @property-read Property $property
 */
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /** The longest title a Project can have. */
    public const int TITLE_LENGTH = 160;

    /**
     * Mirrors the column default so a newly created model reads as new from Sales.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'production_state' => 'new_from_sales',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A project cannot be deleted.');
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
            'production_state' => ProjectProductionState::class,
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'project_size_min_value' => 'integer',
            'project_size_max_value' => 'integer',
        ];
    }

    /**
     * The proposal that was sold.
     *
     * @return BelongsTo<Proposal, $this>
     */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Proposal::class);
    }

    /**
     * The Lead the sale came from.
     *
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
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * The kinds of work, as they were on the Lead at the sale. One is the main one.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)
            ->withPivot('is_primary')
            ->withTimestamps()
            ->orderBy('services.position')
            ->orderBy('services.id');
    }

    /**
     * Projects whose sale stands: every one that was not cancelled.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function standing(Builder $query): void
    {
        $query->whereNull('projects.cancelled_at');
    }

    /**
     * Whether its sale was corrected and it was cancelled with it.
     */
    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    /**
     * What it sold for in dollars, as it is shown.
     */
    public function soldFor(): string
    {
        return Money::format($this->sold_cents);
    }

    /**
     * The size the Lead was given at intake, in plain words, as it was then.
     */
    public function projectSizeRangeLabel(): ?string
    {
        return $this->project_size_min_value === null
            ? null
            : ProjectSize::describeRange($this->project_size_min_value, $this->project_size_max_value);
    }
}
