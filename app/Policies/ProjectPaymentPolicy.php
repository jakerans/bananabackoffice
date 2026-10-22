<?php

namespace App\Policies;

use App\Models\ProjectPayment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Who may see a payment's receipt and attach one
 * (docs/slice-17-customer-payments-plan.md, step 17a). Its Project decides.
 */
class ProjectPaymentPolicy
{
    public function view(User $user, ProjectPayment $payment): bool
    {
        return Gate::forUser($user)->allows('view', $payment->project);
    }

    public function recordPayments(User $user, ProjectPayment $payment): bool
    {
        return Gate::forUser($user)->allows('recordPayments', $payment->project);
    }
}
