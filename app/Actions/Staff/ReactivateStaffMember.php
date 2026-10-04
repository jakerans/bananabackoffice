<?php

namespace App\Actions\Staff;

use App\Models\User;

class ReactivateStaffMember
{
    /**
     * Let a deactivated staff member sign in again with the access they had before.
     */
    public function handle(User $staff): void
    {
        $staff->forceFill(['is_active' => true])->save();
    }
}
