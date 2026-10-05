<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RevokeAccess
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Remove an access grant, keeping at least one Owner who can sign in and manage the system.
     *
     * The grant itself is deleted, so the audit event keeps what it was.
     *
     * @throws ValidationException
     */
    public function handle(AccessGrant $grant): void
    {
        $staff = $grant->user;

        if ($grant->role === Role::Owner && $staff->isUsableOwner() && User::query()->usableOwners()->count() <= 1) {
            throw ValidationException::withMessages([
                'grant' => __('This is the only Owner who can sign in. Make sure another Owner has signed in before removing this one.'),
            ]);
        }

        DB::transaction(function () use ($grant, $staff): void {
            $removed = $grant->auditSummary();
            $location = $grant->location;

            $grant->delete();

            $this->recordAuditEvent->handle(
                AuditAction::StaffAccessRevoked,
                $staff,
                before: $removed,
                location: $location,
            );
        });

        $staff->unsetRelation('accessGrants');
    }
}
