<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Estimate;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see and build a Lead's estimates (docs/slice-31-estimating-plan.md,
 * step 31c; decision 204): as for its proposals, anyone who may see the Lead
 * sees them; the salesperson the Lead is with, or a manager where the Lead
 * is, builds them; only a manager changes an estimate's hourly rate.
 */
class EstimatePolicy
{
    public function view(User $user, Estimate $estimate): bool
    {
        return Gate::forUser($user)->allows('view', $estimate->lead);
    }

    /**
     * Start an estimate on a Lead.
     */
    public function create(User $user, Lead $lead): bool
    {
        return Gate::forUser($user)->allows('manageProposals', $lead);
    }

    public function update(User $user, Estimate $estimate): bool
    {
        return Gate::forUser($user)->allows('manageProposals', $estimate->lead);
    }

    /**
     * Change the hourly rate an estimate is priced at: a manager only (decision 204, question 4).
     */
    public function changeHourlyRate(User $user, Estimate $estimate): bool
    {
        return $this->update($user, $estimate) && $user->hasPermissionAt(Permission::ManageAppointments, $estimate->location);
    }
}
