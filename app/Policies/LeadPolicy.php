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
     * Determine whether the user can send the Lead to a location.
     *
     * Routing an unrouted Lead, or moving a routed one anywhere, needs the
     * company-wide routing permission. Without it, a manager may still move a
     * Lead directly between two locations they manage: the permission is checked
     * at both ends, so it can never reach a location they do not manage.
     */
    public function route(User $user, Lead $lead, Location $destination): bool
    {
        if (! $destination->is_active || $lead->location_id === $destination->id) {
            return false;
        }

        if ($user->hasPermissionAcrossAllLocations(Permission::RouteLeads)) {
            return $this->view($user, $lead);
        }

        return $lead->location !== null
            && $user->hasPermissionAt(Permission::TransferLeads, $lead->location)
            && $user->hasPermissionAt(Permission::TransferLeads, $destination);
    }

    /**
     * Determine whether the user can send the Lead anywhere at all. Which
     * destinations they may choose is decided per destination by "route".
     */
    public function routeAnywhere(User $user, Lead $lead): bool
    {
        if ($user->hasPermissionAcrossAllLocations(Permission::RouteLeads)) {
            return $this->view($user, $lead);
        }

        return $lead->location !== null
            && $user->hasPermissionAt(Permission::TransferLeads, $lead->location)
            && $user->locationsWhere(Permission::TransferLeads)->where('id', '!=', $lead->location_id)->isNotEmpty();
    }

    /**
     * Determine whether the user can return a routed Lead to the central queue.
     */
    public function returnToCentral(User $user, Lead $lead): bool
    {
        return $lead->location !== null && $user->hasPermissionAt(Permission::ReturnLeads, $lead->location);
    }

    /**
     * Determine whether the user can decide who is responsible for the Lead.
     */
    public function assign(User $user, Lead $lead): bool
    {
        if ($lead->isUnrouted()) {
            return $user->hasPermissionAcrossAllLocations(Permission::AssignLeads)
                && $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
        }

        return $lead->location !== null && $user->hasPermissionAt(Permission::AssignLeads, $lead->location);
    }

    /**
     * Determine whether the user can start working the Lead.
     *
     * The person it is assigned to can. So can someone who may assign Leads,
     * when it is unassigned: starting work then makes it theirs. Nobody else can,
     * so starting work is never a way to pick up a Lead that was not given to you.
     */
    public function startWorking(User $user, Lead $lead): bool
    {
        if (! $this->update($user, $lead)) {
            return false;
        }

        return $lead->isAssigned() ? $lead->isAssignedTo($user) : $this->assign($user, $lead);
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
