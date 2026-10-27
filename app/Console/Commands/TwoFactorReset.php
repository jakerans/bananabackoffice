<?php

namespace App\Console\Commands;

use App\Actions\Audit\RecordAuditEvent;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

use function Laravel\Prompts\text;

/**
 * Switch off a staff member's two-factor sign-in when they have lost both
 * their phone and their recovery codes (docs/slice-30-production-readiness-plan.md,
 * step 30a). They sign in with their password and, if their role must use it,
 * are sent to set it up again at once. The reset is recorded in the Audit Log
 * with its reason.
 */
#[Signature('backoffice:two-factor-reset {email : The staff member\'s email address} {--reason= : Why it is being reset}')]
#[Description('Switch off a staff member\'s two-factor sign-in so they can set it up again')]
class TwoFactorReset extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(DisableTwoFactorAuthentication $disableTwoFactorAuthentication, RecordAuditEvent $recordAuditEvent): int
    {
        $staff = User::query()->where('email', User::normalizeEmail((string) $this->argument('email')))->first();

        if (! $staff instanceof User) {
            $this->components->error('No staff member has that email address.');

            return self::FAILURE;
        }

        if ($staff->two_factor_secret === null) {
            $this->components->info("{$staff->name} has no two-factor sign-in to reset.");

            return self::SUCCESS;
        }

        $reason = trim((string) ($this->option('reason') ?? text(label: 'Why is it being reset?', required: true)));

        if ($reason === '') {
            $this->components->error('Give a reason. It is kept in the Audit Log.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($staff, $reason, $disableTwoFactorAuthentication, $recordAuditEvent): void {
            $disableTwoFactorAuthentication($staff);

            $recordAuditEvent->handle(AuditAction::StaffTwoFactorReset, $staff, reason: $reason);
        });

        $this->components->info("Two-factor sign-in is off for {$staff->name}.".($staff->mustUseTwoFactor() ? ' They will be asked to set it up again when they next sign in.' : ''));

        return self::SUCCESS;
    }
}
