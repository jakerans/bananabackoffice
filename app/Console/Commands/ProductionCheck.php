<?php

namespace App\Console\Commands;

use App\Models\SchedulerHeartbeat;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Say whether this server is safe for real data
 * (docs/slice-30-production-readiness-plan.md, step 30a): each setting
 * Backoffice can see for itself, and then what only a person can check, such
 * as the backups kept by Forge. It reads settings and never changes one, and
 * it never prints a key, a password or an address with a secret in it.
 */
#[Signature('backoffice:production-check')]
#[Description('Say whether this server is safe for real data')]
class ProductionCheck extends Command
{
    /** The domain application email is sent from (decision 22). */
    public const string SENDING_DOMAIN = 'mail.bananapainting.com';

    /** How long since the scheduler's every-minute tick still counts as running. */
    public const int SCHEDULER_MINUTES = 5;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Checking whether this server is safe for real data.');

        $notReady = 0;

        foreach ($this->checks() as [$check, $ready, $fix]) {
            $this->components->twoColumnDetail($check, $ready ? '<fg=green;options=bold>ready</>' : '<fg=red;options=bold>not yet</>');

            if (! $ready) {
                $this->line('  <fg=gray>'.$fix.'</>');
                $notReady++;
            }
        }

        $this->newLine();
        $this->line('  Check by hand, in Forge, Wasabi and the restore drill (docs/slice-30-production-readiness-plan.md):');

        foreach (self::byHand() as $item) {
            $this->line('  - '.$item);
        }

        $this->newLine();

        if ($notReady > 0) {
            $this->components->error($notReady === 1 ? 'Not ready for real data: 1 setting to fix.' : "Not ready for real data: {$notReady} settings to fix.");

            return self::FAILURE;
        }

        $this->components->info('Every setting Backoffice can see is ready. Finish the checks by hand before real data goes in.');

        return self::SUCCESS;
    }

    /**
     * Each check, whether it is ready, and what to do when it is not.
     *
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function checks(): array
    {
        $database = (string) config('database.default');
        $isMySql = config("database.connections.{$database}.driver") === 'mysql';

        return [
            ['A production server', config('app.env') === 'production', 'Set APP_ENV=production.'],
            ['Debugging is off', config('app.debug') === false, 'Set APP_DEBUG=false, so an error never shows the code or its settings.'],
            ['Its address uses HTTPS', str_starts_with((string) config('app.url'), 'https://'), 'Set APP_URL to the https:// address, once its certificate is in place.'],
            ['Sign-in cookies are sent over HTTPS only', config('session.secure') === true, 'Set SESSION_SECURE_COOKIE=true.'],
            ['Sessions are kept in the database', config('session.driver') === 'database', 'Set SESSION_DRIVER=database, so a deactivated person is signed out everywhere (security-notes.md).'],
            ['Owner, Leadership and Finance must use two-factor sign-in', config('auth.two_factor_required') === true, 'Remove AUTH_REQUIRE_TWO_FACTOR, or set it to true.'],
            ['The database is MySQL', $isMySql, 'Set DB_CONNECTION=mysql.'],
            ['The database has an account of its own', $isMySql && ! in_array(config("database.connections.{$database}.username"), ['forge', 'root', '', null], true), 'Connect with an account that can reach only the Backoffice database, not the server\'s general one.'],
            ['The database keeps its change log, to put it back to any minute', $isMySql && $this->changeLogIsOn(), 'Turn on MySQL\'s binary log on the server.'],
            ['The scheduler is running', $this->schedulerIsRunning(), 'Add the scheduler in Forge (php artisan schedule:run every minute), then wait a minute.'],
            ['Email is sent through Resend from '.self::SENDING_DOMAIN, $this->emailIsReady(), 'Set MAIL_MAILER=resend, the Resend key, and MAIL_FROM_ADDRESS at '.self::SENDING_DOMAIN.'.'],
            ['Uploaded files go to production\'s private bucket', $this->fileStorageIsReady(), 'Set FILES_DRIVER=s3, the production bucket and its own key, never staging\'s.'],
            ['QuickBooks uses live keys', $this->quickBooksIsLive(), 'Set QUICKBOOKS_ENVIRONMENT=production and the live keys from Intuit.'],
        ];
    }

    /**
     * What Backoffice cannot see from inside, for a person to check.
     *
     * @return list<string>
     */
    private static function byHand(): array
    {
        return [
            'Database backups run every hour and are kept 30 days, to a private bucket, with failure emails (decision 193).',
            'Uploaded files are copied every night to a separate private bucket, kept 30 days, and the bucket\'s versioning and lifecycle rule are on.',
            'The restore drill has passed: the database, and a file with backoffice:files-check.',
            'No other key in the Wasabi account can reach production\'s bucket.',
            'Whether the repository goes back to private before real data (decision 140).',
        ];
    }

    private function changeLogIsOn(): bool
    {
        try {
            $variable = DB::selectOne("SHOW VARIABLES LIKE 'log_bin'");
        } catch (Throwable) {
            return false;
        }

        return strtoupper((string) data_get($variable, 'Value')) === 'ON';
    }

    private function schedulerIsRunning(): bool
    {
        try {
            $lastRanAt = SchedulerHeartbeat::lastRanAt(SchedulerHeartbeat::QUEUE_TICK);
        } catch (Throwable) {
            return false;
        }

        return $lastRanAt !== null && $lastRanAt->greaterThanOrEqualTo(now()->subMinutes(self::SCHEDULER_MINUTES));
    }

    private function emailIsReady(): bool
    {
        return config('mail.default') === 'resend'
            && filled(config('services.resend.key'))
            && str_ends_with(strtolower((string) config('mail.from.address')), '@'.self::SENDING_DOMAIN);
    }

    private function fileStorageIsReady(): bool
    {
        $bucket = (string) config('filesystems.disks.files.bucket');

        return config('filesystems.disks.files.driver') === 's3'
            && $bucket !== ''
            && ! str_contains(strtolower($bucket), 'staging')
            && filled(config('filesystems.disks.files.key'))
            && filled(config('filesystems.disks.files.secret'));
    }

    private function quickBooksIsLive(): bool
    {
        return config('services.quickbooks.environment') === 'production'
            && filled(config('services.quickbooks.client_id'))
            && filled(config('services.quickbooks.client_secret'));
    }
}
