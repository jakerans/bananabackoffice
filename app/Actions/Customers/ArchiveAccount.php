<?php

namespace App\Actions\Customers;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveAccount
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Archive a customer, hiding it from lists, choices and suggestions.
     *
     * It is kept whole, with who archived it and when: its Contacts, Properties,
     * Leads and history are untouched, and it can be restored. A customer with
     * an active Lead, at any location, cannot be archived until that Lead is
     * dealt with. Once Projects exist, an open Project will block it too.
     *
     * The caller is responsible for checking that the person may do this. It is a
     * company-wide management action, so the person can see every Lead that may
     * be in the way and the refusal reveals nothing new to them.
     *
     * @throws ValidationException
     */
    public function archive(Account $account, User $archivedBy): void
    {
        if ($account->isArchived()) {
            return;
        }

        $activeLeads = $account->leads()->active()->count();

        if ($activeLeads > 0) {
            throw ValidationException::withMessages([
                'account' => trans_choice(
                    '{1} This customer has 1 active lead, so cannot be archived yet.|[2,*] This customer has :count active leads, so cannot be archived yet.',
                    $activeLeads,
                    ['count' => $activeLeads],
                ),
            ]);
        }

        DB::transaction(function () use ($account, $archivedBy): void {
            $account->forceFill(['archived_at' => now(), 'archived_by' => $archivedBy->id])->save();

            $this->recordAuditEvent->handle(
                AuditAction::AccountArchived,
                $account,
                actor: $archivedBy,
                before: ['customer_name' => $account->display_name],
            );
        });
    }

    /**
     * Restore an archived customer, exactly as it was.
     */
    public function restore(Account $account): void
    {
        if (! $account->isArchived()) {
            return;
        }

        DB::transaction(function () use ($account): void {
            $account->forceFill(['archived_at' => null, 'archived_by' => null])->save();

            $this->recordAuditEvent->handle(
                AuditAction::AccountRestored,
                $account,
                after: ['customer_name' => $account->display_name],
            );
        });
    }
}
