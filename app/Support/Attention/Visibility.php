<?php

namespace App\Support\Attention;

use App\Enums\Permission;
use App\Models\AttentionItem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who may see an item of Needs Attention (docs/slice-26-needs-attention-plan.md,
 * step 26b; decision 185, question 4): never one about a record the person
 * may not open. Its owner always sees it, since the rule chose them.
 */
final class Visibility
{
    /**
     * @param  Collection<int, Location>  $locations  Every Location, read once.
     */
    public static function allows(User $user, AttentionItem $item, Collection $locations): bool
    {
        if ($item->user_id === $user->id) {
            return true;
        }

        if ($item->rule === 'pay_period_open') {
            return $user->hasPermissionAcrossAllLocations(Permission::ManageCompensation);
        }

        if ($item->rule === 'scorecard_missing') {
            return $user->hasPermissionAcrossAllLocations(Permission::CompanyOverviewAccess);
        }

        $location = $locations->firstWhere('id', $item->location_id);

        if ($location === null) {
            return false;
        }

        $permission = match (explode(':', $item->subject_key)[0]) {
            'lead' => Permission::ViewLeads,
            'subcontractor' => Permission::ViewSubcontractors,
            default => Permission::ViewProjects,
        };

        return $user->hasPermissionAt($permission, $location);
    }
}
