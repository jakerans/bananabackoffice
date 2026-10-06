<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Exceptions\InvitationNotSent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InviteStaffMember
{
    public function __construct(
        private SendStaffInvitation $sendInvitation,
        private RecordAuditEvent $recordAuditEvent,
    ) {}

    /**
     * Create a staff account and email its owner a link to choose a password.
     *
     * The account starts with a long random password that nobody is shown, so it
     * cannot be signed in to until the invitation is accepted. Inviting is all
     * or nothing: if the email cannot be sent, no account is left behind.
     *
     * @throws InvitationNotSent
     */
    public function handle(string $name, string $email): User
    {
        return DB::transaction(function () use ($name, $email): User {
            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(64),
            ]);

            // Recorded before the email goes out: an email cannot be taken back,
            // so everything that could still fail happens first.
            $this->recordAuditEvent->handle(
                AuditAction::StaffInvited,
                $user,
                after: ['name' => $user->name, 'email' => $user->email],
            );

            $this->sendInvitation->handle($user);

            return $user;
        });
    }
}
