<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Location;
use App\Models\Subcontractor;
use App\Models\User;

/**
 * Who may see and change a subcontractor (docs/slice-12-subcontractors-plan.md;
 * decision 148, questions 4 and 9).
 *
 * The Locations it works at decide. Production Managers and managers see the
 * subcontractors of their Locations, and change them; benching, offboarding
 * and VIP are theirs too, with a reason every time.
 */
class SubcontractorPolicy
{
    /**
     * Determine whether the user can see the subcontractor: they may see
     * subcontractors at one of the Locations it works at.
     */
    public function view(User $user, Subcontractor $subcontractor): bool
    {
        return $this->atOneOf($user, $subcontractor, Permission::ViewSubcontractors);
    }

    /**
     * Determine whether the user can change the subcontractor: its details,
     * where it stands and the VIP mark.
     */
    public function update(User $user, Subcontractor $subcontractor): bool
    {
        return $this->view($user, $subcontractor) && $this->atOneOf($user, $subcontractor, Permission::ManageSubcontractors);
    }

    private function atOneOf(User $user, Subcontractor $subcontractor, Permission $permission): bool
    {
        return $subcontractor->locations->contains(fn (Location $location): bool => $user->hasPermissionAt($permission, $location));
    }
}
