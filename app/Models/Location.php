<?php

namespace App\Models;

use App\Support\Queue\LocationTimezones;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operating location of Banana Painting.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property string $timezone
 * @property int $deposit_percent The share of the sold price asked for as a deposit (slice 17, step 17b): 50 by default.
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
    ];

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
        ];
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
