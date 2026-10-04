<?php

namespace App\Actions\Staff;

use App\Enums\GrantScope;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

class CreateOwner
{
    /**
     * Create a verified staff account holding the Owner role for all locations.
     */
    public function handle(string $name, string $email, #[SensitiveParameter] string $password): User
    {
        return DB::transaction(function () use ($name, $email, $password): User {
            $owner = new User([
                'name' => $name,
                'email' => Str::lower($email),
                'password' => $password,
            ]);

            $owner->forceFill(['email_verified_at' => now()])->save();

            $owner->accessGrants()->create([
                'role' => Role::Owner,
                'scope' => GrantScope::AllLocations,
            ]);

            return $owner;
        });
    }
}
