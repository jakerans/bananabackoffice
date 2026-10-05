<?php

namespace App\Actions\Staff;

use App\Exceptions\InvitationNotSent;
use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Throwable;

class SendStaffInvitation
{
    /**
     * Email a fresh single-use link that lets the staff member choose their own password.
     *
     * Any earlier link for the same person stops working. If the email cannot be
     * sent for any reason, nothing changes: an earlier link keeps working.
     *
     * @throws InvitationNotSent
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $token = Password::broker('invitations')->createToken($user);

            $user->forceFill(['invited_at' => now()])->save();

            try {
                $user->notify(new StaffInvitation($token));
            } catch (Throwable $exception) {
                // Mail can fail in many ways: no connection, a refused address, a malformed one.
                report($exception);

                throw new InvitationNotSent($exception);
            }
        });
    }
}
