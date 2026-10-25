<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use App\Models\WarrantyCase;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and work a warranty case (docs/slice-22-warranty-plan.md;
 * decision 177). Its job decides who sees it; its owner or a manager at the
 * Location works it; a manager gives it to someone and approves its budget.
 */
class WarrantyCasePolicy
{
    public function view(User $user, WarrantyCase $case): bool
    {
        return Gate::forUser($user)->allows('view', $case->project);
    }

    /**
     * Schedule, complete, close without work, and record costs: its owner or
     * a manager there.
     */
    public function manage(User $user, WarrantyCase $case): bool
    {
        return $this->view($user, $case) && ($case->owner_id === $user->id || $this->isManagerThere($user, $case));
    }

    public function managePhotos(User $user, WarrantyCase $case): bool
    {
        return $this->manage($user, $case);
    }

    /**
     * Give it to a Production Manager, or keep it: a manager there.
     */
    public function assign(User $user, WarrantyCase $case): bool
    {
        return $this->view($user, $case) && $case->isActive() && $this->isManagerThere($user, $case);
    }

    private function isManagerThere(User $user, WarrantyCase $case): bool
    {
        return $user->hasPermissionAt(Permission::ManageProjects, $case->project->location);
    }
}
