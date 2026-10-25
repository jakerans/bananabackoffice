<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarrantyCost;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a warranty cost's receipt and attach one
 * (docs/slice-22-warranty-plan.md, step 22c). Like a job's costs, it is
 * finance-sensitive: those who may see payouts see it; the case's owner or
 * a manager there attaches one.
 */
class WarrantyCostPolicy
{
    public function view(User $user, WarrantyCost $cost): bool
    {
        return Gate::forUser($user)->allows('viewPayouts', $cost->project);
    }

    public function manageCosts(User $user, WarrantyCost $cost): bool
    {
        return $this->view($user, $cost) && Gate::forUser($user)->allows('manage', $cost->warrantyCase);
    }
}
