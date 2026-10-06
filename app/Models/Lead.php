<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Support\Contact\PhoneNumber;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * One inquiry. Never a Project.
 *
 * The "inquiry_" attributes are what the person told us, as received. They are
 * the historical record of the inquiry and are never replaced by the details of
 * a customer the Lead is linked to later.
 *
 * @property int $id
 * @property Carbon $received_at
 * @property LeadStatus $status
 * @property int $lead_channel_id
 * @property int $lead_source_id
 * @property int $marketing_source_id
 * @property string|null $inquiry_name
 * @property string|null $inquiry_phone
 * @property string|null $inquiry_phone_normalized
 * @property string|null $inquiry_email
 * @property string|null $inquiry_email_normalized
 * @property string|null $inquiry_business_name
 * @property string|null $inquiry_address
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $address_city
 * @property string|null $address_state
 * @property string|null $address_postal_code
 * @property string|null $address_country
 * @property string|float|null $address_latitude
 * @property string|float|null $address_longitude
 * @property string|null $address_accuracy
 * @property string|null $address_provider
 * @property string|null $address_provider_place_id
 * @property string|null $address_entry
 * @property string|null $inquiry_message
 * @property int|null $project_size_id
 * @property int|null $project_size_min_value
 * @property int|null $project_size_max_value
 * @property int|null $location_id
 * @property int|null $captured_by
 * @property int|null $assigned_user_id
 * @property Carbon|null $assigned_at
 * @property Carbon|null $first_routed_at
 * @property Carbon|null $routed_at
 * @property Carbon|null $work_started_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeadChannel $channel
 * @property-read LeadSource $leadSource
 * @property-read MarketingSource $marketingSource
 * @property-read ProjectSize|null $projectSize
 * @property-read Collection<int, Service> $services
 * @property-read Location|null $location
 * @property-read User|null $capturedBy
 * @property-read User|null $assignee
 */
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => LeadStatus::class,
            'assigned_at' => 'datetime',
            'first_routed_at' => 'datetime',
            'routed_at' => 'datetime',
            'work_started_at' => 'datetime',
            'project_size_min_value' => 'integer',
            'project_size_max_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LeadChannel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(LeadChannel::class, 'lead_channel_id');
    }

    /**
     * @return BelongsTo<LeadSource, $this>
     */
    public function leadSource(): BelongsTo
    {
        return $this->belongsTo(LeadSource::class);
    }

    /**
     * @return BelongsTo<MarketingSource, $this>
     */
    public function marketingSource(): BelongsTo
    {
        return $this->belongsTo(MarketingSource::class);
    }

    /**
     * The kinds of work the inquiry is about. One of them is the primary.
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
     * The service the opportunity is mainly about, if any service was recorded.
     */
    public function primaryService(): ?Service
    {
        return $this->services->first(
            fn (Service $service): bool => (bool) $service->getRelationValue('pivot')?->getAttribute('is_primary'),
        );
    }

    /**
     * The intake person's classification of how big the opportunity is.
     *
     * @return BelongsTo<ProjectSize, $this>
     */
    public function projectSize(): BelongsTo
    {
        return $this->belongsTo(ProjectSize::class);
    }

    /**
     * The expected value the size covered when this Lead was classified, in plain
     * words. It is what was recorded then, whatever the size covers today.
     */
    public function projectSizeRangeLabel(): ?string
    {
        return $this->project_size_min_value === null
            ? null
            : ProjectSize::describeRange($this->project_size_min_value, $this->project_size_max_value);
    }

    /**
     * The location the Lead has been routed to. Empty while it is unrouted.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function capturedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /**
     * The person responsible for working the Lead now. Empty when nobody is.
     *
     * Being the assignee grants no access. It says who is responsible, and it
     * drives the "Assigned to me" queue.
     *
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * Everyone who has been responsible for the Lead, and when.
     *
     * @return HasMany<LeadAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(LeadAssignment::class);
    }

    /**
     * What staff have written about the Lead, including notes since removed from view.
     *
     * @return MorphMany<Note, $this>
     */
    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function isAssigned(): bool
    {
        return $this->assigned_user_id !== null;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->assigned_user_id === $user->id;
    }

    /**
     * Limit the query to Leads that still need someone's attention.
     *
     * Every status that exists today is active. When qualification is built, a
     * disqualified Lead stops being active.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [LeadStatus::New, LeadStatus::Working]);
    }

    /**
     * Limit the query to Leads nobody is responsible for.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function unassigned(Builder $query): void
    {
        $query->whereNull('assigned_user_id');
    }

    /**
     * Limit the query to the Leads a staff member is allowed to see.
     *
     * A routed Lead needs the permission at its own location. An unrouted Lead
     * has no location, so no location grant covers it: it needs its own
     * company-wide permission. Someone allowed neither sees nothing.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $locationIds = $user->locationsWhere(Permission::ViewLeads)->modelKeys();
        $seesUnrouted = $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);

        $query->where(function (Builder $query) use ($locationIds, $seesUnrouted): void {
            $query->whereIn('location_id', $locationIds);

            if ($seesUnrouted) {
                $query->orWhereNull('location_id');
            }
        });
    }

    /**
     * Limit the query to Leads that have not been routed to a location yet.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function unrouted(Builder $query): void
    {
        $query->whereNull('location_id');
    }

    public function isUnrouted(): bool
    {
        return $this->location_id === null;
    }

    /**
     * What to call the Lead on screen: the person, else the business, else nothing was given.
     */
    public function displayName(): ?string
    {
        return $this->inquiry_name ?? $this->inquiry_business_name;
    }

    /**
     * The phone number in its usual form when it is recognized, otherwise as typed.
     */
    public function displayPhone(): ?string
    {
        return PhoneNumber::display($this->inquiry_phone, $this->inquiry_phone_normalized);
    }
}
