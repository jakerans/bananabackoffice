<?php

namespace App\Models;

use App\Enums\DisqualificationCategory;
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
 * @property int $lifecycle_version
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
 * @property Carbon|null $status_changed_at
 * @property Carbon|null $first_qualified_at
 * @property Carbon|null $follow_up_on
 * @property string|null $follow_up_note
 * @property int|null $disqualification_reason_id
 * @property DisqualificationCategory|null $disqualification_category
 * @property string|null $disqualification_note
 * @property Carbon|null $last_disqualified_at
 * @property int|null $disqualified_by
 * @property int|null $duplicate_of_lead_id
 * @property-read LeadDisqualificationReason|null $disqualificationReason
 * @property-read User|null $disqualifiedBy
 * @property-read Lead|null $duplicateOf
 * @property-read Collection<int, ContactAttempt> $contactAttempts
 * @property int|null $account_id
 * @property int|null $contact_id
 * @property int|null $property_id
 * @property-read Account|null $account
 * @property-read Contact|null $contact
 * @property-read Property|null $property
 * @property Carbon|null $first_issued_at When the Lead was first issued to Sales, among issues that still count.
 * @property Carbon|null $last_issued_at
 * @property-read Collection<int, Appointment> $appointments
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
            'lifecycle_version' => 'integer',
            'assigned_at' => 'datetime',
            'first_routed_at' => 'datetime',
            'routed_at' => 'datetime',
            'work_started_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'first_qualified_at' => 'datetime',
            'first_issued_at' => 'datetime',
            'last_issued_at' => 'datetime',
            'follow_up_on' => 'date',
            'last_disqualified_at' => 'datetime',
            'disqualification_category' => DisqualificationCategory::class,
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
     * The customer this inquiry turned out to be from. Empty until someone has
     * worked that out. Linking never changes the inquiry as it was received.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The person at that customer the inquiry is from.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * The customer's property the inquiry is about.
     *
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
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

    /**
     * Whether the Lead still needs someone's attention: anything but
     * Disqualified. Its location, assignee and customer links can be changed
     * only while it does.
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Today's date where the company is, which is what a follow-up date is compared with.
     */
    public static function today(): string
    {
        return now()->setTimezone(config('app.display_timezone'))->toDateString();
    }

    /**
     * Whether a Nurture Lead's follow-up date has arrived or passed.
     */
    public function followUpIsDue(): bool
    {
        return $this->status === LeadStatus::Nurture
            && $this->follow_up_on !== null
            && $this->follow_up_on->toDateString() <= self::today();
    }

    /**
     * How many days past its follow-up date a Nurture Lead is. Zero on the day itself.
     */
    public function daysOverdue(): int
    {
        if (! $this->followUpIsDue() || $this->follow_up_on === null) {
            return 0;
        }

        return (int) $this->follow_up_on->startOfDay()->diffInDays(Carbon::parse(self::today())->startOfDay());
    }

    /**
     * What is still missing before the Lead can be marked Qualified.
     *
     * Qualified means a legitimate opportunity has been identified and the core
     * information needed to create its Project is there. An empty list means it
     * may be qualified.
     *
     * @return list<string> Any of: location, assignee, customer, contact, property, service, project_size.
     */
    public function qualificationGaps(): array
    {
        $services = $this->services;

        return array_values(array_filter([
            $this->location_id === null ? 'location' : null,
            $this->assigned_user_id === null ? 'assignee' : null,
            $this->account_id === null || $this->account?->isArchived() ? 'customer' : null,
            $this->contact_id === null ? 'contact' : null,
            $this->property_id === null || $this->property?->isArchived() ? 'property' : null,
            $services->isEmpty() || $this->primaryService() === null ? 'service' : null,
            $this->project_size_id === null ? 'project_size' : null,
        ]));
    }

    /**
     * The reason the Lead is disqualified, while it is.
     *
     * @return BelongsTo<LeadDisqualificationReason, $this>
     */
    public function disqualificationReason(): BelongsTo
    {
        return $this->belongsTo(LeadDisqualificationReason::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disqualifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disqualified_by');
    }

    /**
     * The Lead this one repeats, when it was disqualified as a duplicate.
     *
     * @return BelongsTo<Lead, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_lead_id');
    }

    /**
     * Every change to the Lead's lifecycle, oldest first.
     *
     * @return HasMany<LeadStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(LeadStatusChange::class)->orderBy('id');
    }

    /** @return HasMany<ContactAttempt, $this> */
    public function contactAttempts(): HasMany
    {
        return $this->hasMany(ContactAttempt::class);
    }

    /**
     * Every appointment ever booked for the Lead, newest first, including the
     * ones a manager marked "booked in error".
     *
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderByDesc('id');
    }

    /**
     * The appointment that stands for the Lead now, if there is one.
     */
    public function standingAppointment(): ?Appointment
    {
        return $this->appointments()->standing()->first();
    }

    /**
     * Whether the Lead has an appointment that stands and has not finished.
     * While it does, the Lead cannot be moved to another Location, returned
     * to central or disqualified (decision 95).
     */
    public function hasUpcomingAppointment(): bool
    {
        return $this->appointments()->upcomingAt(now())->exists();
    }

    /**
     * What is still missing before an appointment can be booked for the Lead
     * (rulebook, rule 1): it must be Qualified, at a Location, and linked to a
     * customer with a property. An empty list means it may be booked.
     *
     * @return list<string> Any of: qualified, location, customer, property.
     */
    public function bookingGaps(): array
    {
        return array_values(array_filter([
            $this->status !== LeadStatus::Qualified ? 'qualified' : null,
            $this->location_id === null ? 'location' : null,
            $this->account_id === null || $this->account?->isArchived() ? 'customer' : null,
            $this->property_id === null || $this->property?->isArchived() ? 'property' : null,
        ]));
    }

    /**
     * Limit the query to Leads that someone has to act on now: New, Working and
     * Qualified, and Nurture once its follow-up date has arrived. A Nurture Lead
     * with a date still ahead is waiting, not work.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function actionable(Builder $query): void
    {
        $query->where(function (Builder $query): void {
            $query
                ->whereIn('status', [LeadStatus::New, LeadStatus::Working, LeadStatus::Qualified])
                ->orWhere(fn (Builder $nurture) => $nurture->where('status', LeadStatus::Nurture)->whereDate('follow_up_on', '<=', self::today()));
        });
    }

    public function isLinkedToCustomer(): bool
    {
        return $this->account_id !== null;
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
     * Limit the query to Leads that still need someone's attention: every
     * status but Disqualified. A Qualified Lead stays active until Projects
     * exist to carry it on.
     *
     * @param  Builder<Lead>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', LeadStatus::active());
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
