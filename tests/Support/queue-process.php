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

use App\Actions\Appointments\BookAppointment;
use App\Actions\Appointments\RescheduleAppointment;
use App\Actions\ContactCenter\ChangeContactCenterSettings;
use App\Actions\Leads\DisqualifyLead;
use App\Actions\Leads\MoveLeadToNurture;
use App\Actions\Leads\RecordManualContactAttempt;
use App\Actions\Leads\ReturnLeadToCentral;
use App\Actions\Leads\RouteLead;
use App\Actions\Locations\ChangeLocationTimezone;
use App\Actions\Locations\DeactivateLocation;
use App\Actions\Queue\ClaimNextLead;
use App\Actions\Queue\EnterLeadCadence;
use App\Actions\Queue\RunQueueTick;
use App\Actions\Staff\DeactivateStaffMember;
use App\Actions\Staff\RevokeAccess;
use App\Enums\ContactAttemptChannel;
use App\Enums\ContactAttemptDirection;
use App\Enums\ContactAttemptDisposition;
use App\Enums\Role;
use App\Models\AccessGrant;
use App\Models\Account;
use App\Models\ContactCenterSettings;
use App\Models\Lead;
use App\Models\LeadDisqualificationReason;
use App\Models\Location;
use App\Models\Property;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        // Notices have a text ID, so they cannot be told apart by "higher than": cleanup removes them by their person.
        if (in_array($table, ['migrations', 'jobs', 'job_batches', 'failed_jobs', 'notifications'], true) || ! Schema::hasColumn($table, 'id')) {
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

/*
 * "fail_on_insert": the first insert into the named table is answered with
 * the error MySQL gives the loser of a deadlock, after the row was written.
 * The transaction it belongs to is really rolled back, so what follows shows
 * whether the action can be run again from the start and save once.
 */
$deadlocksMet = 0;

if (isset($arguments['fail_on_insert'])) {
    DB::listen(function (QueryExecuted $query) use (&$deadlocksMet, $arguments): void {
        if ($deadlocksMet === 0 && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, $arguments['fail_on_insert'])) {
            $deadlocksMet++;

            throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
        }
    });
}

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
            $lead = $location === null ? Lead::factory() : Lead::factory()->at($location);

            // "assigned": the Leads are being worked by the Owner, as a Lead that is moved or followed up usually is.
            if ($arguments['assigned'] ?? false) {
                $lead = $lead->assignedTo(User::query()->findOrFail($made['owner']))->working();
            }

            $lead = $lead->create(['received_at' => now()->subMinutes(10)]);
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

    // Fictitious salespeople and Leads an appointment can be booked for, committed so every process sees them.
    'setup-bookings' => function () use ($arguments): array {
        $location = Location::factory()->create(['timezone' => $arguments['timezone']]);
        $made = ['location' => $location->id, 'salespeople' => [], 'leads' => []];

        $made['owner'] = User::factory()->withRoleEverywhere(Role::Owner)->create()->id;

        foreach ($arguments['salespeople'] as $key) {
            $made['salespeople'][$key] = User::factory()->withRoleAt(Role::SalesConsultant, $location)->create()->id;
        }

        foreach ($arguments['leads'] as $key) {
            $account = Account::factory()->withPrimaryContact()->create();
            $property = Property::factory()->of($account)->create();

            $made['leads'][$key] = Lead::factory()->at($location)->from($account, $account->primaryContact(), $property)->qualified()->create()->id;
        }

        return $made;
    },

    // One person books one appointment. A refusal the person would be shown is an answer, not an error.
    'book' => function () use ($arguments): array {
        $actor = User::query()->findOrFail($arguments['actor']);

        Auth::setUser($actor);

        try {
            $result = app(BookAppointment::class)->handle(
                Lead::query()->findOrFail($arguments['lead']),
                User::query()->findOrFail($arguments['salesperson']),
                $arguments['date'],
                $arguments['time'],
                (int) $arguments['minutes'],
                $actor,
            );
        } catch (ValidationException $exception) {
            return ['status' => 'refused', 'refusal' => collect($exception->errors())->flatten()->first(), 'fields' => array_keys($exception->errors())];
        }

        return ['status' => 'booked', 'appointment' => $result->appointment->id];
    },

    // One person gives a Lead's standing appointment a new time. A refusal the person would be shown is an answer.
    'reschedule' => function () use ($arguments): array {
        $actor = User::query()->findOrFail($arguments['actor']);

        Auth::setUser($actor);

        $lead = Lead::query()->findOrFail($arguments['lead']);

        try {
            $result = app(RescheduleAppointment::class)->handle(
                $lead,
                $lead->standingAppointment() ?? throw new RuntimeException('The lead has no standing appointment.'),
                $arguments['date'],
                $arguments['time'],
                (int) $arguments['minutes'],
                $actor,
            );
        } catch (ValidationException $exception) {
            return ['status' => 'refused', 'refusal' => collect($exception->errors())->flatten()->first(), 'fields' => array_keys($exception->errors())];
        }

        return ['status' => 'rescheduled', 'appointment' => $result->appointment->id];
    },

    'appointments' => function () use ($arguments): array {
        $above = $arguments['above'] ?? [];

        return [
            'appointments' => DB::table('appointments')
                ->where('id', '>', (int) ($above['appointments'] ?? 0))
                ->orderBy('id')
                ->get(['id', 'lead_id', 'salesperson_id', 'starts_at', 'ends_at', 'state'])
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'issues' => DB::table('lead_issues')->where('id', '>', (int) ($above['lead_issues'] ?? 0))->count(),
            'leads' => DB::table('leads')
                ->where('id', '>', (int) ($above['leads'] ?? 0))
                ->orderBy('id')
                ->get(['id', 'status', 'assigned_user_id'])
                ->keyBy('id')
                ->map(fn (object $row): array => (array) $row)
                ->all(),
            'notices' => DB::table('notifications')->count(),
        ];
    },

    // Several people press "Next lead" one after another, and their holds may then be made to have run out.
    'prepare-holds' => function () use ($arguments): array {
        $held = [];

        foreach ($arguments['users'] as $user) {
            $held[$user] = app(ClaimNextLead::class)->handle(User::query()->findOrFail($user))->lead?->id;
        }

        if ($arguments['expired'] ?? false) {
            DB::table('lead_cadences')->whereIn('held_by_user_id', $arguments['users'])->update(['held_until' => now()->subMinute()]);
        }

        return ['held' => $held];
    },

    'tick' => fn (): array => ['done' => app(RunQueueTick::class)->handle()],

    'return-to-central' => function () use ($arguments): array {
        app(ReturnLeadToCentral::class)->handle(
            Lead::query()->with('location')->findOrFail($arguments['lead']),
            'Returned by the concurrency test.',
            User::query()->findOrFail($arguments['actor']),
        );

        return ['done' => true];
    },

    'deactivate-location' => function () use ($arguments): array {
        Auth::setUser(User::query()->findOrFail($arguments['actor']));
        app(DeactivateLocation::class)->handle(Location::query()->findOrFail($arguments['location']));

        return ['done' => true];
    },

    'disqualify' => function () use ($arguments): array {
        $actor = User::query()->findOrFail($arguments['actor']);

        Auth::setUser($actor);
        app(DisqualifyLead::class)->handle(
            Lead::query()->findOrFail($arguments['lead']),
            LeadDisqualificationReason::factory()->create(),
            'Disqualified by the concurrency test.',
            $actor,
        );

        return ['done' => true];
    },

    'nurture' => function () use ($arguments): array {
        $actor = User::query()->findOrFail($arguments['actor']);

        Auth::setUser($actor);
        app(MoveLeadToNurture::class)->handle(Lead::query()->findOrFail($arguments['lead']), now()->addWeek()->toDateString(), 'Call back after their trip.', $actor);

        return ['done' => true];
    },

    'attempt' => function () use ($arguments): array {
        $caller = User::query()->findOrFail($arguments['user']);
        $lead = Lead::query()->findOrFail($arguments['lead']);

        Auth::setUser($caller);
        $attempt = app(RecordManualContactAttempt::class)->handle(
            $lead,
            $caller,
            (string) Str::uuid(),
            ContactAttemptChannel::Phone,
            ContactAttemptDirection::Outbound,
            now()->subMinute(),
            $lead->inquiry_phone,
            20,
            false,
            false,
            ContactAttemptDisposition::NoAnswer,
        );

        return ['done' => true, 'attempt' => $attempt->id];
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

    // What the other records look like after everything has finished.
    'records' => function () use ($arguments): array {
        $above = $arguments['above'] ?? [];
        $since = fn (string $table) => DB::table($table)->where('id', '>', (int) ($above[$table] ?? 0));
        $counted = fn (string $table, string $column): array => $since($table)
            ->selectRaw("{$column} as counted, count(*) as total")
            ->groupBy($column)
            ->pluck('total', 'counted')
            ->map(fn (mixed $value): int => (int) $value)
            ->all();

        return [
            'leads' => $since('leads')->orderBy('id')->get(['id', 'location_id', 'status', 'assigned_user_id', 'lifecycle_version'])->keyBy('id')->map(fn (object $row): array => (array) $row)->all(),
            'users' => $since('users')->pluck('is_active', 'id')->map(fn (mixed $active): bool => (bool) $active)->all(),
            'grants' => $since('access_grants')->pluck('id')->map(fn (mixed $value): int => (int) $value)->all(),
            'locations' => $since('locations')->pluck('is_active', 'id')->map(fn (mixed $active): bool => (bool) $active)->all(),
            'audit' => $counted('audit_events', 'action'),
            'attempts' => $counted('contact_attempts', 'lead_id'),
            'status_changes' => $counted('lead_status_changes', 'lead_id'),
            'open_assignments' => $since('lead_assignments')->whereNull('ended_at')->pluck('lead_id')->map(fn (mixed $value): int => (int) $value)->all(),
            'cadences' => $since('lead_cadences')->orderBy('id')->get(['lead_id', 'state', 'attempts_made', 'held_by_user_id'])->keyBy('lead_id')->map(fn (object $row): array => (array) $row)->all(),
        ];
    },

    // Remove every row committed since the baseline, and say what is left.
    'cleanup' => function () use ($arguments, $highestIds): array {
        $baseline = $arguments['highest'] ?? [];

        Schema::disableForeignKeyConstraints();

        // Notices belong to the people the processes made, and go with them.
        $notices = fn () => DB::table('notifications')->where('notifiable_type', 'user')->where('notifiable_id', '>', (int) ($baseline['users'] ?? 0));

        try {
            $notices()->delete();

            foreach ($highestIds() as $table => $highest) {
                if ($highest > (int) ($baseline[$table] ?? 0)) {
                    DB::table($table)->where('id', '>', (int) ($baseline[$table] ?? 0))->delete();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $left = array_diff_assoc($highestIds(), array_map(intval(...), $baseline));

        return ['left' => $notices()->count() > 0 ? $left + ['notifications' => $notices()->count()] : $left];
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
    $result['deadlocks_met'] = $deadlocksMet;
} catch (Throwable $exception) {
    // Reported, never hidden: a test fails on any error it did not expect.
    $result['error'] = $exception::class;
    $result['message'] = substr($exception->getMessage(), 0, 300);
    $result['busy'] = $busy->is($exception);
}

echo json_encode($result, JSON_THROW_ON_ERROR), PHP_EOL;
