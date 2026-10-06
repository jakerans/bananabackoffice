<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReactivateStaffMember
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Let a deactivated staff member sign in again with the access they had before.
     */
    public function handle(User $staff): void
    {
        if ($staff->isActive()) {
            return;
        }

        DB::transaction(function () use ($staff): void {
            $staff->forceFill(['is_active' => true])->save();

            $this->recordAuditEvent->handle(AuditAction::StaffReactivated, $staff);
        });
    }
}
