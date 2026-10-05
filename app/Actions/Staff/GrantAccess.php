<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GrantAccess
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

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
        $grantLocation = null;

        if ($scope === GrantScope::Location) {
            if ($location === null || ! $location->is_active) {
                throw ValidationException::withMessages([
                    'locationId' => __('Choose an active location.'),
                ]);
            }

            $grantLocation = $location;
        }

        $alreadyGranted = $staff->accessGrants()
            ->where('role', $role)
            ->where('scope', $scope)
            ->where('location_id', $grantLocation?->id)
            ->exists();

        if ($alreadyGranted) {
            throw ValidationException::withMessages([
                'role' => __('This person already has that access.'),
            ]);
        }

        try {
            $grant = DB::transaction(function () use ($grantedBy, $staff, $role, $scope, $grantLocation): AccessGrant {
                $grant = $staff->accessGrants()->create([
                    'role' => $role,
                    'scope' => $scope,
                    'location_id' => $grantLocation?->id,
                    'granted_by' => $grantedBy->id,
                ]);

                $this->recordAuditEvent->handle(
                    AuditAction::StaffAccessGranted,
                    $staff,
                    actor: $grantedBy,
                    after: $grant->auditSummary(),
                    location: $grantLocation,
                );

                return $grant;
            });
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
