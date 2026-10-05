<?php

namespace App\Actions\Staff;

use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class RevokeAccess
{
    /**
     * Remove an access grant, keeping at least one Owner who can sign in and manage the system.
     *
     * @throws ValidationException
     */
    public function handle(AccessGrant $grant): void
    {
        $staff = $grant->user;

        if ($grant->role === Role::Owner && $staff->isUsableOwner() && User::query()->usableOwners()->count() <= 1) {
            throw ValidationException::withMessages([
                'grant' => __('This is the only Owner who can sign in. Make sure another Owner has signed in before removing this one.'),
            ]);
        }

        $grant->delete();

        $staff->unsetRelation('accessGrants');
    }
}
