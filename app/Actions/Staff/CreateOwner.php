<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Exceptions\InvitationNotSent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class CreateOwner
{
    public function __construct(
        private RecordAuditEvent $recordAuditEvent,
        private SendStaffInvitation $sendInvitation,
    ) {}

    /**
     * Create a verified staff account holding the Owner role for all locations.
     *
     * This runs from the command line, so the audit event has no acting person.
     */
    public function handle(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $owner = new User([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $owner->forceFill(['email_verified_at' => now()])->save();

            $grant = $owner->accessGrants()->create([
                'role' => Role::Owner,
                'scope' => GrantScope::AllLocations,
            ]);

            $this->recordAuditEvent->handle(AuditAction::StaffAccessGranted, $owner, after: $grant->auditSummary());

            return $owner;
        });
    }

    /**
     * Create an Owner who chooses their own password from an emailed link
     * (docs/slice-37-location-setup-plan.md, step 37a), so the first Owner
     * can be created from Forge's Commands tab, which cannot answer questions.
     *
     * Like any invitation, the account starts with a long random password that
     * nobody is shown, so it cannot be signed in to until the link is used.
     * It is all or nothing: if the email cannot be sent, no account is left.
     *
     * @throws InvitationNotSent
     */
    public function invite(string $name, string $email): User
    {
        return DB::transaction(function () use ($name, $email): User {
            $owner = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => Str::password(64),
            ]);

            $this->recordAuditEvent->handle(AuditAction::StaffInvited, $owner, after: ['name' => $owner->name, 'email' => $owner->email]);

            $grant = $owner->accessGrants()->create([
                'role' => Role::Owner,
                'scope' => GrantScope::AllLocations,
            ]);

            $this->recordAuditEvent->handle(AuditAction::StaffAccessGranted, $owner, after: $grant->auditSummary());

            $this->sendInvitation->handle($owner);

            return $owner;
        });
    }
}
