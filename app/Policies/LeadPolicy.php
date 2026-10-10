<?php

namespace App\Policies;

use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Models\Lead;
use App\Models\LeadCadence;
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
        if (! $lead->isActive() || ! $destination->is_active || $lead->location_id === $destination->id) {
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
        // A disqualified Lead is reopened before it is routed anywhere.
        if (! $lead->isActive()) {
            return false;
        }

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
        return $lead->isActive() && $lead->location !== null && $user->hasPermissionAt(Permission::ReturnLeads, $lead->location);
    }

    /**
     * Determine whether the user can decide who is responsible for the Lead.
     *
     * Nobody is responsible for a disqualified Lead, so it is reopened first.
     */
    public function assign(User $user, Lead $lead): bool
    {
        return $lead->isActive() && $this->distributes($user, $lead);
    }

    /**
     * Determine whether the user can move the Lead through its lifecycle:
     * qualify it, move it to or from nurture, or disqualify it.
     *
     * The person it is assigned to can. So can anyone who hands out Leads where
     * it is. Nobody else, so a Sales Consultant acts only on their own Leads.
     */
    public function changeStatus(User $user, Lead $lead): bool
    {
        if (! $lead->isActive() || ! $this->update($user, $lead)) {
            return false;
        }

        return $lead->isAssignedTo($user) || $this->distributes($user, $lead);
    }

    /**
     * Determine whether the user can reopen a disqualified Lead, or correct why
     * it was disqualified.
     *
     * A disqualified Lead has nobody assigned, and a reopened one comes back
     * unassigned, so this belongs to the people who hand out Leads.
     */
    public function reopen(User $user, Lead $lead): bool
    {
        return ! $lead->isActive() && $this->update($user, $lead) && $this->distributes($user, $lead);
    }

    /**
     * Determine whether the user can put the Lead back in the call queue.
     *
     * It belongs to the people who hand out Leads where it is; a Call Center
     * Agent also needs their manager-view switch (decision 85). The Lead must
     * have a cadence that has left the queue, and be New, Working or
     * Qualified: a disqualified Lead is reopened first and a Deferred
     * Follow-up is resumed first.
     */
    public function returnToQueue(User $user, Lead $lead): bool
    {
        if (! in_array($lead->status, [LeadStatus::New, LeadStatus::Working, LeadStatus::Qualified], true) || ! $this->managesQueue($user, $lead)) {
            return false;
        }

        $state = LeadCadence::query()->where('lead_id', $lead->id)->value('state');

        return $state instanceof CadenceState && $state !== CadenceState::InQueue;
    }

    /**
     * Determine whether the user can see the Lead's place in the call queue:
     * its cadence state and marker, and its queue history. It belongs to the
     * people who hand out Leads where the Lead is, like Put back in queue; a
     * Call Center Agent also needs their manager-view switch (decision 85).
     */
    public function manageQueue(User $user, Lead $lead): bool
    {
        return $this->view($user, $lead) && $this->managesQueue($user, $lead);
    }

    /**
     * Legacy permission lookup retained with existing role grants. No current
     * lifecycle action accepts a contact-wait waiver or nonresponse closure.
     */
    public function waiveContactWait(User $user, Lead $lead): bool
    {
        return $lead->location === null
            ? $user->hasPermissionAcrossAllLocations(Permission::WaiveContactWait)
            : $user->hasPermissionAt(Permission::WaiveContactWait, $lead->location);
    }

    /**
     * Whether the user hands out Leads where this Lead is, whatever its status.
     */
    /**
     * Whether the person has the manager part of the call queue where this
     * Lead is: they hand out Leads there, and a Call Center Agent also needs
     * the switch Leadership sets for them (decision 85).
     */
    private function managesQueue(User $user, Lead $lead): bool
    {
        if ($lead->isUnrouted()) {
            return $user->managesQueueAcrossAllLocations()
                && $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
        }

        return $lead->location !== null && $user->managesQueueAt($lead->location);
    }

    private function distributes(User $user, Lead $lead): bool
    {
        if ($lead->isUnrouted()) {
            return $user->hasPermissionAcrossAllLocations(Permission::AssignLeads)
                && $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
        }

        return $lead->location !== null && $user->hasPermissionAt(Permission::AssignLeads, $lead->location);
    }

    /**
     * Determine whether the user can say which customer the Lead is from: link
     * it to a customer, create one from it, or change or remove that link.
     *
     * It needs the Lead to be theirs to work, and the customer permission where
     * the Lead is. For an unrouted Lead that means company-wide.
     */
    public function resolveCustomer(User $user, Lead $lead): bool
    {
        if (! $this->update($user, $lead)) {
            return false;
        }

        return $lead->location === null
            ? $user->hasPermissionAcrossAllLocations(Permission::ManageAccounts)
            : $user->hasPermissionAt(Permission::ManageAccounts, $lead->location);
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
