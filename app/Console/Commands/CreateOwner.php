<?php

namespace App\Console\Commands;

use App\Actions\Staff\CreateOwner as CreateOwnerAction;
use App\Actions\Staff\SendStaffInvitation;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Role;
use App\Exceptions\InvitationNotSent;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Create an Owner. Asked with no options, it asks for a name, an email and a
 * password, in a terminal. Given --name and --email, it asks nothing and
 * emails the Owner a link to choose their own password, so it can run from
 * Forge's Commands tab (docs/slice-37-location-setup-plan.md, step 37a).
 * Given the email of an Owner whose link was never used, it sends a fresh one.
 */
#[Signature('backoffice:create-owner
    {--name= : The Owner\'s full name; with --email, they are emailed a link to choose their own password}
    {--email= : The Owner\'s email address}
    {--force : Create another Owner even if one who can sign in already exists}')]
#[Description('Create an Owner account, the only way to add the first staff member')]
class CreateOwner extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(CreateOwnerAction $createOwner, SendStaffInvitation $sendInvitation): int
    {
        $byEmail = $this->option('name') !== null || $this->option('email') !== null;

        if ($byEmail) {
            $waiting = $this->waitingOwner(User::normalizeEmail((string) $this->option('email')));

            if ($waiting !== null) {
                return $this->sendAgain($sendInvitation, $waiting);
            }
        }

        if (! $this->option('force') && User::query()->usableOwners()->exists()) {
            $this->components->error('An Owner who can sign in already exists. Invite other staff from Settings > Users and Access, or pass --force to add another Owner.');

            return self::FAILURE;
        }

        return $byEmail ? $this->createByEmail($createOwner) : $this->createByAsking($createOwner);
    }

    /**
     * Ask for everything in a terminal, the password included.
     */
    private function createByAsking(CreateOwnerAction $createOwner): int
    {
        $input = [
            'name' => text(label: 'Full name', required: true),
            'email' => User::normalizeEmail(text(label: 'Email address', required: true)),
            'password' => password(label: 'Password', required: true),
            'password_confirmation' => password(label: 'Confirm password', required: true),
        ];

        if (! $this->valid($input, ['name' => $this->nameRules(), 'email' => $this->emailRules(), 'password' => $this->passwordRules()])) {
            return self::FAILURE;
        }

        $owner = $createOwner->handle($input['name'], $input['email'], $input['password']);

        $this->components->info("Owner account created for {$owner->email}. Sign in at ".route('login'));

        return self::SUCCESS;
    }

    /**
     * Create the Owner from the options and email them their link.
     */
    private function createByEmail(CreateOwnerAction $createOwner): int
    {
        $input = [
            'name' => trim((string) $this->option('name')),
            'email' => User::normalizeEmail((string) $this->option('email')),
        ];

        if (! $this->valid($input, ['name' => $this->nameRules(), 'email' => $this->emailRules()])) {
            return self::FAILURE;
        }

        try {
            $owner = $createOwner->invite($input['name'], $input['email']);
        } catch (InvitationNotSent) {
            $this->components->error('The email could not be sent, so no Owner was created. Check the email settings, then run this again.');

            return self::FAILURE;
        }

        $this->components->info("Owner created for {$owner->email}. A link to choose a password is on its way; it works for 72 hours.");

        return self::SUCCESS;
    }

    /**
     * Send a fresh link to an Owner who never used theirs.
     */
    private function sendAgain(SendStaffInvitation $sendInvitation, User $owner): int
    {
        try {
            $sendInvitation->handle($owner);
        } catch (InvitationNotSent) {
            $this->components->error('The email could not be sent. Check the email settings, then run this again.');

            return self::FAILURE;
        }

        $this->components->info("A fresh link was sent to {$owner->email}. Any earlier link no longer works.");

        return self::SUCCESS;
    }

    /**
     * The Owner with this email whose invitation has not been used yet, if any.
     */
    private function waitingOwner(string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();

        return $user !== null && $user->isActive() && $user->invitationIsPending() && $user->holdsRole(Role::Owner) ? $user : null;
    }

    /**
     * @param  array<string, string>  $input
     * @param  array<string, mixed>  $rules
     */
    private function valid(array $input, array $rules): bool
    {
        $validator = Validator::make($input, $rules);

        foreach ($validator->errors()->all() as $message) {
            $this->components->error($message);
        }

        return $validator->errors()->isEmpty();
    }
}
