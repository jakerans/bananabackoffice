<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        $user->forceFill([
            'password' => $input['password'],
        ]);

        // Someone invited who sets their password this way has done what the
        // invitation asked, through a link sent to the same inbox. Treat the
        // invitation as accepted and retire its link so it cannot be used later.
        if ($user->invitationIsPending()) {
            $user->markInvitationAccepted();

            Password::broker('invitations')->deleteToken($user);
        }

        $user->save();
    }
}
