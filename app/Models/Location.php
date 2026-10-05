<?php

namespace App\Models;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'is_active'])]
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
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
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
