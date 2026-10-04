<?php

namespace App\Models;

use App\Enums\GrantScope;
use App\Enums\Permission;
use App\Enums\Role;
use Database\Factories\AccessGrantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * "This person holds this role, here." What and where are stored together so
 * that two grants can never combine into access neither one gives.
 *
 * @property int $id
 * @property int $user_id
 * @property Role $role
 * @property GrantScope $scope
 * @property int|null $location_id
 * @property int|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Location|null $location
 * @property-read User|null $grantedBy
 */
#[Fillable(['user_id', 'role', 'scope', 'location_id', 'granted_by'])]
class AccessGrant extends Model
{
    /** @use HasFactory<AccessGrantFactory> */
    use HasFactory;

    /**
     * Refuse to save a grant whose scope and location disagree.
     */
    protected static function booted(): void
    {
        static::saving(function (AccessGrant $grant): void {
            if ($grant->scope === GrantScope::Location && $grant->location_id === null) {
                throw new InvalidArgumentException('A location-scoped access grant must name a location.');
            }

            if ($grant->scope === GrantScope::AllLocations && $grant->location_id !== null) {
                throw new InvalidArgumentException('An all-locations access grant must not name a location.');
            }

            if ($grant->role->requiresAllLocations() && $grant->scope !== GrantScope::AllLocations) {
                throw new InvalidArgumentException("The {$grant->role->label()} role can only be granted for all locations.");
            }
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
            'role' => Role::class,
            'scope' => GrantScope::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isCompanyWide(): bool
    {
        return $this->scope === GrantScope::AllLocations;
    }

    /**
     * Determine whether this grant applies at the given location.
     */
    public function covers(Location $location): bool
    {
        return $this->isCompanyWide() || $this->location_id === $location->id;
    }

    public function allows(Permission $permission): bool
    {
        return $this->role->allows($permission);
    }
}
