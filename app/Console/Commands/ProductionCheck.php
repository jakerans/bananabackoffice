<?php

namespace App\Console\Commands;

use App\Models\AgreementWording;
use App\Models\Location;
use App\Models\PayPlan;
use App\Models\PriceBook;
use App\Models\ProductionRate;
use App\Models\SchedulerHeartbeat;
use App\Models\ServiceAreaPostalCode;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Locations\LocationSetup;
use App\Support\Locations\SetupStep;
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
 * it never prints a key, a password or an address with a secret in it. From
 * step 30c it also says whether what an estimate is sold from is in place,
 * since a new server starts with none of it.
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
            ['Removed files are also deleted from the copy', $this->copyCleanUpIsReady(), 'Set FILES_COPY_BUCKET and its own delete-only key.'],
            ['Email reaches customers, from a sending domain of its own', self::customerEmailIsReady(), 'Set the MAIL_CUSTOMERS_ settings and its key (docs/slice-36-agreement-copy-plan.md).'],
            ['QuickBooks uses live keys', self::quickBooksIsLive(), 'Set QUICKBOOKS_ENVIRONMENT=production and the live keys from Intuit.'],
            ...$this->readyToSell(),
        ];
    }

    /**
     * What a new server starts without: it is added once, then stays.
     *
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function readyToSell(): array
    {
        return [
            ['An Owner can sign in', $this->exists(fn (): bool => User::query()->usableOwners()->exists()), 'In Forge\'s Commands tab, run php artisan backoffice:create-owner --name="Your Name" --email=you@example.com; the Owner is emailed a link to choose a password.'],
            ['A Location is active', $this->exists(fn (): bool => Location::query()->where('is_active', true)->exists()), 'Add one in Company > Locations, with its time zone.'],
            ['Its service area is set', $this->exists(fn (): bool => ServiceAreaPostalCode::query()->exists()), 'Add its ZIP codes on its checklist, in Company > Locations > Set up.'],
            ['The default price book has prices', $this->exists(fn (): bool => ProductionRate::query()->whereIn('price_book_id', PriceBook::query()->whereNull('location_id')->select('id'))->exists()), 'Import the saved PaintScout file in Settings > Pricing Settings, or run php artisan backoffice:pricing-import with it.'],
            ['It has a tax rate', $this->exists(fn (): bool => TaxRate::query()->exists()), 'Add one in Settings > Pricing Settings.'],
            ['The agreement has its wording', $this->exists(fn (): bool => AgreementWording::current() !== null), 'Import the saved PaintScout file in Settings > Pricing Settings, or run php artisan backoffice:agreement-import with it.'],
            ['A pay plan is set', $this->exists(fn (): bool => PayPlan::query()->exists()), 'Set each person\'s pay plan in Finance > Payroll.'],
            ...$this->eachLocation(),
        ];
    }

    /**
     * For each Location not deactivated, the required steps it still lacks
     * (step 37b; decision 220).
     *
     * @return list<array{0: string, 1: bool, 2: string}>
     */
    private function eachLocation(): array
    {
        try {
            $locations = Location::query()->where(fn ($query) => $query->where('is_active', true)->orWhereNull('opened_at'))->orderBy('name')->get();
        } catch (Throwable) {
            return [];
        }

        $setup = app(LocationSetup::class);
        $checks = [];

        foreach ($locations as $location) {
            $missing = array_map(fn (SetupStep $step): string => mb_strtolower($step->label), $setup->missing($location));

            $checks[] = [
                $location->name.' has every required step',
                $missing === [],
                'Still to do on Company > Locations > '.$location->name.' > Set up: '.implode(', ', $missing).'.',
            ];
        }

        return $checks;
    }

    /**
     * @param  callable(): bool  $check
     */
    private function exists(callable $check): bool
    {
        try {
            return $check();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * What Backoffice cannot see from inside, for a person to check.
     *
     * @return list<string>
     */
    private static function byHand(): array
    {
        return [
            'Database backups run every hour and are kept 30 days, to a private bucket, with failure emails (decision 193). Forge counts backups, not days: 720.',
            'Wasabi replicates uploaded files to the copy bucket in another region, and versioning and the lifecycle rule are on both buckets.',
            'The restore drill has passed: the database, to a chosen minute, and a file with backoffice:files-check.',
            'No other key in the Wasabi account can reach production\'s buckets.',
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

    private function copyCleanUpIsReady(): bool
    {
        return filled(config('filesystems.disks.files_copy.bucket'))
            && filled(config('filesystems.disks.files_copy.key'))
            && filled(config('filesystems.disks.files_copy.secret'));
    }

    /**
     * Whether customer email is sent from a sending domain of its own (slice 36).
     */
    public static function customerEmailIsReady(): bool
    {
        return config('mail.customers.enabled') === true
            && config('mail.customers.to_anyone') === true
            && config('mail.mailers.customers.transport') === 'resend'
            && filled(config('mail.mailers.customers.key'))
            && ! str_ends_with(strtolower((string) config('mail.customers.from_address')), '@'.self::SENDING_DOMAIN);
    }

    /**
     * Whether QuickBooks uses Intuit's live keys.
     */
    public static function quickBooksIsLive(): bool
    {
        return config('services.quickbooks.environment') === 'production'
            && filled(config('services.quickbooks.client_id'))
            && filled(config('services.quickbooks.client_secret'));
    }
}
