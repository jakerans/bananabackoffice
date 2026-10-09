<?php

/*
 * Test support only. One operating-system process of the real-concurrency
 * queue tests (tests/Feature/Queue/QueueConcurrencyTest.php), started by
 * Tests\Support\QueueProcesses with Laravel's Process facade.
 *
 * It boots the real application against a database the processes genuinely
 * share, waits at a starting line so that they all begin at the same moment,
 * runs one operation through the application's own actions, and prints the
 * result as one line of JSON.
 *
 * This is never an application command or route, and it refuses to run
 * outside the testing environment.
 *
 * Usage: php tests/Support/queue-process.php <operation> <base64 JSON arguments>
 */

use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Actions\Leads\RouteLead;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Queue\ClaimNextLead;
use App\Actions\Queue\EnterLeadCadence;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    fwrite(STDERR, 'This script is test support and only runs in the testing environment.'.PHP_EOL);
    exit(2);
}

$operation = (string) ($argv[1] ?? '');
/** @var array<string, mixed> $arguments */
$arguments = json_decode((string) base64_decode((string) ($argv[2] ?? ''), true), true) ?: [];

// The processes run on the application's own database settings, unchanged.
// On SQLite that means its own transaction mode and no wait: a write that
// meets another process's write is refused at once, and the application's own
// three retries are what has to cope.

// Every process shares one fixed clock, so calling hours do not depend on
// when the tests happen to run.
if (isset($arguments['now'])) {
    Carbon::setTestNow(Carbon::parse($arguments['now'], 'UTC'));
}

$busy = new class
{
    use DetectsConcurrencyErrors;

    public function is(Throwable $exception): bool
    {
        return $this->causedByConcurrencyError($exception);
    }
};

/**
 * Every table with a numeric ID and the highest ID committed in it.
 *
 * @return array<string, int>
 */
$highestIds = function (): array {
    $highest = [];

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        if (in_array($table, ['migrations', 'jobs', 'job_batches', 'failed_jobs'], true) || ! Schema::hasColumn($table, 'id')) {
            continue;
        }

        $highest[$table] = (int) DB::table($table)->max('id');
    }

    ksort($highest);

    return $highest;
};

/**
 * Wait at the starting line: say "ready", then start at the instant written
 * to the "go" file, plus this process's own delay.
 */
$waitForStart = function () use ($arguments): ?float {
    if (! isset($arguments['line'])) {
        return null;
    }

    // Open the connection first, so connecting is not part of the race.
    DB::select('select 1');

    touch($arguments['line'].'/ready-'.$arguments['runner']);

    $deadline = microtime(true) + 60;

    while (! is_file($arguments['line'].'/go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The starting signal never came.');
        }

        usleep(200);
    }

    $startAt = (float) file_get_contents($arguments['line'].'/go') + ((int) ($arguments['delay_ms'] ?? 0)) / 1000;
    $early = $startAt - microtime(true);

    while (microtime(true) < $startAt) {
        usleep(50);
    }

    return round($early * 1000, 2);
};

