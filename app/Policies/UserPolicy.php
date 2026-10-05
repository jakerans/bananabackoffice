<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Managing staff accounts and their access is a company-level task. Accounts
 * are deactivated rather than deleted, so there is no delete ability.
 */
class UserPolicy
{
    /**
     * Determine whether the user can view the staff list in Settings.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can invite a new staff member.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can manage a staff member's access grants and invitation.
     */
    public function update(User $user, User $staff): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageUsers);
    }

    /**
     * Determine whether the user can deactivate or reactivate a staff member.
     *
     * Nobody may deactivate their own account, which prevents an accidental lockout.
     */
    public function deactivate(User $user, User $staff): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageUsers) && $user->isNot($staff);
    }

    /**
     * Determine whether the user can view Backoffice as a staff member.
     *
     * This belongs to the Owner role itself, not to a permission another role
     * could be given. An Owner cannot do it as themselves, nor as someone who is
     * deactivated or has not accepted their invitation.
     */
    public function impersonate(User $user, User $staff): bool
    {
        return $user->isUsableOwner()
            && $user->isNot($staff)
            && $staff->isActive()
            && ! $staff->invitationIsPending();
    }
}
