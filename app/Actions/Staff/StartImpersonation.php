<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use App\Support\Access\Impersonation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class StartImpersonation
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private Impersonation $impersonation,
    ) {}

    /**
     * Let an Owner view Backoffice as another staff member.
     *
     * Only an Owner who can sign in may do this, never as themselves, and only
     * as an active staff member who has accepted their invitation. It cannot be
     * started while already viewing as someone. The staff member's password is
     * not needed, shown, or changed.
     *
     * The start is recorded in the Owner's own name before anything changes.
     *
     * @throws AuthorizationException
     */
    public function handle(User $owner, User $staff): void
    {
        if ($this->impersonation->isActive() || $this->impersonation->remembered() !== null) {
            throw new AuthorizationException(__('Return to yourself before viewing Backoffice as someone else.'));
        }

        $refusal = $this->impersonation->refusalFor($owner, $staff);

        if ($refusal !== null) {
            throw new AuthorizationException($refusal);
        }

        DB::transaction(function () use ($owner, $staff): void {
            $this->recordAuditEvent->handle(AuditAction::StaffImpersonationStarted, $staff, actor: $owner);

            $this->impersonation->start($owner, $staff);
        });
    }
}
