<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\Permission;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The party the company does business with: a household or a business.
 *
 * Staff see an Account as a "Customer". It has Contacts (people), Properties
 * (places) and Leads. It has no location. Who may see it is decided by the Leads
 * it has, and later its Projects: see "visibleTo".
 *
 * @property int $id
 * @property AccountType $type
 * @property string|null $business_name
 * @property string $display_name
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $archivedBy
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /** What a household is called until it has a primary Contact with a name. */
    public const string UNNAMED = 'Unnamed customer';

    /**
     * Refuse to delete an Account. It is archived instead.
     */
    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('An account is archived, never deleted.');
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
            'type' => AccountType::class,
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The Account's connections to its Contacts, each carrying roles and the primary mark.
     *
     * @return HasMany<AccountContact, $this>
     */
    public function contactLinks(): HasMany
    {
        return $this->hasMany(AccountContact::class);
    }

    /**
     * @return HasMany<Property, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /**
     * Every Lead from this customer, at every location. Always narrow this to
     * what a person may see before showing or counting them.
     *
     * @return HasMany<Lead, $this>
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'archived_by');
    }

    /**
     * Limit the query to the Accounts a staff member is allowed to see.
     *
     * An Account has no location, so there are two ways in:
     *
     * - holding the customer permission through an all-locations grant, which
     *   covers every Account; or
     * - being able to see at least one of the Account's Leads, at a location
     *   where the person also holds the customer permission.
     *
     * An Account with no Lead, or only unrouted Leads, is therefore seen by
     * company-wide staff alone. When a Lead moves away, so does this.
     *
     * Every list, search and count of Accounts starts from this.
     *
     * @param  Builder<Account>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        if ($user->hasPermissionAcrossAllLocations(Permission::ViewAccounts)) {
            return;
        }

        $locationIds = self::locationsGivingSightTo($user);

        $query->whereHas('leads', fn (Builder $leads) => $leads->whereIn('location_id', $locationIds));
    }

    /**
     * The locations where a person may see both Leads and customers: a Lead at
     * one of these is what lets a location-scoped person see its Account.
     *
     * @return list<int>
     */
    public static function locationsGivingSightTo(User $user): array
    {
        return array_values(array_intersect(
            $user->locationsWhere(Permission::ViewAccounts)->modelKeys(),
            $user->locationsWhere(Permission::ViewLeads)->modelKeys(),
        ));
    }

    /**
     * Limit the query to Accounts that have not been archived.
     *
     * @param  Builder<Account>  $query
     */
    #[Scope]
    protected function notArchived(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    public function isVisibleTo(User $user): bool
    {
        return self::query()->visibleTo($user)->whereKey($this->getKey())->exists();
    }

    /**
     * Whether a staff member may add to and edit this customer.
     *
     * The same two ways in as seeing it: the manage permission through an
     * all-locations grant, or one of the customer's Leads at a location where
     * the person can see Leads and holds both customer permissions.
     */
    public function isManageableBy(User $user): bool
    {
        if ($user->hasPermissionAcrossAllLocations(Permission::ManageAccounts)) {
            return $this->isVisibleTo($user);
        }

        $locationIds = array_values(array_intersect(
            self::locationsGivingSightTo($user),
            $user->locationsWhere(Permission::ManageAccounts)->modelKeys(),
        ));

        return $this->leads()->whereIn('location_id', $locationIds)->exists();
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isCommercial(): bool
    {
        return $this->type === AccountType::Commercial;
    }

    /**
     * The connection to the Account's primary Contact, if it has one.
     */
    public function primaryLink(): ?AccountContact
    {
        return $this->contactLinks->firstWhere('is_primary', true);
    }

    public function primaryContact(): ?Contact
    {
        return $this->primaryLink()?->contact;
    }

    /**
     * Work out what the Account should be called and save it if it has changed.
     *
     * A business is called by its business name. A household is called by its
     * primary Contact's name, so this is run whenever that could have changed.
     */
    public function refreshDisplayName(): void
    {
        $this->unsetRelation('contactLinks');

        $name = $this->isCommercial()
            ? trim((string) $this->business_name)
            : (string) $this->primaryContact()?->fullName();

        $this->display_name = $name === '' ? self::UNNAMED : $name;

        if ($this->isDirty('display_name')) {
            $this->save();
        }
    }
}
