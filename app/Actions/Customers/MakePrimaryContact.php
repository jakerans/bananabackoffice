<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AccountContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MakePrimaryContact
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Make one of a customer's Contacts its primary Contact.
     *
     * A customer has one primary Contact at most, so whoever was primary stops
     * being so in the same step. A household is named after its primary Contact,
     * so its name is brought up to date. Being primary is a mark on the
     * connection; it is not one of the person's roles.
     *
     * Choosing the Contact who is already primary changes nothing.
     *
     * @throws ValidationException
     */
    public function handle(AccountContact $link): void
    {
        $account = $link->account;

        LinkContactToAccount::ensureChangeable($account);

        if ($link->isArchived() || $link->contact->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => __('Only a current contact of this customer can be its primary contact.'),
            ]);
        }

        if ($link->is_primary) {
            return;
        }

        DB::transaction(function () use ($link, $account): void {
            $previous = $account->contactLinks()->where('is_primary', true)->with('contact')->first();
            $previousName = $account->display_name;

            // The earlier primary is cleared first: the database allows only one at a time.
            $account->contactLinks()->where('is_primary', true)->update(['is_primary' => false]);

            $link->forceFill(['is_primary' => true])->save();

            $account->refreshDisplayName();

            $this->recordAuditEvent->handle(
                AuditAction::PrimaryContactChanged,
                $account,
                before: ['primary_contact' => $previous?->contact->fullName(), 'customer_name' => $previousName],
                after: ['primary_contact' => $link->contact->fullName(), 'customer_name' => $account->display_name],
            );
        });
    }
}
