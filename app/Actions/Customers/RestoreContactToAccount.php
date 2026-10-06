<?php

namespace App\Actions\Customers;

use App\Enums\ContactRole;
use App\Models\AccountContact;
use App\Models\AccountContactRole;
use Illuminate\Validation\ValidationException;

class RestoreContactToAccount
{
    public function __construct(private LinkContactToAccount $linkContactToAccount) {}

    /**
     * Bring back a person who was removed from a customer, as they were.
     *
     * Their roles are kept from before. If the person had been archived because
     * this was their last connection, they are restored with it. If the customer
     * has no primary Contact, they become it.
     *
     * The caller is responsible for checking that the person may do this. It is a
     * company-wide management action.
     *
     * @throws ValidationException
     */
    public function handle(AccountContact $link): void
    {
        if (! $link->isArchived()) {
            return;
        }

        $roles = [];

        foreach ($link->roles as $held) {
            /** @var AccountContactRole $held */
            $roles[] = $held->role;
        }

        $this->linkContactToAccount->handle($link->account, $link->contact, $roles === [] ? [ContactRole::Other] : $roles);
    }
}
