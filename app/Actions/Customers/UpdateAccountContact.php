<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Models\AccountContact;
use App\Support\Customers\ContactDetails;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccountContact
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Correct a Contact's details, and what they are to this customer.
     *
     * The two are different kinds of change. A person's name, phone and email
     * belong to the person, so a correction applies wherever they are a contact,
     * and any household named after them is renamed. What they are to this
     * customer (their roles) belongs to this connection only.
     *
     * Each kind is recorded only if it changed.
     *
     * The caller is responsible for checking that the person may change this customer.
     *
     * @param  list<ContactRole>  $roles
     *
     * @throws ValidationException
     */
    public function handle(AccountContact $link, ContactDetails $details, array $roles): void
    {
        $account = $link->account;
        $contact = $link->contact;

        LinkContactToAccount::ensureChangeable($account);

        if ($link->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => __('This person is no longer a contact of this customer.'),
            ]);
        }

        $details->ensureNamed();

        $roles = LinkContactToAccount::ensureRoles($roles);

        DB::transaction(function () use ($link, $account, $contact, $details, $roles): void {
            $before = ContactDetails::ofContact($contact)->forAudit();
            $after = $details->forAudit();
            $changed = array_keys(array_diff_assoc($after, $before) + array_diff_assoc($before, $after));

            if ($changed !== []) {
                $contact->forceFill($details->attributes())->save();

                $this->recordAuditEvent->handle(
                    AuditAction::ContactDetailsEdited,
                    $contact,
                    before: array_intersect_key($before, array_flip($changed)),
                    after: array_intersect_key($after, array_flip($changed)),
                );

                // A household is named after its primary Contact, wherever this person is one.
                Account::query()
                    ->whereHas('contactLinks', fn ($links) => $links->where('contact_id', $contact->id)->where('is_primary', true))
                    ->get()
                    ->each(fn (Account $named) => $named->refreshDisplayName());

                $account->refresh();
            }

            $rolesBefore = $link->roles()->pluck('role')->map(fn (ContactRole $role): string => $role->value)->sort()->values()->all();
            $rolesAfter = collect($roles)->map(fn (ContactRole $role): string => $role->value)->sort()->values()->all();

            if ($rolesBefore !== $rolesAfter) {
                LinkContactToAccount::setRoles($link, $roles);

                $this->recordAuditEvent->handle(
                    AuditAction::ContactRolesChanged,
                    $account,
                    before: ['contact_id' => $contact->id, 'contact' => $contact->fullName(), 'roles' => $rolesBefore],
                    after: ['contact_id' => $contact->id, 'contact' => $contact->fullName(), 'roles' => $rolesAfter],
                );
            }
        });
    }
}
