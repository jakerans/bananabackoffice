<?php

namespace App\Models;

use App\Enums\ColorReadiness;
use App\Enums\ProjectProductionState;
use App\Support\Attachments\HasPhotos;
use App\Support\Attachments\KeepsPhotos;
use App\Support\Money;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
 * @property Carbon|null $handoff_reminded_at When the salesperson was reminded to complete the handoff.
 * @property int|null $production_manager_id
 * @property string|null $production_manager_name
 * @property Carbon|null $production_manager_assigned_at
 * @property Carbon|null $welcome_call_on The day the welcome call was made: a checklist item (decision 141). Empty until it is ticked.
 * @property string|null $welcome_call_note
 * @property int|null $welcome_call_by
 * @property string|null $welcome_call_by_name
 * @property Carbon|null $welcome_call_recorded_at
 * @property Carbon|null $timeframe_earliest The earliest day the work is expected to start. Not a booked date.
 * @property Carbon|null $timeframe_latest
 * @property string|null $timeframe_note
 * @property ColorReadiness $color_readiness Where its colors stand: a state of its own (slice 10, step 10b).
 * @property Carbon|null $deposit_paid_at When its deposit invoice was paid in full (slice 17, step 17b). Empty again if a payment is reversed.
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Proposal $proposal
 * @property-read Lead $lead
 * @property-read Location $location
 * @property-read Account $account
 * @property-read Contact|null $contact
 * @property-read Property $property
 * @property-read ProjectHandoff|null $handoff
 * @property-read Collection<int, WorkPackage> $workPackages
 */
class Project extends Model implements HasPhotos
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use KeepsPhotos;

    /** The longest title a Project can have. */
    public const int TITLE_LENGTH = 160;

    /**
     * Mirrors the column default so a newly created model reads as new from Sales.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'production_state' => 'new_from_sales',
        'color_readiness' => 'not_started',
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
            'color_readiness' => ColorReadiness::class,
            'sold_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'handoff_reminded_at' => 'datetime',
            'production_manager_assigned_at' => 'datetime',
            'welcome_call_on' => 'date',
            'welcome_call_recorded_at' => 'datetime',
            'timeframe_earliest' => 'date',
            'timeframe_latest' => 'date',
            'deposit_paid_at' => 'datetime',
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
     * What the salesperson told Production: the answers to the handoff
     * questions, once they are complete (decision 132).
     *
     * @return HasOne<ProjectHandoff, $this>
     */
    public function handoff(): HasOne
    {
        return $this->hasOne(ProjectHandoff::class);
    }

    /**
     * Every version of its Color Specification, the latest first. The latest
     * is the one in force, or the one being written.
     *
     * @return HasMany<ColorSpecification, $this>
     */
    public function colorSpecifications(): HasMany
    {
        return $this->hasMany(ColorSpecification::class)->orderByDesc('version');
    }

    /**
     * Every rough timeframe it has had, the one in force first.
     *
     * @return HasMany<ProjectTimeframe, $this>
     */
    public function timeframes(): HasMany
    {
        return $this->hasMany(ProjectTimeframe::class)->orderByDesc('id');
    }

    /**
     * The packages its work is split into, in their order (slice 11, step 11a).
     *
     * @return HasMany<WorkPackage, $this>
     */
    public function workPackages(): HasMany
    {
        return $this->hasMany(WorkPackage::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Every window of dates offered to the customer, across its packages.
     *
     * @return HasManyThrough<WorkPackageWindow, WorkPackage, $this>
     */
    public function workPackageWindows(): HasManyThrough
    {
        return $this->hasManyThrough(WorkPackageWindow::class, WorkPackage::class);
    }

    /**
     * The days its work is booked for, from the first package's first day to
     * the last one's last day, once every package has dates the customer
     * confirmed. Read from its packages, which should be loaded.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function scheduledDays(): ?array
    {
        $packages = $this->workPackages;

        if ($packages->isEmpty() || $packages->contains(fn (WorkPackage $package): bool => ! $package->isScheduled())) {
            return null;
        }

        /** @var Carbon $first */
        $first = $packages->min('scheduled_first_day');
        /** @var Carbon $last */
        $last = $packages->max('scheduled_last_day');

        return [$first, $last];
    }

    /**
     * Whether the salesperson's handoff is complete. Production cannot take a
     * Project until it is.
     */
    public function hasHandoff(): bool
    {
        return $this->handoff()->exists();
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

    /**
     * Its deposit and final invoices, voided ones too (slice 17).
     *
     * @return HasMany<ProjectInvoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(ProjectInvoice::class)->orderBy('id');
    }

    /**
     * Each smaller deposit, or none, set by a manager; the latest counts
     * (slice 17, step 17b).
     *
     * @return HasMany<ProjectDepositChange, $this>
     */
    public function depositChanges(): HasMany
    {
        return $this->hasMany(ProjectDepositChange::class)->orderBy('id');
    }

    /**
     * Its customer change orders, drafts and withdrawn ones too (slice 18).
     *
     * @return HasMany<ProjectChangeOrder, $this>
     */
    public function changeOrders(): HasMany
    {
        return $this->hasMany(ProjectChangeOrder::class)->orderBy('number');
    }

    /**
     * Each contact with the customer about the balance (slice 17, step 17c).
     *
     * @return HasMany<ProjectCollectionContact, $this>
     */
    public function collectionContacts(): HasMany
    {
        return $this->hasMany(ProjectCollectionContact::class)->orderBy('id');
    }

    /**
     * The customer's payments, reversed ones too (slice 17).
     *
     * @return HasMany<ProjectPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(ProjectPayment::class)->orderBy('id');
    }

    /**
     * The Project itself takes the walkthrough's photos (slice 16, step
     * 16c); its Work Packages and punch items take their own.
     */
    public function photoKinds(): array
    {
        return ['walkthrough' => 'Walkthrough'];
    }

    public function photoProject(): Project
    {
        return $this;
    }

    public function attachmentDescription(string $kind): string
    {
        return $this->photoKinds()[$kind] ?? $kind;
    }
}
