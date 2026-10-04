<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RevokeAccess
{
    /**
     * Remove an access grant, keeping at least one active Owner.
     *
     * @throws ValidationException
     */
    public function handle(AccessGrant $grant): void
    {
        $staff = $grant->user;

        if ($grant->role === Role::Owner && $staff->isActive() && User::query()->active()->owners()->count() <= 1) {
            throw ValidationException::withMessages([
                'grant' => __('At least one active Owner is required. Make someone else an Owner first.'),
            ]);
        }

        $grant->delete();

        $staff->unsetRelation('accessGrants');
    }
}
