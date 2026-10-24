<?php

namespace App\Policies;

use App\Models\ProjectCost;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a cost's receipt and attach one
 * (docs/slice-19-job-costing-plan.md). Its Project decides: those who may
 * see payouts see it; its Production Manager, a manager there or Finance
 * attaches one.
 */
class ProjectCostPolicy
{
    public function view(User $user, ProjectCost $cost): bool
    {
        return Gate::forUser($user)->allows('viewPayouts', $cost->project);
    }

    public function manageCosts(User $user, ProjectCost $cost): bool
    {
        return Gate::forUser($user)->any(['manageCosts', 'reconcileCosts'], $cost->project);
    }
}
