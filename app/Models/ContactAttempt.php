<?php

namespace App\Models;

use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\ContactAttemptLifecycleResult;
use App\Enums\ContactAttemptSource;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Support\Contact\PhoneNumber;
use Database\Factories\ContactAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One factual attempt to contact the person behind a Lead.
 *
 * @property int $id
 * @property int $lead_id
 * @property string $submission_id
 * @property string $submission_fingerprint
 * @property int|null $account_id
 * @property int|null $contact_id
 * @property int|null $location_id
 * @property int|null $responsible_user_id
 * @property string $responsible_user_name
 * @property int|null $impersonator_id
 * @property string|null $impersonator_name
 * @property ContactAttemptSource $source
 * @property ContactAttemptChannel $channel
 * @property ContactAttemptDirection $direction
 * @property Carbon $occurred_at
 * @property string|null $number
 * @property string|null $number_normalized
 * @property int|null $duration_seconds
 * @property bool $was_answered
 * @property bool $was_connected
 * @property ContactAttemptDisposition $disposition
 * @property LeadStatus $lead_status
 * @property ContactAttemptLifecycleResult $lifecycle_result
 * @property string|null $recording_reference
 * @property string|null $transcript_reference
 * @property Carbon $created_at
 * @property-read Lead $lead
 * @property-read Account|null $account
 * @property-read Contact|null $contact
 * @property-read Location|null $location
 * @property-read User|null $responsibleUser
 * @property-read User|null $impersonator
 */
class ContactAttempt extends Model
{
    /** @use HasFactory<ContactAttemptFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $hidden = [
        'submission_id',
        'submission_fingerprint',
    ];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('A contact attempt cannot be changed once it has been recorded.');
        });

        static::deleting(function (): never {
            throw new LogicException('A contact attempt cannot be deleted.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ContactAttemptSource::class,
            'channel' => ContactAttemptChannel::class,
            'direction' => ContactAttemptDirection::class,
            'occurred_at' => 'datetime',
            'duration_seconds' => 'integer',
            'was_answered' => 'boolean',
            'was_connected' => 'boolean',
            'disposition' => ContactAttemptDisposition::class,
            'lead_status' => LeadStatus::class,
            'lifecycle_result' => ContactAttemptLifecycleResult::class,
        ];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<User, $this> */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_id');
    }

    /**
     * Restrict historical rows to the location where each attempt occurred.
     *
     * @param  Builder<ContactAttempt>  $query
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

    public function displayNumber(): ?string
    {
        return PhoneNumber::display($this->number, $this->number_normalized);
    }

    public function durationLabel(): ?string
    {
        if ($this->duration_seconds === null) {
            return null;
        }

        $minutes = intdiv($this->duration_seconds, 60);
        $seconds = $this->duration_seconds % 60;

        return $minutes === 0
            ? __(':seconds sec', ['seconds' => $seconds])
            : __(':minutes min :seconds sec', ['minutes' => $minutes, 'seconds' => $seconds]);
    }

    public function recordedBy(): string
    {
        return $this->impersonator_name === null
            ? $this->responsible_user_name
            : __(':owner, impersonating :staff', [
                'owner' => $this->impersonator_name,
                'staff' => $this->responsible_user_name,
            ]);
    }
}
