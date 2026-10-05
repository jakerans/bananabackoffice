<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\ImpersonationEndReason;
use App\Models\User;
use App\Support\Access\Impersonation;
use Illuminate\Support\Facades\DB;

class StopImpersonation
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private Impersonation $impersonation,
    ) {}

    /**
     * Stop viewing Backoffice as another staff member.
     *
     * The session is the Owner's alone again from this moment. The stop is
     * recorded in the Owner's own name, with why it ended. When the session was
     * not being used as anyone, nothing happens and nothing is recorded.
     *
     * @return User|null The staff member who was being viewed as.
     */
    public function handle(ImpersonationEndReason $reason): ?User
    {
        $remembered = $this->impersonation->remembered();

        if ($remembered === null) {
            return null;
        }

        $owner = User::query()->find($remembered['impersonator_id']);
        $staff = User::query()->find($remembered['staff_id']);

        // From here the request is no longer served as anyone else, so the record below is the Owner's alone.
        $this->impersonation->forgetRequest();

        DB::transaction(function () use ($owner, $staff, $reason): void {
            if ($staff !== null) {
                $this->recordAuditEvent->handle(
                    AuditAction::StaffImpersonationStopped,
                    $staff,
                    actor: $owner,
                    after: ['cause' => $reason->value],
                );
            }

            $this->impersonation->stop();
        });

        return $staff;
    }
}
