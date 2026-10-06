<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AccountType;
use App\Enums\AuditAction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateAccount
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Correct what kind of customer an Account is, or a business's name.
     *
     * - Becoming or staying a business needs a business name, which is what it
     *   is then shown by.
     * - Becoming a household needs a primary Contact, because a household is
     *   shown by that person's name. It keeps no business name.
     *
     * Nothing else is touched. The customer's Contacts, Properties, Leads and
     * history are exactly as they were, whichever way the kind is changed.
     * Saving it unchanged does nothing and records nothing.
     *
     * The caller is responsible for checking that the person may change this customer.
     *
     * @throws ValidationException
     */
    public function handle(Account $account, AccountType $type, ?string $businessName): void
    {
        LinkContactToAccount::ensureChangeable($account);

        $businessName = trim((string) $businessName);

        if ($type === AccountType::Commercial && $businessName === '') {
            throw ValidationException::withMessages([
                'accountBusinessName' => __('Enter the name of the business.'),
            ]);
        }

        if ($type === AccountType::Residential && $account->contactLinks()->notArchived()->where('is_primary', true)->doesntExist()) {
            throw ValidationException::withMessages([
                'accountType' => __('A household is named after its primary contact. Add a contact and make them primary first.'),
            ]);
        }

        $before = ['type' => $account->type->value, 'business_name' => $account->business_name, 'customer_name' => $account->display_name];

        DB::transaction(function () use ($account, $type, $businessName, $before): void {
            $account->forceFill([
                'type' => $type,
                'business_name' => $type === AccountType::Commercial ? $businessName : null,
                'business_name_normalized' => $type === AccountType::Commercial ? Account::normalizeBusinessName($businessName) : null,
            ]);

            if (! $account->isDirty(['type', 'business_name'])) {
                return;
            }

            $account->save();
            $account->refreshDisplayName();

            $after = ['type' => $account->type->value, 'business_name' => $account->business_name, 'customer_name' => $account->display_name];
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

            $this->recordAuditEvent->handle(
                AuditAction::AccountEdited,
                $account,
                before: array_intersect_key($before, array_flip($changed)),
                after: array_intersect_key($after, array_flip($changed)),
            );
        });
    }
}
