<?php

namespace App\Policies;

use App\Enums\Permission;
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
}
