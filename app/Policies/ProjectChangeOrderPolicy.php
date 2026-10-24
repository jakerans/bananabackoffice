<?php

namespace App\Policies;

use App\Models\ProjectChangeOrder;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a change order's signed copy and attach one
 * (docs/slice-18-change-orders-plan.md, step 18a). Its Project decides.
 */
class ProjectChangeOrderPolicy
{
    public function view(User $user, ProjectChangeOrder $changeOrder): bool
    {
        return Gate::forUser($user)->allows('view', $changeOrder->project);
    }

    public function manageChangeOrders(User $user, ProjectChangeOrder $changeOrder): bool
    {
        return Gate::forUser($user)->allows('manageChangeOrders', $changeOrder->project);
    }
}
