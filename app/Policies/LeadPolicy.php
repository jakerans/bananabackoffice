<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;

/**
 * Access to a Lead is decided by the Lead's own location, never by the location
 * selected in the header. An unrouted Lead has no location, so only its own
 * company-wide permission reaches it.
 */
class LeadPolicy
{
    /**
     * Determine whether the user can see the given Lead.
     */
    public function view(User $user, Lead $lead): bool
    {
        if ($lead->isUnrouted()) {
            return $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
        }

        return $lead->location !== null && $user->hasPermissionAt(Permission::ViewLeads, $lead->location);
    }

    /**
     * Determine whether the user can capture a Lead anywhere at all.
     */
    public function create(User $user): bool
    {
        return $this->canCaptureUnrouted($user) || $user->locationsWhere(Permission::ManageLeads)->isNotEmpty();
    }

    /**
     * Determine whether the user can capture a Lead for the given location,
     * or leave it unrouted when no location is given.
     */
    public function createAt(User $user, ?Location $location): bool
    {
        return $location === null
            ? $this->canCaptureUnrouted($user)
            : $user->hasPermissionAt(Permission::ManageLeads, $location);
    }

    /**
     * Determine whether the user can work the Lead: correct it, change its
     * attribution, or move it through its lifecycle.
     */
    public function update(User $user, Lead $lead): bool
    {
        if ($lead->isUnrouted()) {
            return $this->canCaptureUnrouted($user);
        }

        return $lead->location !== null && $user->hasPermissionAt(Permission::ManageLeads, $lead->location);
    }

    /**
     * Leaving a Lead unrouted needs both the company-wide view of unrouted Leads
     * and the ability to work Leads company-wide.
     */
    private function canCaptureUnrouted(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads)
            && $user->hasPermissionAcrossAllLocations(Permission::ManageLeads);
    }
}
