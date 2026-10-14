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
