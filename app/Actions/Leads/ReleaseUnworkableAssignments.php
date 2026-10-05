<?php

namespace App\Actions\Leads;

use App\Enums\AssignmentEndReason;
use App\Models\Lead;
use App\Models\User;

class ReleaseUnworkableAssignments
{
    public function __construct(private UnassignLead $unassignLead) {}

    /**
     * Unassign every Lead a staff member holds but can no longer work.
     *
     * Called when someone's access is removed or their account is deactivated.
     * A Lead must not stay with a person who cannot open it: it goes back to
     * being unassigned, where a manager will see it, and the assignment is closed
     * with "access removed".
     *
     * @return int How many Leads were unassigned.
     */
    public function handle(User $staff, ?User $actor = null): int
    {
        $staff->unsetRelation('accessGrants');

        $released = 0;

        $held = Lead::query()->where('assigned_user_id', $staff->id)->with('location')->get();

        foreach ($held as $lead) {
            if ($staff->canBeAssigned($lead)) {
                continue;
            }

            $this->unassignLead->handle($lead, AssignmentEndReason::AccessRemoved, $actor);

            $released++;
        }

        return $released;
    }
}
