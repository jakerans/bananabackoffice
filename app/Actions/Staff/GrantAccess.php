<?php

namespace App\Actions\Staff;

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class GrantAccess
{
    /**
     * Give a staff member a role, either at one location or across all locations.
     *
     * @throws ValidationException
     */
    public function handle(User $grantedBy, User $staff, Role $role, GrantScope $scope, ?Location $location): AccessGrant
    {
        if ($role->requiresAllLocations() && $scope !== GrantScope::AllLocations) {
            throw ValidationException::withMessages([
                'scope' => __('The :role role can only be granted for all locations.', ['role' => $role->label()]),
            ]);
        }

        // An all-locations grant never carries a location, whatever was passed in.
        $locationId = null;

        if ($scope === GrantScope::Location) {
            if ($location === null || ! $location->is_active) {
                throw ValidationException::withMessages([
                    'locationId' => __('Choose an active location.'),
                ]);
            }

            $locationId = $location->id;
        }

        $alreadyGranted = $staff->accessGrants()
            ->where('role', $role)
            ->where('scope', $scope)
            ->where('location_id', $locationId)
            ->exists();

        if ($alreadyGranted) {
            throw ValidationException::withMessages([
                'role' => __('This person already has that access.'),
            ]);
        }

        try {
            $grant = $staff->accessGrants()->create([
                'role' => $role,
                'scope' => $scope,
                'location_id' => $locationId,
                'granted_by' => $grantedBy->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two requests arrived together and the database refused the second.
            throw ValidationException::withMessages([
                'role' => __('This person already has that access.'),
            ]);
        }

        $staff->unsetRelation('accessGrants');

        return $grant;
    }
}
