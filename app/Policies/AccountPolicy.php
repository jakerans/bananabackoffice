<?php

namespace App\Policies;

use App\Enums\Permission;
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

    /**
     * Determine whether the user can create a customer that has no Lead.
     *
     * Such a customer is visible to company-wide staff alone, so only they may
     * create one. Location staff reach a customer through a Lead.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionAcrossAllLocations(Permission::ManageAccounts);
    }

    /**
     * Determine whether the user can add and edit the customer's Contacts and Properties.
     *
     * An archived customer is not changed until it has been restored.
     */
    public function update(User $user, Account $account): bool
    {
        return ! $account->isArchived() && $account->isManageableBy($user);
    }

    /**
     * Determine whether the user can remove or restore the customer's shared
     * records: a Contact's connection to it, or one of its Properties.
     *
     * These records are shared by every location that deals with the customer,
     * so this is a company-wide management action. It counts only through an
     * all-locations grant, which also means the person can see every Lead that
     * might be in the way, so a refusal never reveals another location's work.
     */
    public function archive(User $user, Account $account): bool
    {
        return ! $account->isArchived()
            && $user->hasPermissionAcrossAllLocations(Permission::ArchiveAccounts)
            && $account->isVisibleTo($user);
    }
}
