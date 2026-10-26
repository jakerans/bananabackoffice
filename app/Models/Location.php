<?php

namespace App\Models;

use App\Support\Queue\LocationTimezones;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operating location of Banana Painting.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $timezone
 * @property int $target_margin_percent The gross margin a job there aims for (slice 19): 55 by default.
 * @property int $deposit_percent The share of the sold price asked for as a deposit (slice 17, step 17b): 50 by default.
 * @property int|null $scorecard_owner_id Who owns its weekly scorecard: its general manager (slice 27, decision 187).
 * @property int|null $nsli_target_cents Its scorecard's NSLI target.
 * @property int|null $cost_per_close_target_cents Its scorecard's cost-per-close target.
 * @property int|null $net_profit_target_percent Its scorecard's Net Profit % target (slice 28).
 * @property-read User|null $scorecardOwner
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_active', 'timezone'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Mirrors the column default so a newly created model reads as active.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'timezone' => LocationTimezones::DEFAULT,
        'deposit_percent' => self::DEFAULT_DEPOSIT_PERCENT,
        'target_margin_percent' => self::DEFAULT_TARGET_MARGIN_PERCENT,
    ];

    /** The gross margin a job aims for unless a Location sets its own (decision 170, question 4). */
    public const int DEFAULT_TARGET_MARGIN_PERCENT = 55;

    /** The deposit asked for unless a Location sets its own (product-architecture.md, 10.4). */
    public const int DEFAULT_DEPOSIT_PERCENT = 50;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'deposit_percent' => 'integer',
            'target_margin_percent' => 'integer',
            'nsli_target_cents' => 'integer',
            'net_profit_target_percent' => 'integer',
            'cost_per_close_target_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scorecardOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scorecard_owner_id');
    }

    /**
     * @return HasMany<AccessGrant, $this>
     */
    public function accessGrants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    /**
     * The postal codes this location normally serves: its service area.
     *
     * @return HasMany<ServiceAreaPostalCode, $this>
     */
    public function servicePostalCodes(): HasMany
    {
        return $this->hasMany(ServiceAreaPostalCode::class);
    }

    /**
     * The Leads that belong to this location.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Limit the query to locations that are currently operating.
     *
     * @param  Builder<Location>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
