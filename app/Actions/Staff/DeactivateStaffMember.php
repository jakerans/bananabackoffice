<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Leads\ReleaseUnworkableAssignments;
use App\Actions\Queue\EndQueueHold;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeactivateStaffMember
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private ReleaseUnworkableAssignments $releaseUnworkableAssignments,
        private EndQueueHold $endQueueHold,
    ) {}

    /**
     * Stop a staff member signing in, and end every sign-in they already have.
     *
     * Their record and access grants are kept, so reactivating restores their
     * access, but they must sign in again: nothing from before deactivation
     * comes back to life. Leads they were responsible for become unassigned, so
     * no Lead is left with someone who cannot work it; reactivating does not
     * give those back. A Lead they hold from the call queue is taken back at
     * once.
     *
     * @throws ValidationException
     */
    public function handle(User $staff): void
    {
        if ($staff->isUsableOwner() && User::query()->usableOwners()->count() <= 1) {
            throw ValidationException::withMessages([
                'staff' => __('This is the only Owner who can sign in. Make sure another Owner has signed in before deactivating this one.'),
            ]);
        }

        DB::transaction(function () use ($staff): void {
            $wasActive = $staff->isActive();

            $staff->forceFill([
                'is_active' => false,
                // A new token makes every "remember me" cookie already issued worthless.
                'remember_token' => Str::random(60),
            ])->save();

            if ($wasActive) {
                $this->recordAuditEvent->handle(AuditAction::StaffDeactivated, $staff);
            }

            $this->releaseUnworkableAssignments->handle($staff);

            $this->endQueueHold->forStaffMember($staff);

            Password::broker('invitations')->deleteToken($staff);

            $this->endSessions($staff);
        });
    }

    /**
     * Delete the staff member's stored sessions so no browser stays signed in.
     *
     * Sessions can only be found by person when they are kept in the database,
     * which is this application's configured session store.
     */
    private function endSessions(User $staff): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $staff->getAuthIdentifier())
            ->delete();
    }
}
