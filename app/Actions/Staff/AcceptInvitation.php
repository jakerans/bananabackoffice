<?php

namespace App\Actions\Staff;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

class AcceptInvitation
{
    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

    /**
     * Set the invited staff member's own password, using up their invitation link.
     *
     * Receiving the link proves the email address, so the account is also marked verified.
     *
     * @return bool Whether the invitation was valid and has been accepted.
     */
    public function handle(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): bool
    {
        $email = User::normalizeEmail($email);

        if (User::query()->where('email', $email)->where('is_active', false)->exists()) {
            return false;
        }

        $status = Password::broker('invitations')->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->markInvitationAccepted()->save();

                    // Nobody is signed in yet: the person accepting is the one acting.
                    $this->recordAuditEvent->handle(AuditAction::StaffInvitationAccepted, $user, actor: $user);
                });

                event(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET;
    }
}
