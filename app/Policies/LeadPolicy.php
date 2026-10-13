<?php

namespace App\Policies;

use App\Enums\CadenceState;
use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Appointment;
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
        return $lead->status === LeadStatus::Disqualified && $this->update($user, $lead) && $this->distributes($user, $lead);
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
     * Determine whether the user can book an appointment for the Lead
     * (rulebook, rule 2): the roles that hold the booking permission where
     * the Lead is, and a Sales Consultant there only for a Lead already
     * assigned to them. A Lead with no Location cannot be booked by anyone.
     */
    public function bookAppointment(User $user, Lead $lead): bool
    {
        if ($lead->location === null || ! $this->view($user, $lead)) {
            return false;
        }

        return $user->hasPermissionAt(Permission::BookAppointments, $lead->location)
            || ($lead->isAssignedTo($user) && $user->holdsRoleAt(Role::SalesConsultant, $lead->location));
    }

    /**
     * Determine whether the user can do what the appointment rules keep for a
     * manager where the Lead is (decision 88): undo an appointment as "booked
     * in error", move it to another salesperson, send the Lead back to
     * Contact Center, and say it should never have been booked.
     */
    public function manageAppointment(User $user, Lead $lead): bool
    {
        return $lead->location !== null
            && $this->view($user, $lead)
            && $user->hasPermissionAt(Permission::ManageAppointments, $lead->location);
    }

    /**
     * Determine whether the user can give the Lead's appointment a new time
     * (rulebook, rule 6): a manager where the Lead is, or the salesperson
     * whose appointment it is, while the Lead is still theirs.
     */
    public function rescheduleAppointment(User $user, Lead $lead, Appointment $appointment): bool
    {
        if ($lead->location === null || $appointment->lead_id !== $lead->id || ! $this->view($user, $lead)) {
            return false;
        }

        return $user->hasPermissionAt(Permission::ManageAppointments, $lead->location)
            || ($appointment->salesperson_id === $user->id
                && $lead->isAssignedTo($user)
                && $user->holdsRoleAt(Role::SalesConsultant, $lead->location));
    }

    /**
     * Determine whether the user can say what happened to the Lead's
     * appointment once its time has come, run or no-show (decision 125): the
     * same people who may give it a new time.
     */
    public function closeOutAppointment(User $user, Lead $lead, Appointment $appointment): bool
    {
        return $this->rescheduleAppointment($user, $lead, $appointment);
    }

    /**
     * Determine whether the user can add a proposal to the Lead, or record
     * that one was presented at another appointment (slice 7 step 7b): a
     * manager where the Lead is, or the salesperson the Lead is with while it
     * is with Sales or shows Sold. The same people give a proposal its result,
     * change one that is still pending, and mark the Lead lost (step 7c).
     */
    public function manageProposals(User $user, Lead $lead): bool
    {
        if ($lead->location === null || ! $this->view($user, $lead)) {
            return false;
        }

        return $user->hasPermissionAt(Permission::ManageAppointments, $lead->location)
            || (($lead->isWithSales() || $lead->status === LeadStatus::Sold)
                && $lead->isAssignedTo($user)
                && $user->holdsRoleAt(Role::SalesConsultant, $lead->location));
    }

    /**
     * Determine whether the user can record that the Lead's appointment was
     * cancelled: anyone who may book that Lead (decision 107).
     */
    public function cancelAppointment(User $user, Lead $lead): bool
    {
        return $this->bookAppointment($user, $lead);
    }

    /**
     * Determine whether the user can take the Lead back from the Contact
     * Center (rulebook, rule 12; decision 120): only the salesperson the timer
     * released it from, while it is still with the Contact Center at a
     * Location where they have Sales Consultant access.
     */
    public function takeBack(User $user, Lead $lead): bool
    {
        return $lead->location !== null
            && $lead->wasReleasedFromSales()
            && $lead->released_from_user_id === $user->id
            && $this->view($user, $lead)
            && $user->holdsRoleAt(Role::SalesConsultant, $lead->location);
    }

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

    /**
     * Whether the user hands out Leads where this Lead is, whatever its status.
     */
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
