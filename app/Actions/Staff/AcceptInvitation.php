<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use SensitiveParameter;

class AcceptInvitation
{
    /**
     * Set the invited staff member's own password, using up their invitation link.
     *
     * Receiving the link proves the email address, so the account is also marked verified.
     *
     * @return bool Whether the invitation was valid and has been accepted.
     */
    public function handle(string $email, #[SensitiveParameter] string $token, #[SensitiveParameter] string $password): bool
    {
        $email = Str::lower($email);

        if (User::query()->where('email', $email)->where('is_active', false)->exists()) {
            return false;
        }

        $status = Password::broker('invitations')->reset(
            ['email' => $email, 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                    'invitation_accepted_at' => now(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        return $status === Password::PASSWORD_RESET;
    }
}
