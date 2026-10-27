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
use Illuminate\Database\Eloquent\Casts\Attribute;
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
 * @property string|null $payroll_id Their ID in payroll, set by Finance (decision 173).
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property bool $queue_manager_view Whether a Call Center Agent may see the manager part of the call queue.
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
        'queue_manager_view' => false,
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
            'queue_manager_view' => 'boolean',
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
     * Put an email address into the one form the application stores and compares.
     *
     * Sign-in lowercases what is typed, so an address stored with a capital letter
     * could never be matched on a database that compares text exactly.
     */
    public static function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    /**
     * Store every email address in its normalized form, whatever wrote it.
     *
     * @return Attribute<string, string>
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (string $value): string => self::normalizeEmail($value),
        );
    }

    /**
     * Limit the query to Owners who can actually sign in and manage the system.
     *
     * Holding an Owner grant is not enough: the account must be active, must have
     * a confirmed email address (management pages require one), and must have a
     * password its owner chose (an unaccepted invitation has none).
     *
     * @param  Builder<User>  $query
     */
    #[Scope]
    protected function usableOwners(Builder $query): void
    {
        $query
            ->where('is_active', true)
            ->whereNotNull('email_verified_at')
            ->where(fn (Builder $query) => $query->whereNull('invited_at')->orWhereNotNull('invitation_accepted_at'))
            ->whereRelation('accessGrants', 'role', Role::Owner);
    }

    /**
     * Determine whether this person is an Owner who can actually sign in and manage the system.
     */
    public function isUsableOwner(): bool
    {
        return $this->isActive()
            && $this->hasVerifiedEmail()
            && ! $this->invitationIsPending()
            && $this->holdsRole(Role::Owner);
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

    /**
     * Record that the person has chosen their own password through a link sent to their inbox.
     *
     * That delivery also proves the email address, so it is marked as confirmed.
     */
    public function markInvitationAccepted(): static
    {
        return $this->forceFill([
            'invitation_accepted_at' => now(),
            'email_verified_at' => $this->email_verified_at ?? now(),
        ]);
    }

    public function holdsRole(Role $role): bool
    {
        return $this->accessGrants->contains(fn (AccessGrant $grant): bool => $grant->role === $role);
    }

    /**
     * Determine whether the staff member must use two-factor sign-in, because
     * they hold Owner, Leadership or Finance anywhere (decision 193, question 3).
     */
    public function mustUseTwoFactor(): bool
    {
        return config('auth.two_factor_required')
            && $this->accessGrants->contains(fn (AccessGrant $grant): bool => $grant->role->requiresTwoFactor());
    }

    /**
     * Determine whether the staff member must set up two-factor sign-in before using Backoffice.
     */
    public function mustSetUpTwoFactor(): bool
    {
        return $this->mustUseTwoFactor() && ! $this->hasEnabledTwoFactorAuthentication();
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
     * Get the active locations where the staff member may use a location-level permission.
     *
     * @return Collection<int, Location>
     */
    public function locationsWhere(Permission $permission): Collection
    {
        return $this->authorizedLocations()
            ->filter(fn (Location $location): bool => $this->hasPermissionAt($permission, $location))
            ->values();
    }

    /**
     * The pay plans this staff member has been on (slice 20).
     *
     * @return HasMany<PayPlan, $this>
     */
    public function payPlans(): HasMany
    {
        return $this->hasMany(PayPlan::class);
    }

    /**
     * Their variable-compensation ledger (slice 20).
     *
     * @return HasMany<CompensationEntry, $this>
     */
    public function compensationEntries(): HasMany
    {
        return $this->hasMany(CompensationEntry::class);
    }

    /**
     * Whether they have pay in Backoffice to see: a pay plan or a ledger entry.
     */
    public function hasPay(): bool
    {
        return $this->payPlans()->exists() || $this->compensationEntries()->exists();
    }

    /**
     * The Leads this staff member is responsible for working now.
     *
     * @return HasMany<Lead, $this>
     */
    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_user_id');
    }

    /**
     * Determine whether one of the staff member's grants gives them a role at a location.
     */
    public function holdsRoleAt(Role $role, Location $location): bool
    {
        return $this->isActive()
            && $location->is_active
            && $this->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->role === $role && $grant->covers($location),
            );
    }

    /**
     * Determine whether the staff member may see and use the manager part of
     * the call queue for Leads at a location (decisions 71 and 85).
     *
     * It belongs to people who may assign Leads there. Where that comes from
     * Call Center Agent access, it also needs the switch Leadership sets for
     * that one person. Any other role that may assign is not affected by it.
     */
    public function managesQueueAt(Location $location): bool
    {
        return $this->isActive()
            && $location->is_active
            && $this->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->covers($location) && $this->managesQueueThrough($grant),
            );
    }

    /**
     * The same, through an all-locations grant: the rule for unrouted Leads.
     */
    public function managesQueueAcrossAllLocations(): bool
    {
        return $this->isActive()
            && $this->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->isCompanyWide() && $this->managesQueueThrough($grant),
            );
    }

    private function managesQueueThrough(AccessGrant $grant): bool
    {
        return $grant->allows(Permission::AssignLeads)
            && ($grant->role !== Role::CallCenterAgent || $this->queue_manager_view);
    }

    /**
     * Determine whether the staff member may be made responsible for a Lead.
     *
     * Only someone who can already work the Lead may be assigned it: assignment
     * never grants access. An unrouted Lead can only be worked by company-wide
     * lead staff. An account that cannot sign in yet cannot hold work.
     */
    public function canBeAssigned(Lead $lead): bool
    {
        if (! $this->isActive() || $this->invitationIsPending()) {
            return false;
        }

        if ($lead->isUnrouted()) {
            return $this->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads)
                && $this->hasPermissionAcrossAllLocations(Permission::ManageLeads);
        }

        return $lead->location !== null && $this->hasPermissionAt(Permission::ManageLeads, $lead->location);
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
