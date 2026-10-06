<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\AccountContact;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RemoveContactFromAccount
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Remove a person from a customer.
     *
     * The connection is archived, never deleted, so it can be restored. The
     * person and their details are untouched, and they stay a contact of any
     * other customer. Only when this was their last connection is the person
     * archived too, since nobody could reach them any more.
     *
     * Refused when it would leave the customer in a state it may not be in:
     *
     * - the primary Contact, while the customer has other Contacts: choose
     *   another primary first;
     * - the last Contact of a household, which must always have one;
     * - a Contact an active Lead of this customer points at.
     *
     * The caller is responsible for checking that the person may do this. It is a
     * company-wide management action.
     *
     * @throws ValidationException
     */
    public function handle(AccountContact $link, User $removedBy): void
    {
        $account = $link->account;
        $contact = $link->contact;

        LinkContactToAccount::ensureChangeable($account);

        if ($link->isArchived()) {
            return;
        }

        $others = $account->contactLinks()->notArchived()->whereKeyNot($link->id)->count();

        if ($link->is_primary && $others > 0) {
            throw ValidationException::withMessages([
                'contact' => __(':name is the primary contact. Make someone else primary first.', ['name' => $contact->fullName() ?? __('This person')]),
            ]);
        }

        if ($others === 0 && ! $account->isCommercial()) {
            throw ValidationException::withMessages([
                'contact' => __('A household always has at least one contact. Add another person before removing this one.'),
            ]);
        }

        $activeLeads = $account->leads()->active()->where('contact_id', $contact->id)->count();

        if ($activeLeads > 0) {
            throw ValidationException::withMessages([
                'contact' => trans_choice(
                    '{1} :name is the contact on 1 active lead for this customer, so cannot be removed yet.|[2,*] :name is the contact on :count active leads for this customer, so cannot be removed yet.',
                    $activeLeads,
                    ['name' => $contact->fullName() ?? __('This person'), 'count' => $activeLeads],
                ),
            ]);
        }

        DB::transaction(function () use ($link, $account, $contact, $removedBy): void {
            $roles = $link->roleLabels();

            $link->forceFill([
                'is_primary' => false,
                'archived_at' => now(),
                'archived_by' => $removedBy->id,
            ])->save();

            $stillConnected = $contact->accountLinks()->whereNull('archived_at')->exists();

            if (! $stillConnected) {
                $contact->forceFill(['archived_at' => now(), 'archived_by' => $removedBy->id])->save();
            }

            $account->refreshDisplayName();

            $this->recordAuditEvent->handle(
                AuditAction::ContactDisconnected,
                $account,
                actor: $removedBy,
                before: ['contact_id' => $contact->id, 'contact' => $contact->fullName(), 'roles' => $roles],
                after: ['contact_archived' => ! $stillConnected],
            );
        });
    }
}
