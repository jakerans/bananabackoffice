<?php

namespace App\Models;

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Support\Contact\PhoneNumber;
use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $inquiry_message
 * @property int|null $location_id
 * @property int|null $captured_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read LeadChannel $channel
 * @property-read LeadSource $leadSource
 * @property-read MarketingSource $marketingSource
 * @property-read Location|null $location
 * @property-read User|null $capturedBy
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
