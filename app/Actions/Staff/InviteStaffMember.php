<?php

namespace App\Actions\Staff;

use App\Models\User;
use Illuminate\Support\Str;

class InviteStaffMember
{
    public function __construct(private SendStaffInvitation $sendInvitation) {}

    /**
     * Create a staff account and email its owner a link to choose a password.
     *
     * The account starts with a long random password that nobody is shown, so it
     * cannot be signed in to until the invitation is accepted.
     */
    public function handle(string $name, string $email): User
    {
        $user = User::query()->create([
            'name' => $name,
            'email' => Str::lower($email),
            'password' => Str::password(64),
        ]);

        $this->sendInvitation->handle($user);

        return $user;
    }
}
