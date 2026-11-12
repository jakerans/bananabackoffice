<?php

namespace App\Support\Scorecards;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who owns, submits and sees a Location's weekly scorecard
 * (docs/slice-27-leadership-scorecards-plan.md, step 27a; decision 187,
 * questions 3 and 7): its general manager, chosen in Settings > Locations;
 * until one is chosen, the Owner. The Owner and Leadership see every one;
 * nobody else does.
 */
final class ScorecardOwnership
{
    /**
     * The people who own a Location's scorecard: its chosen owner while they
     * are active, or else every active Owner.
     *
     * @return Collection<int, User>
     */
    public static function owners(Location $location): Collection
    {
        $chosen = $location->scorecard_owner_id === null ? null : User::query()->whereKey($location->scorecard_owner_id)->where('is_active', true)->first();

        if ($chosen !== null) {
            return collect([$chosen]);
        }

        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->get()
            ->filter(fn (User $user): bool => $user->holdsRole(Role::Owner))
            ->values();
    }

    public static function canSubmit(User $user, Location $location): bool
    {
        return self::owners($location)->contains('id', $user->id);
    }

    public static function canView(User $user, Location $location): bool
    {
        return self::canSubmit($user, $location) || $user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess);
    }

    /**
     * The active Locations whose scorecard a person owns.
     *
     * @return Collection<int, Location>
     */
    public static function locationsOf(User $user): Collection
    {
        return Location::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Location $location): bool => self::canSubmit($user, $location))
            ->values();
    }

    /**
     * Who may be chosen as a Location's owner: its active managers. A
     * Location still being set up counts the managers given access there, so
     * its owner can be chosen on its checklist (decision 226); a deactivated
     * one has none.
     *
     * @return Collection<int, User>
     */
    public static function candidates(Location $location): Collection
    {
        if (! $location->is_active && ! $location->isBeingSetUp()) {
            return collect();
        }

        return User::query()
            ->where('is_active', true)
            ->with('accessGrants')
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user): bool => $user->accessGrants->contains(
                fn (AccessGrant $grant): bool => $grant->role === Role::LocationManager && $grant->covers($location),
            ))
            ->values();
    }
}
