<?php

namespace App\Models;

use App\Enums\SubcontractorStanding;
use App\Support\Contact\PhoneNumber;
use Database\Factories\SubcontractorFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A business that does Banana's work: a subcontractor
 * (docs/slice-12-subcontractors-plan.md, step 12a; product-architecture.md,
 * 7; decision 148).
 *
 * The Locations it works at decide who may see it. It is made and changed
 * only by App\Actions\Subcontractors\ChangeSubcontractor, and is never
 * deleted: one who leaves is Offboarded and keeps its history. No tax ID
 * number is kept.
 *
 * @property int $id
 * @property string $business_name
 * @property string $contact_name
 * @property string|null $phone As it was typed.
 * @property string|null $phone_normalized
 * @property string|null $email
 * @property int|null $crew_size How many people are in the crew.
 * @property string|null $home_town
 * @property string|null $home_zip
 * @property SubcontractorStanding $standing
 * @property Carbon|null $standing_changed_at
 * @property bool $is_vip Set and taken off by the Production Manager or a manager, with a reason.
 * @property int|null $created_by
 * @property string|null $created_by_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Location> $locations
 * @property-read Collection<int, Service> $services
 */
class Subcontractor extends Model
{
    /** @use HasFactory<SubcontractorFactory> */
    use HasFactory;

    /** The longest a business or contact name can be. */
    public const int NAME_LENGTH = 120;

    /** The longest a home town can be. */
    public const int TOWN_LENGTH = 80;

    /** The largest crew that can be recorded. */
    public const int MAX_CREW = 200;

    /** The longest reason kept for a change. */
    public const int REASON_LENGTH = 1000;

    /**
     * Mirrors the column defaults so a new model reads as a prospect.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'standing' => 'prospect',
        'is_vip' => false,
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('A subcontractor cannot be deleted. Offboard them instead.');
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
            'standing' => SubcontractorStanding::class,
            'standing_changed_at' => 'datetime',
            'is_vip' => 'boolean',
            'crew_size' => 'integer',
        ];
    }

    /**
     * The Locations it works at.
     *
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class)->withTimestamps()->orderBy('locations.name');
    }

    /**
     * The kinds of work it does.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)->withTimestamps()->orderBy('services.position')->orderBy('services.id');
    }

    /**
     * Every compliance item received, the latest first (slice 12, step 12b).
     *
     * @return HasMany<SubcontractorComplianceRecord, $this>
     */
    public function complianceRecords(): HasMany
    {
        return $this->hasMany(SubcontractorComplianceRecord::class)->orderByDesc('id');
    }

    /**
     * The phone number as it is shown.
     */
    public function phoneForDisplay(): ?string
    {
        return PhoneNumber::display($this->phone, $this->phone_normalized);
    }

    /**
     * The home base as it is said: "Rio Rancho 87124".
     */
    public function homeBase(): ?string
    {
        $base = trim(($this->home_town ?? '').' '.($this->home_zip ?? ''));

        return $base === '' ? null : $base;
    }
}
