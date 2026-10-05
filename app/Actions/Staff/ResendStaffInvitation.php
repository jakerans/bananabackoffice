<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\InvitationNotSent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ResendStaffInvitation
{
    public function __construct(
        private SendStaffInvitation $sendInvitation,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Email an invited staff member a fresh link, which cancels the one sent before.
     *
     * Replacing someone's way into their account is a security action, so it is
     * recorded: who did it, for whom, and when. The link itself is never recorded.
     * If the email cannot be sent, nothing changes and nothing is recorded.
     *
     * @throws InvitationNotSent
     */
    public function handle(User $staff): void
    {
        DB::transaction(function () use ($staff): void {
            $this->recordAuditEvent->handle(AuditAction::StaffInvitationResent, $staff);

            $this->sendInvitation->handle($staff);
        });
    }
}
