<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class DeactivateStaffMember
{
    /**
     * Stop a staff member signing in, keeping their record and at least one active Owner.
     *
     * Their access grants are kept so that reactivating restores them as they were.
     *
     * @throws ValidationException
     */
    public function handle(User $staff): void
    {
        if ($staff->isActive() && $staff->holdsRole(Role::Owner) && User::query()->active()->owners()->count() <= 1) {
            throw ValidationException::withMessages([
                'staff' => __('At least one active Owner is required. Make someone else an Owner first.'),
            ]);
        }

        $staff->forceFill(['is_active' => false])->save();

        Password::broker('invitations')->deleteToken($staff);
    }
}