$operations = [
    // Build the temporary SQLite database. MySQL is migrated by the test suite itself.
    'migrate' => function (): array {
        Artisan::call('migrate', ['--force' => true]);

        return ['migrated' => true];
    },

    'baseline' => fn (): array => ['highest' => $highestIds()],

    // Fictitious people, Locations and due Leads, committed so every process sees them.
    'setup' => function () use ($arguments): array {
        $made = ['locations' => [], 'setters' => [], 'grants' => [], 'leads' => [], 'cadences' => []];

        $made['owner'] = User::factory()->withRoleEverywhere(Role::Owner)->create()->id;

        foreach ($arguments['locations'] ?? [] as $key => $timezone) {
            $made['locations'][$key] = Location::factory()->create(['timezone' => $timezone])->id;
        }

        foreach ($arguments['setters'] ?? [] as $key => $where) {
            $setter = User::factory()->create();
            $location = $where === null ? null : Location::query()->findOrFail($made['locations'][$where]);
            $grant = AccessGrant::factory()->role(Role::CallCenterAgent);

            $made['setters'][$key] = $setter->id;
            $made['grants'][$key] = ($location === null ? $grant->everywhere() : $grant->at($location))->create(['user_id' => $setter->id])->id;
        }

        foreach ($arguments['leads'] ?? [] as $key => $where) {
            $location = $where === null ? null : Location::query()->findOrFail($made['locations'][$where]);
            $lead = ($location === null ? Lead::factory() : Lead::factory()->at($location))->create(['received_at' => now()->subMinutes(10)]);
            $cadence = app(EnterLeadCadence::class)->handle($lead);

            $cadence->forceFill(['due_at' => now()->subMinute()])->save();

            $made['leads'][$key] = $lead->id;
            $made['cadences'][$key] = $cadence->id;
        }

        return $made;
    },

    'claim' => function () use ($arguments): array {
        try {
            $claim = app(ClaimNextLead::class)->handle(User::query()->findOrFail($arguments['user']));
        } catch (AuthorizationException) {
            return ['status' => 'refused', 'lead' => null, 'ended_hold' => null];
        }

        return ['status' => $claim->status, 'lead' => $claim->lead?->id, 'already_held' => $claim->alreadyHeld, 'ended_hold' => $claim->endedHold?->value];
    },

    'route' => function () use ($arguments): array {
        app(RouteLead::class)->handle(
            Lead::query()->with('location')->findOrFail($arguments['lead']),
            Location::query()->findOrFail($arguments['location']),
            'Moved by the concurrency test.',
            User::query()->findOrFail($arguments['actor']),
        );

        return ['done' => true];
    },

    'revoke' => function () use ($arguments): array {
        Auth::setUser(User::query()->findOrFail($arguments['actor']));
        app(RevokeAccess::class)->handle(AccessGrant::query()->findOrFail($arguments['grant']));

        return ['done' => true];
    },

    'deactivate' => function () use ($arguments): array {
        Auth::setUser(User::query()->findOrFail($arguments['actor']));
        app(DeactivateStaffMember::class)->handle(User::query()->findOrFail($arguments['user']));

        return ['done' => true];
    },

    'narrow-hours' => function () use ($arguments): array {
        $saved = app(ChangeContactCenterSettings::class)->callingHours(
            User::query()->with('accessGrants')->findOrFail($arguments['actor']),
            (int) $arguments['version'],
            (int) $arguments['opens'],
            (int) $arguments['closes'],
        );

        return ['done' => $saved];
    },

    'timezone' => function () use ($arguments): array {
        Auth::setUser(User::query()->findOrFail($arguments['actor']));
        app(ChangeLocationTimezone::class)->handle(Location::query()->findOrFail($arguments['location']), $arguments['timezone']);

        return ['done' => true];
    },

    'settings-version' => fn (): array => ['version' => ContactCenterSettings::current()->version],

    // Remove settings versions a test saved, so the next round starts from the original hours.
    'reset-settings' => function () use ($arguments): array {
        return ['removed' => DB::table('contact_center_settings')->where('id', '>', (int) $arguments['above'])->delete()];
    },

    'inspect' => function () use ($arguments): array {
        $above = $arguments['above'] ?? [];

        return [
            'cadences' => DB::table('lead_cadences')
                ->where('id', '>', (int) ($above['lead_cadences'] ?? 0))
                ->orderBy('id')
                ->get(['id', 'lead_id', 'state', 'held_by_user_id', 'hold_token', 'held_until'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'events' => DB::table('lead_queue_events')
                ->where('id', '>', (int) ($above['lead_queue_events'] ?? 0))
                ->orderBy('id')
                ->get(['id', 'lead_id', 'type', 'reason', 'actor_id', 'affected_user_id', 'hold_token'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    },

    // Remove every row committed since the baseline, and say what is left.
    'cleanup' => function () use ($arguments, $highestIds): array {
        $baseline = $arguments['highest'] ?? [];

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($highestIds() as $table => $highest) {
                if ($highest > (int) ($baseline[$table] ?? 0)) {
                    DB::table($table)->where('id', '>', (int) ($baseline[$table] ?? 0))->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return ['left' => array_diff_assoc($highestIds(), array_map(intval(...), $baseline))];
    },
];

if (! isset($operations[$operation])) {
    fwrite(STDERR, "Unknown operation [{$operation}].".PHP_EOL);
    exit(2);
}

$result = ['operation' => $operation, 'runner' => $arguments['runner'] ?? null];

try {
    $result['early_ms'] = $waitForStart();
    $result += $operations[$operation]();
} catch (Throwable $exception) {
    // Reported, never hidden: a test fails on any error it did not expect.
    $result['error'] = $exception::class;
    $result['message'] = substr($exception->getMessage(), 0, 300);
    $result['busy'] = $busy->is($exception);
}

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
