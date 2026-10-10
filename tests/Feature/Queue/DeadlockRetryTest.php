<?php

use App\Actions\Leads\RouteLead;
use App\Actions\Locations\DeactivateLocation;
use App\Actions\Queue\EndQueueHold;
use App\Actions\Queue\RunQueueTick;
use App\Enums\LeadStatus;
use App\Enums\Role;
use App\Models\Lead;
use App\Models\LeadCadence;
use App\Models\Location;
use App\Models\User;
use App\Support\Queue\QueueTransaction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * The deadlock fix (decision 87; docs/slice-4-plan.md, contract 5, "Locks").
 * Two parts: the Lead row is locked before its cadence row everywhere a hold
 * is ended, and the existing actions that end a hold or a cadence run again
 * from the start when the database reports a deadlock or says it is busy.
 *
 * These tests are deterministic. The same actions are raced, and made to meet
 * a real rolled-back deadlock, by separate processes in QueueConcurrencyTest.
 *
 * The clock is Wednesday 7 October 2026, 10:00 am Eastern.
 */

beforeEach(function () {
    $this->travelTo('2026-10-07 14:00:00');
});

/**
 * Run something with no transaction open, as a page or a command runs an
 * action. Each test here is itself inside a transaction, so this steps out of
 * it and back in again; the work given must not write to the database.
 */
function outsideAnyTransaction(Closure $work): mixed
{
    $levels = DB::transactionLevel();

    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    try {
        return $work();
    } finally {
        while (DB::transactionLevel() < $levels) {
            DB::beginTransaction();
        }
    }
}

/**
 * The tables of the reads that ran, in order, and whether each asked for a
 * row lock. SQLite has no row locks to ask for, so there only the order shows.
 *
 * @return list<array{table: string, locks: bool}>
 */
function readsDuring(Closure $work): array
{
    $reads = [];

    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $sql = str_replace(['`', '"'], '', strtolower($query->sql));

        if (preg_match('/^select \* from (\w+) /', $sql, $matches) === 1) {
            $reads[] = ['table' => $matches[1], 'locks' => str_contains($sql, 'for update')];
        }
    });

    $work();

    return $reads;
}

/*
|--------------------------------------------------------------------------
| Running an action again
|--------------------------------------------------------------------------
*/

test('an action that meets a deadlock or a busy database is run again from the start, with its models put back first', function () {
    Sleep::fake();

    $steps = [];
    $deadlock = fn () => throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');

    $action = function () use (&$steps, $deadlock): string {
        $steps[] = 'try';

        return count($steps) < 5 ? $deadlock() : 'saved';
    };
    $putBack = function () use (&$steps): void {
        $steps[] = 'put back';
    };

    $result = outsideAnyTransaction(fn () => app(QueueTransaction::class)->attempt($action, $putBack));

    expect($result)->toBe('saved');
    expect($steps)->toBe(['try', 'put back', 'try', 'put back', 'try']);
});

test('after three more tries the failure is passed on, and anything else is never tried again', function () {
    Sleep::fake();

    $tries = 0;
    $busy = function () use (&$tries): never {
        $tries++;

        throw new PDOException('SQLSTATE[HY000]: General error: 5 database is locked');
    };

    expect(fn () => outsideAnyTransaction(fn () => app(QueueTransaction::class)->attempt($busy)))->toThrow(PDOException::class);

    // One try and three more.
    expect($tries)->toBe(4);

    $tries = 0;
    $broken = function () use (&$tries): never {
        $tries++;

        throw new RuntimeException('Something else.');
    };

    expect(fn () => outsideAnyTransaction(fn () => app(QueueTransaction::class)->attempt($broken)))->toThrow(RuntimeException::class);

    expect($tries)->toBe(1);
});

