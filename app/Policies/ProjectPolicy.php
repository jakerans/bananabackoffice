<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Project;
use App\Models\User;

/**
 * Access to a Project is decided by the Project's own Location, never by the
 * Location selected in the header and never by whose Project it is
 * (decision 31; product-architecture.md, 2: "Responsibility is not
 * permission").
 */
class ProjectPolicy
{
    /**
     * Determine whether the user can see the Project.
     */
    public function view(User $user, Project $project): bool
    {
        return $user->hasPermissionAt(Permission::ViewProjects, $project->location);
    }

    /**
     * Determine whether the user can change the Project's title and
     * description (decision 130).
     */
    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && $user->hasPermissionAt(Permission::ManageProjects, $project->location);
    }

    /**
     * Determine whether the user can answer or correct the handoff questions
     * (decision 132): the salesperson who sold it, or a manager where it is.
     */
    public function completeHandoff(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && ($project->salesperson_id === $user->id || $user->hasPermissionAt(Permission::ManageProjects, $project->location));
    }

    /**
     * Determine whether the user can record or correct the Brand Ambassador
     * package (slice 21, decision 175): the salesperson until the handoff is
     * complete, or a manager where it is.
     */
    public function recordAmbassadorPackage(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && (($project->salesperson_id === $user->id && ! $project->hasHandoff()) || $user->hasPermissionAt(Permission::ManageProjects, $project->location));
    }

    /**
     * Determine whether the user can choose or change the Project's
     * Production Manager (decision 130, answer 7): a manager where it is.
     */
    public function assignProductionManager(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Determine whether the user can do the pre-production work on the
     * Project (slice 10, decision 141): tick the welcome call and set the
     * rough timeframe. Its Production Manager, or a manager where it is, once
     * it has a Production Manager.
     */
    public function managePreProduction(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && $project->production_manager_id !== null
            && ($project->production_manager_id === $user->id || $user->hasPermissionAt(Permission::ManageProjects, $project->location));
    }

    /**
     * Determine whether the user can add or remove photos of the work on the
     * Project: its Production Manager, or a manager where it is
     * (docs/slice-16-document-storage-plan.md, step 16c).
     */
    public function managePhotos(User $user, Project $project): bool
    {
        return $this->managePreProduction($user, $project);
    }

    /**
     * Determine whether the user can record the Project's invoices and the
     * customer's payments, and void an invoice: its Production Manager, a
     * manager where it is, or Finance there
     * (docs/slice-17-customer-payments-plan.md; decision 162). A deposit is
     * often taken at the sale, before there is a Production Manager.
     */
    public function recordPayments(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && ($project->production_manager_id === $user->id
                || $user->hasPermissionAt(Permission::ManageProjects, $project->location)
                || $user->hasPermissionAt(Permission::FinanceAccess, $project->location));
    }

    /**
     * Determine whether the user can record and correct the Project's costs,
     * and submit its job costing: its Production Manager, or a manager where
     * it is, who may see payouts (docs/slice-19-job-costing-plan.md;
     * decision 170).
     */
    public function manageCosts(User $user, Project $project): bool
    {
        return $this->managePayouts($user, $project);
    }

    /**
     * Determine whether the user can reconcile the Project's job costing,
     * approve its final gross margin, and close or reopen it: Finance at its
     * Location (decision 170).
     */
    public function reconcileCosts(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->hasPermissionAt(Permission::FinanceAccess, $project->location);
    }

    /**
     * Determine whether the user can draft, sign off and withdraw the
     * Project's change orders: its Production Manager, or a manager where it
     * is (docs/slice-18-change-orders-plan.md; decision 166). Lowering the
     * price also needs `update`, a manager's.
     */
    public function manageChangeOrders(User $user, Project $project): bool
    {
        return $this->managePreProduction($user, $project);
    }

    /**
     * Determine whether the user can lower or waive the Project's deposit: a
     * manager where it is, with a reason (decision 162, question 4).
     */
    public function changeDeposit(User $user, Project $project): bool
    {
        return $this->update($user, $project);
    }

    /**
     * Determine whether the user can approve a cash or check payment, or
     * reverse a payment: Finance at the Project's Location (decision 133;
     * decision 162).
     */
    public function approvePayments(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->hasPermissionAt(Permission::FinanceAccess, $project->location);
    }

    /**
     * Determine whether the user can see what Banana will pay for the
     * Project's work: finance-sensitive, so it needs `payouts.view` at the
     * Project's Location (slice 13; decision 151, question 1).
     */
    public function viewPayouts(User $user, Project $project): bool
    {
        return $this->view($user, $project) && $user->hasPermissionAt(Permission::ViewPayouts, $project->location);
    }

    /**
     * Determine whether the user can set the payouts of the Project's work:
     * someone who may run its pre-production and may see payouts.
     */
    public function managePayouts(User $user, Project $project): bool
    {
        return $this->managePreProduction($user, $project) && $this->viewPayouts($user, $project);
    }

    /**
     * Determine whether the user can take the Project as its Production
     * Manager: someone with that role at the Project's own Location, when the
     * handoff is complete and nobody has it yet.
     */
    public function take(User $user, Project $project): bool
    {
        return $this->view($user, $project)
            && ! $project->isCancelled()
            && $project->production_manager_id === null
            && $project->hasHandoff()
            && $user->holdsRoleAt(Role::ProductionManager, $project->location);
    }
}
