<?php

namespace App\Actions\Fortify;

use App\Actions\Audit\RecordAuditEvent;
use App\Concerns\PasswordValidationRules;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private RecordAuditEvent $recordAuditEvent) {}

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
        if (! $user->invitationIsPending()) {
            $user->save();

            return;
        }

        DB::transaction(function () use ($user): void {
            $user->markInvitationAccepted()->save();

            Password::broker('invitations')->deleteToken($user);

            // Nobody is signed in yet: the person accepting is the one acting.
            $this->recordAuditEvent->handle(AuditAction::StaffInvitationAccepted, $user, actor: $user);
        });
    }
}
