<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\AccountContactRole;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LinkContactToAccount
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private MakePrimaryContact $makePrimaryContact,
    ) {}

    /**
     * Connect a person who already exists to a customer, with what they are to it.
     *
     * This is how one person becomes a contact of several customers without being
     * copied. They may hold several roles at once. A customer's first Contact
     * becomes its primary Contact; after that, primary is only changed on request.
     *
     * If the person was connected to this customer before and removed, that
     * connection is brought back rather than a second one made.
     *
     * The caller is responsible for checking that the person may change this
     * customer, and that they are allowed to know of this Contact.
     *
     * @param  list<ContactRole>  $roles
     *
     * @throws ValidationException
     */
    public function handle(Account $account, Contact $contact, array $roles, bool $makePrimary = false): AccountContact
    {
        self::ensureChangeable($account);

        $roles = self::ensureRoles($roles);

        $existing = $account->contactLinks()->where('contact_id', $contact->id)->first();

        if ($existing !== null && ! $existing->isArchived()) {
            throw ValidationException::withMessages([
                'contact' => __(':name is already a contact of this customer.', ['name' => $contact->fullName() ?? __('This person')]),
            ]);
        }

        return DB::transaction(function () use ($account, $contact, $roles, $makePrimary, $existing): AccountContact {
            if ($contact->isArchived()) {
                $contact->forceFill(['archived_at' => null, 'archived_by' => null])->save();
            }

            $link = $existing ?? new AccountContact;

            $link->forceFill([
                'account_id' => $account->id,
                'contact_id' => $contact->id,
                'is_primary' => false,
                'archived_at' => null,
                'archived_by' => null,
            ])->save();

            $link->setRelation('contact', $contact);
            $link->setRelation('account', $account);

            self::setRoles($link, $roles);

            $becomesPrimary = $makePrimary || ! $account->contactLinks()->notArchived()->where('is_primary', true)->exists();

            $this->recordAuditEvent->handle(
                $existing === null ? AuditAction::ContactConnected : AuditAction::ContactReconnected,
                $account,
                after: [
                    'contact_id' => $contact->id,
                    'contact' => $contact->fullName(),
                    'roles' => array_map(fn (ContactRole $role): string => $role->value, $roles),
                ],
            );

            if ($becomesPrimary) {
                $this->makePrimaryContact->handle($link);
            }

            return $link;
        });
    }

    /**
     * An archived customer is not changed until it has been restored.
     *
     * @throws ValidationException
     */
    public static function ensureChangeable(Account $account): void
    {
        if ($account->isArchived()) {
            throw ValidationException::withMessages([
                'account' => __('This customer is archived. Restore it before changing it.'),
            ]);
        }
    }

    /**
     * A person is always something to a customer: at least one role, each named once.
     *
     * @param  array<int, mixed>  $roles
     * @return list<ContactRole>
     *
     * @throws ValidationException
     */
    public static function ensureRoles(array $roles): array
    {
        $clean = [];

        foreach ($roles as $role) {
            if ($role instanceof ContactRole && ! in_array($role, $clean, true)) {
                $clean[] = $role;
            }
        }

        if ($clean === []) {
            throw ValidationException::withMessages([
                'contactRoles' => __('Choose at least one thing this person is to the customer.'),
            ]);
        }

        usort($clean, fn (ContactRole $a, ContactRole $b): int => $a->position() <=> $b->position());

        return $clean;
    }

    /**
     * Make a connection hold exactly these roles.
     *
     * @param  list<ContactRole>  $roles
     */
    public static function setRoles(AccountContact $link, array $roles): void
    {
        $wanted = array_map(fn (ContactRole $role): string => $role->value, $roles);

        $link->roles()->whereNotIn('role', $wanted)->delete();

        $held = $link->roles()->pluck('role')->map(fn (ContactRole $role): string => $role->value)->all();

        foreach (array_diff($wanted, $held) as $role) {
            (new AccountContactRole)->forceFill(['account_contact_id' => $link->id, 'role' => $role])->save();
        }

        $link->unsetRelation('roles');
    }
}