test('inside someone else\'s transaction the action runs once and the deadlock is theirs to handle', function () {
    Sleep::fake();

    $tries = 0;
    $putBack = 0;

    expect(DB::transactionLevel())->toBeGreaterThan(0);

    $action = function () use (&$tries): never {
        $tries++;

        throw new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction');
    };
    $restore = function () use (&$putBack): void {
        $putBack++;
    };

    expect(fn () => app(QueueTransaction::class)->attempt($action, $restore))->toThrow(PDOException::class);

    expect($tries)->toBe(1);
    expect($putBack)->toBe(0);
});

test('a move that fails for any other reason is not tried again and leaves the lead as it was', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $riverside = Location::factory()->create(['name' => 'Riverside']);
    $owner = User::factory()->withRoleEverywhere(Role::Owner)->create();
    $lead = dueLead($lakeview);

    $this->partialMock(EndQueueHold::class)
        ->shouldReceive('forLead')
        ->once()
        ->andThrow(new RuntimeException('Something else.'));

    expect(fn () => app(RouteLead::class)->handle($lead, $riverside, 'A customer asked for the other office.', $owner))
        ->toThrow(RuntimeException::class);

    expect($lead->fresh()->location_id)->toBe($lakeview->id);
});

/*
|--------------------------------------------------------------------------
| Lead before cadence
|--------------------------------------------------------------------------
*/

test('ending the holds at a location locks the lead rows before their cadence rows', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $setter = User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create();
    $lead = dueLead($lakeview);

    expect(nextLead($setter)->lead->id)->toBe($lead->id);

    // What a crash can leave behind: a Lead that left, with its hold still recorded.
    $lead->forceFill(['status' => LeadStatus::Disqualified])->save();

    $reads = readsDuring(fn () => app(DeactivateLocation::class)->handle($lakeview));

    $leadRead = array_search('leads', array_column($reads, 'table'), true);
    $cadenceRead = array_search('lead_cadences', array_column($reads, 'table'), true);

    expect($leadRead)->not->toBeFalse();
    expect($cadenceRead)->not->toBeFalse();
    expect($leadRead)->toBeLessThan($cadenceRead);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect($reads[$leadRead]['locks'])->toBeTrue();
        expect($reads[$cadenceRead]['locks'])->toBeTrue();
    }

    expect(LeadCadence::query()->where('lead_id', $lead->id)->value('held_by_user_id'))->toBeNull();
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_ended:access_lost']);
});

test('the timer locks the lead row before the cadence row when it releases a hold that ran out', function () {
    $lakeview = Location::factory()->create(['name' => 'Lakeview']);
    $setter = User::factory()->withRoleAt(Role::CallCenterAgent, $lakeview)->create();
    $lead = dueLead($lakeview);

    expect(nextLead($setter)->lead->id)->toBe($lead->id);

    // Past the five-minute hold.
    $this->travelTo('2026-10-07 14:06:00');

    $done = [];
    $reads = readsDuring(function () use (&$done): void {
        $done = app(RunQueueTick::class)->handle();
    });

    expect($done)->toMatchArray(['expired' => 1, 'failed' => 0]);

    $leadRead = array_search('leads', array_column($reads, 'table'), true);
    $cadenceRead = array_search('lead_cadences', array_column($reads, 'table'), true);

    expect($leadRead)->not->toBeFalse();
    expect($cadenceRead)->not->toBeFalse();
    expect($leadRead)->toBeLessThan($cadenceRead);

    if (DB::connection()->getDriverName() !== 'sqlite') {
        expect($reads[$leadRead]['locks'])->toBeTrue();
        expect($reads[$cadenceRead]['locks'])->toBeTrue();
    }

    expect(LeadCadence::query()->where('lead_id', $lead->id)->value('held_by_user_id'))->toBeNull();
    expect(queueHistory($lead))->toBe(['entered', 'claimed', 'hold_expired']);
    expect(Lead::query()->whereKey($lead->id)->value('location_id'))->toBe($lakeview->id);
});
