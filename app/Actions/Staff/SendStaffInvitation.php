<?php

namespace App\Actions\Staff;

use App\Models\User;
use App\Notifications\StaffInvitation;
use Illuminate\Support\Facades\Password;

class SendStaffInvitation
{
    /**
     * Email a fresh single-use link that lets the staff member choose their own password.
     *
     * Any earlier link for the same person stops working.
     */
    public function handle(User $user): void
    {
        $token = Password::broker('invitations')->createToken($user);

        $user->forceFill(['invited_at' => now()])->save();

        $user->notify(new StaffInvitation($token));
    }
}
