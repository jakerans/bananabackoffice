<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\User;

/**
 * Managing the list of locations is a company-level task. Locations are
 * deactivated rather than deleted, so there is no delete ability.
 */
class LocationPolicy
{
    /**
     * Determine whether the user can view the list of locations in Settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageLocations);
    }

    /**
     * Determine whether the user can add a location.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageLocations);
    }

    /**
     * Determine whether the user can rename, deactivate or reactivate the location.
     */
    public function update(User $user, Location $location): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageLocations);
    }
}
