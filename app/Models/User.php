<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\Role;
use App\Support\Access\LocationContext;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * A member of staff. Homeowners are never stored here; see docs/decisions.md.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property Carbon|null $invited_at
 * @property Carbon|null $invitation_accepted_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, AccessGrant> $accessGrants
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

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
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'invited_at' => 'datetime',
            'invitation_accepted_at' => 'datetime',
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
     * Limit the query to staff who have not been deactivated.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Limit the query to staff who hold the Owner role.
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function owners(Builder $query): void
    {
        $query->whereRelation('accessGrants', 'role', Role::Owner);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Determine whether the staff member was invited and has not yet chosen a password.
     */
    public function invitationIsPending(): bool
    {
        return $this->invited_at !== null && $this->invitation_accepted_at === null;
    }

    public function holdsRole(Role $role): bool
    {
        return $this->accessGrants->contains(fn (AccessGrant $grant): bool => $grant->role === $role);
    }

    /**
     * Determine whether the staff member may use a permission at one location.
     */
    public function hasPermissionAt(Permission $permission, Location $location): bool
    {
        if ($permission->isCompanyLevel()) {
            return $this->hasPermissionAcrossAllLocations($permission);
        }

        return $this->isActive()
            && $location->is_active
            && $this->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->allows($permission) && $grant->covers($location),
            );
    }

    /**
     * Determine whether the staff member holds a permission through an all-locations grant.
     */
    public function hasPermissionAcrossAllLocations(Permission $permission): bool
    {
        return $this->isActive()
            && $this->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->isCompanyWide() && $grant->allows($permission),
            );
    }

    /**
     * Determine whether the staff member may use a permission in the given location context.
     */
    public function hasPermissionIn(Permission $permission, LocationContext $context): bool
    {
        return $context->isAllLocations || $context->location === null
            ? $this->hasPermissionAcrossAllLocations($permission)
            : $this->hasPermissionAt($permission, $context->location);
    }

    /**
     * The "All Locations" view requires at least one explicit all-locations grant.
     */
    public function canViewAllLocations(): bool
    {
        return $this->isActive()
            && $this->accessGrants->contains(fn (AccessGrant $grant): bool => $grant->isCompanyWide());
    }

    /**
     * Get the active locations covered by the staff member's access grants.
     *
     * @return Collection<int, Location>
     */
    public function authorizedLocations(): Collection
    {
        if (! $this->isActive()) {
            return new Collection;
        }

        return Location::query()
            ->active()
            ->unless(
                $this->canViewAllLocations(),
                fn (Builder $query) => $query->whereKey($this->accessGrants->pluck('location_id')->filter()->unique()->all()),
            )
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
