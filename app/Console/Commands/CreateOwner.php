<?php

namespace App\Console\Commands;

use App\Actions\Staff\CreateOwner as CreateOwnerAction;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('backoffice:create-owner {--force : Create another Owner even if one who can sign in already exists}')]
#[Description('Create an Owner account, the only way to add the first staff member')]
class CreateOwner extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(CreateOwnerAction $createOwner): int
    {
        if (! $this->option('force') && User::query()->usableOwners()->exists()) {
            $this->components->error('An Owner who can sign in already exists. Invite other staff from Settings > Users and Access, or pass --force to add another Owner.');

            return self::FAILURE;
        }

        $input = [
            'name' => text(label: 'Full name', required: true),
            'email' => User::normalizeEmail(text(label: 'Email address', required: true)),
            'password' => password(label: 'Password', required: true),
            'password_confirmation' => password(label: 'Confirm password', required: true),
        ];

        $validator = Validator::make($input, [
            'name' => $this->nameRules(),
            'email' => $this->emailRules(),
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $owner = $createOwner->handle($input['name'], $input['email'], $input['password']);

        $this->components->info("Owner account created for {$owner->email}. Sign in at ".route('login'));

        return self::SUCCESS;
    }
}
