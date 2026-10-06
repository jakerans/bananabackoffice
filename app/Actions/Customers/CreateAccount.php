<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Support\Customers\ContactDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateAccount
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private AddContactToAccount $addContactToAccount,
    ) {}

    /**
     * Create a customer directly, without a Lead.
     *
     * A household must be given its primary Contact, because it is named after
     * that person. A business must be given its business name and may be created
     * with no Contact yet; one can be added when it is known.
     *
     * A customer with no Lead is seen by company-wide staff alone, so the caller
     * is responsible for checking that the person may create one.
     *
     * @param  list<ContactRole>  $roles  What the first Contact is to the customer. Ignored when no Contact is given.
     *
     * @throws ValidationException
     */
    public function handle(AccountType $type, ?string $businessName, ?ContactDetails $contact, array $roles = []): Account
    {
        $businessName = trim((string) $businessName);

        // Nothing typed about a person means "no contact yet", which only a business may have.
        if ($contact !== null && $contact->isEmpty()) {
            $contact = null;
        }

        if ($type === AccountType::Commercial && $businessName === '') {
            throw ValidationException::withMessages([
                'businessName' => __('Enter the name of the business.'),
            ]);
        }

        if ($type === AccountType::Residential && $contact === null) {
            throw ValidationException::withMessages([
                'contactFirstName' => __('A household needs its primary contact. Enter at least their name.'),
            ]);
        }

        if ($contact !== null) {
            $contact->ensureNamed();

            $roles = LinkContactToAccount::ensureRoles($roles);
        }

        return DB::transaction(function () use ($type, $businessName, $contact, $roles): Account {
            $account = new Account;

            $account->forceFill([
                'type' => $type,
                // A household has no business name: it is named after its primary Contact.
                'business_name' => $type === AccountType::Commercial ? $businessName : null,
                'display_name' => $type === AccountType::Commercial ? $businessName : Account::UNNAMED,
            ])->save();

            $this->recordAuditEvent->handle(
                AuditAction::AccountCreated,
                $account,
                after: ['type' => $type->value, 'business_name' => $account->business_name],
            );

            if ($contact !== null) {
                $this->addContactToAccount->handle($account, $contact, $roles, makePrimary: true);
            }

            return $account->refresh();
        });
    }
}
