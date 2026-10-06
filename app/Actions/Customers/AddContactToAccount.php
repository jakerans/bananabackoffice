<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Contact;
use App\Support\Customers\ContactDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddContactToAccount
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private LinkContactToAccount $linkContactToAccount,
    ) {}

    /**
     * Add a new person to a customer, with what they are to it.
     *
     * A Contact is always created for a customer, never on its own. The person
     * needs a first or last name. Their phone and email are kept as typed with a
     * matching form beside them, and neither has to be unique.
     *
     * The caller is responsible for checking that the person may change this customer.
     *
     * @param  list<ContactRole>  $roles
     *
     * @throws ValidationException
     */
    public function handle(Account $account, ContactDetails $details, array $roles, bool $makePrimary = false): AccountContact
    {
        LinkContactToAccount::ensureChangeable($account);

        $details->ensureNamed();

        $roles = LinkContactToAccount::ensureRoles($roles);

        return DB::transaction(function () use ($account, $details, $roles, $makePrimary): AccountContact {
            $contact = new Contact;

            $contact->forceFill($details->attributes())->save();

            $this->recordAuditEvent->handle(AuditAction::ContactCreated, $contact, after: $details->forAudit());

            return $this->linkContactToAccount->handle($account, $contact, $roles, $makePrimary);
        });
    }
}
