<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

/**
 * An Account (a customer) has no location, so its rules cannot ask "is this at
 * one of the person's locations?". They ask what the person can already see:
 * see "Account::visibleTo". Seeing a customer never reveals another location's
 * Leads; those are filtered separately wherever they are shown.
 */
class AccountPolicy
{
    /**
     * Determine whether the user can see the customer, with its Contacts and Properties.
     */
    public function view(User $user, Account $account): bool
    {
        return $account->isVisibleTo($user);
    }
}
