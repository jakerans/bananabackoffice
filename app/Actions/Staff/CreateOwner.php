<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

class CreateOwner
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

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
}
