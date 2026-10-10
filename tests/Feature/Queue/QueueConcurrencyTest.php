<?php

use Tests\Support\QueueProcesses;

/*
 * The guarantees of "Next lead", raced by real operating-system processes in
 * a database they genuinely share: the suite's own MySQL 8.4 database in the
 * MySQL job, a temporary file-backed SQLite database otherwise. See
 * Tests\Support\QueueProcesses and tests/Support/queue-process.php.
 *
 * Nothing here is simulated. Every process boots the application, waits at a
 * starting line and runs the application's own actions. A process that ends
 * in a deadlock, a "database is locked" or any other error fails the test.
 *
 * The processes use the application's own database settings, with nothing
 * changed for the test. On SQLite a write that meets another process's write
 * is refused at once, and the application retries it three times; MySQL waits
 * on row locks instead.
 *
 * Every process uses one fixed clock: Wednesday 7 October 2026, 10:00 am
 * Eastern, which is 4:00 am in Honolulu.
 */

const OPEN_ZONE = 'America/New_York';
const CLOSED_ZONE = 'Pacific/Honolulu';

/**
 * How far apart, in milliseconds, the claim and the change are started in
 * each round: [claim held back, change held back]. A claim does more work
 * than a change before it reaches the row they compete for, so the rounds
 * lean towards giving the claim a head start: they run from "the change is
 * well ahead", through the window where the two really collide, to "the claim
 * is well ahead".
 */
const STAGGER = [[40, 0], [10, 0], [0, 0], [0, 5], [0, 10], [0, 15], [0, 20], [0, 30], [0, 60], [0, 120]];

/**
 * Extra rounds kept in reserve. They run only while one of the two orders has
 * still not been met, each holding the other side back twice as long as the
 * last, so a slow or busy machine cannot leave an order unchecked.
 */
const SPARE_ROUNDS = 4;

/**
 * The stagger for a round, or null when the rounds are over.
 *
 * @return array{0: int, 1: int}|null
 */
function staggerFor(int $round, int $claimGotInFirst, int $changeGotInFirst): ?array
{
    if (isset(STAGGER[$round])) {
        return STAGGER[$round];
    }

    $spare = $round - count(STAGGER);

    if ($spare >= SPARE_ROUNDS || ($claimGotInFirst > 0 && $changeGotInFirst > 0)) {
        return null;
    }

    $wait = 250 * (2 ** $spare);

    return $claimGotInFirst === 0 ? [0, $wait] : [$wait, 0];
}

beforeEach(function () {
    $this->processes = QueueProcesses::start();
});

afterEach(function () {
    // Always, also after a failure: nothing may be left behind for other tests.
    expect($this->processes->finish())->toBe([]);
});

/**
 * No process may end in an error of any kind, or be told to try again.
 *
 * @param  list<array<string, mixed>>  $results
 */
function expectCleanRun(array $results): void
{
    foreach ($results as $result) {
        expect(array_key_exists('error', $result))->toBeFalse(json_encode($result));
        expect($result['status'] ?? 'done')->not->toBe('try_again', json_encode($result));
    }
}

/**
 * The queue history of one Lead after it entered, as "type:reason@affected" words.
 *
 * @param  array{events: list<array<string, mixed>>}  $state
 * @return list<string>
 */
function historyAfterEntering(array $state, int $leadId): array
{
    return collect($state['events'])
        ->where('lead_id', $leadId)
        ->reject(fn (array $event): bool => $event['type'] === 'entered')
        ->map(fn (array $event): string => $event['type']
            .($event['reason'] === null ? '' : ':'.$event['reason'])
            .($event['affected_user_id'] === null ? '' : '@'.$event['affected_user_id']))
        ->values()
        ->all();
}

/**
 * Who holds a Lead when everything has finished.
 *
 * @param  array{cadences: list<array<string, mixed>>}  $state
 */
function holderOf(array $state, int $leadId): ?int
{
    $holder = collect($state['cadences'])->firstWhere('lead_id', $leadId)['held_by_user_id'];

    return $holder === null ? null : (int) $holder;
}

test('eight processes claim from five due leads: five succeed, each lead has one holder, three are told nothing is due', function () {
    $made = $this->processes->run('setup', [
        'locations' => ['open' => OPEN_ZONE],
        'setters' => array_fill_keys(['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8'], null),
        'leads' => array_fill_keys(['l1', 'l2', 'l3', 'l4', 'l5'], 'open'),
    ]);

    expect($made)->not->toHaveKey('error');

    $results = $this->processes->together(array_map(
        fn (int $setter): array => ['claim', ['user' => $setter]],
        array_values($made['setters']),
    ));

    expectCleanRun($results);

    // They really did start together: every process was waiting at the line.
    expect(collect($results)->min('early_ms'))->toBeGreaterThan(0);

    $given = collect($results)->where('status', 'given');
    $nothing = collect($results)->where('status', 'nothing_due');

    expect($given)->toHaveCount(5);
    expect($nothing)->toHaveCount(3);
    expect($given->pluck('lead')->sort()->values()->all())->toBe(collect($made['leads'])->sort()->values()->all());

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);
    $holders = collect($state['cadences'])->pluck('held_by_user_id', 'lead_id');

    // Every Lead has exactly one holder, and no setter holds two.
    expect($holders)->toHaveCount(5);
    expect($holders->filter()->count())->toBe(5);
    expect($holders->unique()->count())->toBe(5);
    expect($holders->values()->diff(array_values($made['setters']))->all())->toBe([]);

    // Each process was told about the Lead the database says it holds.
    foreach ($given as $index => $result) {
        expect((int) $holders[$result['lead']])->toBe(array_values($made['setters'])[$index]);
    }

    // The history matches: five entered, five claimed, one claim per Lead, nothing else.
    $events = collect($state['events']);

    expect($events->where('type', 'entered'))->toHaveCount(5);
    expect($events->where('type', 'claimed'))->toHaveCount(5);
    expect($events->where('type', 'claimed')->pluck('lead_id')->unique())->toHaveCount(5);
    expect($events)->toHaveCount(10);
});

test('one setter pressing next lead in four tabs at once holds exactly one lead', function () {
    $made = $this->processes->run('setup', [
        'locations' => ['open' => OPEN_ZONE],
        'setters' => ['s1' => null],
        'leads' => array_fill_keys(['l1', 'l2', 'l3'], 'open'),
    ]);

    $results = $this->processes->together(array_fill(0, 4, ['claim', ['user' => $made['setters']['s1']]]));

    expectCleanRun($results);

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);
    $held = collect($state['cadences'])->whereNotNull('held_by_user_id');

    // The database refuses a second hold for one person, and every tab is
    // answered with the one Lead that person holds.
    expect($held)->toHaveCount(1);
    expect(collect($results)->pluck('status')->unique()->all())->toBe(['given']);
    expect(collect($results)->pluck('lead')->unique()->all())->toBe([$held->first()['lead_id']]);
    expect(collect($state['events'])->where('type', 'claimed'))->toHaveCount(1);
});

test('a change that takes a lead away while it is being claimed never leaves a hold its holder may not have', function (string $change) {
    $locations = ['closed' => CLOSED_ZONE];
    $setters = [];
    $leads = [];

    // Each round has a Location of its own, so its two setters can only be given its one Lead.
    foreach (range(0, count(STAGGER) + SPARE_ROUNDS - 1) as $round) {
        $locations["open{$round}"] = OPEN_ZONE;
        $setters["a{$round}"] = "open{$round}";
        $setters["b{$round}"] = "open{$round}";
        $leads["lead{$round}"] = "open{$round}";
    }

    $made = $this->processes->run('setup', ['locations' => $locations, 'setters' => $setters, 'leads' => $leads]);

    expect($made)->not->toHaveKey('error');

    $results = [];
    $claimGotInFirst = 0;
    $changeGotInFirst = 0;

    for ($round = 0; ($stagger = staggerFor($round, $claimGotInFirst, $changeGotInFirst)) !== null; $round++) {
        [$claimDelay, $changeDelay] = $stagger;
        $affected = $made['setters']["a{$round}"];

        $changeJob = match ($change) {
            'the lead is routed to a closed location' => ['route', ['lead' => $made['leads']["lead{$round}"], 'location' => $made['locations']['closed'], 'actor' => $made['owner']]],
            'the claimer\'s access is revoked' => ['revoke', ['grant' => $made['grants']["a{$round}"], 'actor' => $made['owner']]],
            'the claimer is deactivated' => ['deactivate', ['user' => $affected, 'actor' => $made['owner']]],
        };

        $changeJob[1]['delay_ms'] = $changeDelay;

        $results[$round] = $this->processes->together([
            ['claim', ['user' => $affected, 'delay_ms' => $claimDelay]],
            ['claim', ['user' => $made['setters']["b{$round}"], 'delay_ms' => $claimDelay]],
            $changeJob,
        ]);

        expectCleanRun($results[$round]);

        // For a move, either setter's claim counts; otherwise only the setter the change is about.
        $claimed = $change === 'the lead is routed to a closed location'
            ? collect($results[$round])->contains('status', 'given')
            : $results[$round][0]['status'] === 'given';

        $claimed ? $claimGotInFirst++ : $changeGotInFirst++;
    }

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);

    foreach (array_keys($results) as $round) {
        $lead = $made['leads']["lead{$round}"];
        $affected = $made['setters']["a{$round}"];
        $other = $made['setters']["b{$round}"];
        $history = historyAfterEntering($state, $lead);
        $holder = holderOf($state, $lead);
        $context = "round {$round}: ".json_encode(['history' => $history, 'holder' => $holder, 'results' => $results[$round]]);

        if ($change === 'the lead is routed to a closed location') {
            // Nobody may be given a Lead at a closed Location: no hold may be left on it.
            expect($holder)->toBeNull($context);

            // One of the two legal outcomes: never claimed, or claimed and then ended by the move.
            expect($history === [] || (count($history) === 2
                && in_array($history[0], ['claimed'], true)
                && in_array($history[1], ["hold_ended:moved@{$affected}", "hold_ended:moved@{$other}"], true)))->toBeTrue($context);

            // A process was told "given" only if the history shows its claim.
            expect(collect($results[$round])->where('status', 'given')->count())->toBe($history === [] ? 0 : 1, $context);

            continue;
        }

        // The affected setter may no longer be given the Lead; the other still may.
        expect($holder)->not->toBe($affected, $context);
        expect($holder === null || $holder === $other)->toBeTrue($context);

        // Every hold the affected setter took was ended by the change itself.
        $ended = "hold_ended:access_lost@{$affected}";
        $affectedClaimed = $results[$round][0]['status'] === 'given';

        expect(in_array($ended, $history, true))->toBe($affectedClaimed, $context);
        expect(collect($history)->filter(fn (string $entry): bool => str_starts_with($entry, 'hold_ended'))->reject(fn (string $entry): bool => $entry === $ended)->all())->toBe([], $context);
        expect(collect($history)->where(fn (string $entry): bool => $entry === 'claimed')->count())
            ->toBe(collect($results[$round])->where('status', 'given')->count(), $context);

        if ($affectedClaimed) {
            // Its claim comes before the entry that ends it.
            expect(array_search('claimed', $history, true))->toBeLessThan(array_search($ended, $history, true), $context);
        } else {
            expect(in_array($results[$round][0]['status'], ['nothing_due', 'refused'], true))->toBeTrue($context);
        }
    }

    // Both orders were really met, so both outcomes were really checked.
    expect($claimGotInFirst)->toBeGreaterThan(0, "The claim never got in first in {$change}.");
    expect($changeGotInFirst)->toBeGreaterThan(0, "The change never got in first in {$change}.");

    if (getenv('QUEUE_RACE_REPORT')) {
        fwrite(STDERR, sprintf("%s [%s]: claim first %d, change first %d, rounds %d\n", $change, $this->processes->engine(), $claimGotInFirst, $changeGotInFirst, count($results)));
    }
})->with([
    'the lead is routed to a closed location',
    'the claimer\'s access is revoked',
    'the claimer is deactivated',
]);

test('a change of hours or timezone while a lead is being claimed never hands it out after the change', function (string $change) {
    $locations = [];
    $setters = [];
    $leads = [];

    foreach (range(0, count(STAGGER) + SPARE_ROUNDS - 1) as $round) {
        $locations["open{$round}"] = OPEN_ZONE;
        $setters["a{$round}"] = "open{$round}";
        $setters["b{$round}"] = "open{$round}";
        $leads["lead{$round}"] = "open{$round}";
    }

    $made = $this->processes->run('setup', ['locations' => $locations, 'setters' => $setters, 'leads' => $leads]);

    expect($made)->not->toHaveKey('error');

    $version = $this->processes->run('settings-version')['version'];
    $settingsBaseline = $this->processes->baseline()['contact_center_settings'];
    $claimGotInFirst = 0;
    $changeGotInFirst = 0;
    $context = [];

    for ($round = 0; ($stagger = staggerFor($round, $claimGotInFirst, $changeGotInFirst)) !== null; $round++) {
        [$claimDelay, $changeDelay] = $stagger;
        $first = $made['setters']["a{$round}"];
        $late = $made['setters']["b{$round}"];
        $lead = $made['leads']["lead{$round}"];

        $changeJob = match ($change) {
            // 10:00 am Eastern is outside noon to 9:00 pm.
            'the calling hours are narrowed to exclude this moment' => ['narrow-hours', ['actor' => $made['owner'], 'version' => $version, 'opens' => 720, 'closes' => 1260, 'delay_ms' => $changeDelay]],
            'the location\'s timezone is changed to one that is closed' => ['timezone', ['location' => $made['locations']["open{$round}"], 'timezone' => CLOSED_ZONE, 'actor' => $made['owner'], 'delay_ms' => $changeDelay]],
        };

        [$raced, $changed] = $this->processes->together([
            ['claim', ['user' => $first, 'delay_ms' => $claimDelay]],
            $changeJob,
        ]);

        expectCleanRun([$raced, $changed]);
        expect($changed['done'])->toBeTrue();

        // The change has committed. A claim that starts now is always refused,
        // and the first setter's next "Next lead" gives up any hold taken just before it.
        [$lateClaim, $again] = [
            $this->processes->run('claim', ['user' => $late]),
            $this->processes->run('claim', ['user' => $first]),
        ];

        $context[$round] = json_encode(compact('raced', 'changed', 'lateClaim', 'again'));

        expectCleanRun([$lateClaim, $again]);

        expect($lateClaim['status'])->toBe('nothing_due', $context[$round]);
        expect($again['status'])->toBe('nothing_due', $context[$round]);

        if ($raced['status'] === 'given') {
            expect($raced['lead'])->toBe($lead, $context[$round]);
            expect($again['ended_hold'])->toBe('outside_calling_hours', $context[$round]);
            $claimGotInFirst++;
        } else {
            expect($raced['status'])->toBe('nothing_due', $context[$round]);
            expect($again['ended_hold'])->toBeNull($context[$round]);
            $changeGotInFirst++;
        }

        if ($change === 'the calling hours are narrowed to exclude this moment') {
            // Back to the original hours for the next round.
            expect($this->processes->run('reset-settings', ['above' => $settingsBaseline])['removed'])->toBe(1);
        }
    }

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);

    foreach (array_keys($context) as $round) {
        $lead = $made['leads']["lead{$round}"];
        $first = $made['setters']["a{$round}"];
        $history = historyAfterEntering($state, $lead);

        // Nothing outside calling hours is left held, and the history shows one of the two legal outcomes.
        expect(holderOf($state, $lead))->toBeNull($context[$round]);
        expect(in_array($history, [[], ['claimed', "hold_ended:outside_calling_hours@{$first}"]], true))->toBeTrue($context[$round].json_encode($history));
    }

    expect($claimGotInFirst)->toBeGreaterThan(0, "The claim never got in first in {$change}.");
    expect($changeGotInFirst)->toBeGreaterThan(0, "The change never got in first in {$change}.");

    if (getenv('QUEUE_RACE_REPORT')) {
        fwrite(STDERR, sprintf("%s [%s]: claim first %d, change first %d, rounds %d\n", $change, $this->processes->engine(), $claimGotInFirst, $changeGotInFirst, count($context)));
    }
})->with([
    'the calling hours are narrowed to exclude this moment',
    'the location\'s timezone is changed to one that is closed',
]);

/*
|--------------------------------------------------------------------------
| Deadlocks (decision 87)
|--------------------------------------------------------------------------
|
| The existing actions that also end a hold or a cadence take the Lead row
| before the cadence row, and run again from the start when the database
| reports a deadlock or says it is busy.
|
*/

test('an action that meets a deadlock once runs again from the start and saves everything exactly once', function () {
    $cases = ['route', 'return', 'revoke', 'deactivate', 'disqualify', 'nurture', 'attempt'];
    $locations = ['destination' => OPEN_ZONE, 'empty' => OPEN_ZONE];
    $setters = [];
    $leads = [];

    // Each case has a Location, a setter and a Lead of its own.
    foreach ($cases as $case) {
        $locations[$case] = OPEN_ZONE;
        $setters[$case] = $case;
        $leads[$case] = $case;
    }

    $made = $this->processes->run('setup', ['locations' => $locations, 'setters' => $setters, 'leads' => $leads, 'assigned' => true]);

    expect(array_key_exists('error', $made))->toBeFalse(json_encode($made));

    $owner = $made['owner'];
    $lead = fn (string $case): int => $made['leads'][$case];
    $setter = fn (string $case): int => $made['setters'][$case];

    // These Leads are held by their own setter when the action runs.
    $holding = ['route', 'return', 'revoke', 'deactivate', 'attempt'];
    $held = $this->processes->run('prepare-holds', ['users' => array_map($setter, $holding)]);

    expect(array_key_exists('error', $held))->toBeFalse(json_encode($held));

    foreach ($holding as $case) {
        expect($held['held'][$setter($case)])->toBe($lead($case), $case);
    }

    // The first history entry each action writes is answered with a deadlock, after everything before it was written.
    $deadlock = ['fail_on_insert' => 'lead_queue_events'];

    $results = [
        'route' => $this->processes->run('route', ['lead' => $lead('route'), 'location' => $made['locations']['destination'], 'actor' => $owner] + $deadlock),
        'return' => $this->processes->run('return-to-central', ['lead' => $lead('return'), 'actor' => $owner] + $deadlock),
        'revoke' => $this->processes->run('revoke', ['grant' => $made['grants']['revoke'], 'actor' => $owner] + $deadlock),
        'deactivate' => $this->processes->run('deactivate', ['user' => $setter('deactivate'), 'actor' => $owner] + $deadlock),
        'disqualify' => $this->processes->run('disqualify', ['lead' => $lead('disqualify'), 'actor' => $owner] + $deadlock),
        'nurture' => $this->processes->run('nurture', ['lead' => $lead('nurture'), 'actor' => $owner] + $deadlock),
        'attempt' => $this->processes->run('attempt', ['lead' => $lead('attempt'), 'user' => $setter('attempt')] + $deadlock),
        // No Lead is here, so there is no history entry: the audit entry is the one that meets the deadlock.
        'deactivate-location' => $this->processes->run('deactivate-location', ['location' => $made['locations']['empty'], 'actor' => $owner, 'fail_on_insert' => 'audit_events']),
    ];

    foreach ($results as $case => $result) {
        expect(array_key_exists('error', $result))->toBeFalse("{$case}: ".json_encode($result));

        // The deadlock really happened, once, and the action carried on by itself.
        expect($result['deadlocks_met'])->toBe(1, $case);
    }

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);
    $records = $this->processes->run('records', ['above' => $this->processes->baseline()]);

    expect(array_key_exists('error', $records))->toBeFalse(json_encode($records));

    $history = fn (string $case): array => historyAfterEntering($state, $lead($case));
    $row = fn (string $case): array => $records['leads'][$lead($case)];
    $cadence = fn (string $case): array => $records['cadences'][$lead($case)];
    // A move does not touch the lifecycle revision, so this is the one every Lead started with.
    $startingRevision = (int) $row('route')['lifecycle_version'];

    // Moved once: at the destination, nobody assigned, the hold ended by the move.
    expect((int) $row('route')['location_id'])->toBe($made['locations']['destination']);
    expect($row('route')['assigned_user_id'])->toBeNull();
    expect($history('route'))->toBe(['claimed', "hold_ended:moved@{$setter('route')}"]);

    expect($row('return')['location_id'])->toBeNull();
    expect($row('return')['assigned_user_id'])->toBeNull();
    expect($history('return'))->toBe(['claimed', "hold_ended:moved@{$setter('return')}"]);

    expect($records['grants'])->not->toContain($made['grants']['revoke']);
    expect($history('revoke'))->toBe(['claimed', "hold_ended:access_lost@{$setter('revoke')}"]);

    expect($records['users'][$setter('deactivate')])->toBeFalse();
    expect($history('deactivate'))->toBe(['claimed', "hold_ended:access_lost@{$setter('deactivate')}"]);

    expect($row('disqualify')['status'])->toBe('disqualified');
    expect($row('disqualify')['assigned_user_id'])->toBeNull();
    expect((int) $row('disqualify')['lifecycle_version'])->toBe($startingRevision + 1);
    expect($cadence('disqualify')['state'])->toBe('disqualified');
    expect($history('disqualify'))->toBe(['ended:disqualified']);

    expect($row('nurture')['status'])->toBe('nurture');
    expect((int) $row('nurture')['assigned_user_id'])->toBe($owner);
    expect((int) $row('nurture')['lifecycle_version'])->toBe($startingRevision + 1);
    expect($cadence('nurture')['state'])->toBe('deferred_follow_up');
    expect($history('nurture'))->toBe(['ended:deferred_follow_up']);

    expect($records['attempts'])->toBe([$lead('attempt') => 1]);
    expect((int) $cadence('attempt')['attempts_made'])->toBe(1);
    expect($cadence('attempt')['held_by_user_id'])->toBeNull();
    expect($history('attempt'))->toHaveCount(3);
    expect($history('attempt')[0])->toBe('claimed');
    expect(collect($history('attempt'))->filter(fn (string $entry): bool => str_starts_with($entry, 'hold_ended:attempt_logged'))->count())->toBe(1);
    expect(collect($history('attempt'))->filter(fn (string $entry): bool => str_starts_with($entry, 'attempt_counted'))->count())->toBe(1);

    expect($records['locations'][$made['locations']['empty']])->toBeFalse();

    // Nothing was written twice: one audit entry for each thing that happened.
    $audit = $records['audit'];
    ksort($audit);

    expect($audit)->toBe([
        'lead.disqualified' => 1,
        'lead.moved_to_nurture' => 1,
        'lead.returned_to_central' => 1,
        'lead.transferred' => 1,
        // The move, the return and the disqualification each ended the Owner's assignment.
        'lead.unassigned' => 3,
        'location.deactivated' => 1,
        'staff.access_revoked' => 1,
        'staff.deactivated' => 1,
    ]);

    $statusChanges = $records['status_changes'];
    ksort($statusChanges);

    expect($statusChanges)->toBe(collect([$lead('disqualify') => 1, $lead('nurture') => 1])->sortKeys()->all());

    // The Leads nothing unassigned are still with the Owner, each with one open assignment.
    $stillAssigned = $records['open_assignments'];
    sort($stillAssigned);

    expect($stillAssigned)->toBe(collect(['revoke', 'deactivate', 'nurture', 'attempt'])->map($lead)->sort()->values()->all());
});

test('two changes to the same hold at the same moment both finish and the hold is ended once', function (string $pair) {
    $locations = ['destination' => OPEN_ZONE];
    $setters = [];
    $leads = [];

    // Each round has a Location of its own, so its setters can only be given its one Lead.
    foreach (array_keys(STAGGER) as $round) {
        $locations["open{$round}"] = OPEN_ZONE;
        $setters["a{$round}"] = "open{$round}";
        $setters["b{$round}"] = "open{$round}";
        $leads["lead{$round}"] = "open{$round}";
    }

    $made = $this->processes->run('setup', ['locations' => $locations, 'setters' => $setters, 'leads' => $leads]);

    expect(array_key_exists('error', $made))->toBeFalse(json_encode($made));

    $owner = $made['owner'];
    $holdRanOut = $pair !== 'the holder is deactivated while a manager moves the lead';
    $results = [];

    foreach (STAGGER as $round => [$firstDelay, $secondDelay]) {
        $holder = $made['setters']["a{$round}"];
        $other = $made['setters']["b{$round}"];
        $lead = $made['leads']["lead{$round}"];

        // The first setter holds the Lead before the race starts.
        $held = $this->processes->run('prepare-holds', ['users' => [$holder], 'expired' => $holdRanOut]);

        expect($held['held'][$holder] ?? null)->toBe($lead, "round {$round}: ".json_encode($held));

        $deactivate = ['deactivate', ['user' => $holder, 'actor' => $owner, 'delay_ms' => $firstDelay]];
        $claim = ['claim', ['user' => $other, 'delay_ms' => $secondDelay]];

        $results[$round] = $this->processes->together(match ($pair) {
            'the holder is deactivated while a manager moves the lead' => [$deactivate, ['route', ['lead' => $lead, 'location' => $made['locations']['destination'], 'actor' => $owner, 'delay_ms' => $secondDelay]]],
            'the holder is deactivated while someone else takes over their hold that ran out' => [$deactivate, $claim],
            'the timer releases a hold that ran out while someone else claims the lead' => [['tick', ['delay_ms' => $firstDelay]], $claim],
        });

        expectCleanRun($results[$round]);
    }

    $state = $this->processes->run('inspect', ['above' => $this->processes->baseline()]);
    $records = $this->processes->run('records', ['above' => $this->processes->baseline()]);

    foreach (array_keys($results) as $round) {
        $holder = $made['setters']["a{$round}"];
        $other = $made['setters']["b{$round}"];
        $lead = $made['leads']["lead{$round}"];
        $history = historyAfterEntering($state, $lead);
        $context = "round {$round}: ".json_encode(['history' => $history, 'holder' => holderOf($state, $lead), 'results' => $results[$round]]);

        if (! $holdRanOut) {
            // Both changes were saved, and whichever reached the hold first ended it. It was ended once.
            expect($records['users'][$holder])->toBeFalse($context);
            expect((int) $records['leads'][$lead]['location_id'])->toBe($made['locations']['destination'], $context);
            expect(holderOf($state, $lead))->toBeNull($context);
            expect($history)->toHaveCount(2, $context);
            expect($history[0])->toBe('claimed', $context);
            expect(in_array($history[1], ["hold_ended:access_lost@{$holder}", "hold_ended:moved@{$holder}"], true))->toBeTrue($context);

            continue;
        }

        // The hold that ran out was tidied up once, by whichever got there first, and the other setter has the Lead.
        sort($history);

        expect($history)->toBe(['claimed', 'claimed', "hold_expired@{$holder}"], $context);
        expect(holderOf($state, $lead))->toBe($other, $context);
        expect($results[$round][1]['status'])->toBe('given', $context);
        expect($results[$round][1]['lead'])->toBe($lead, $context);

        if ($pair === 'the holder is deactivated while someone else takes over their hold that ran out') {
            expect($records['users'][$holder])->toBeFalse($context);

            continue;
        }

        // The timer swallows and logs a failure, so its own count is what shows one. On SQLite a write that
        // meets another write is refused at once and the timer leaves that hold for its next run.
        if ($this->processes->engine() === 'mysql') {
            expect($results[$round][0]['done']['failed'])->toBe(0, $context);
        }
    }
})->with([
    'the holder is deactivated while a manager moves the lead',
    'the holder is deactivated while someone else takes over their hold that ran out',
    'the timer releases a hold that ran out while someone else claims the lead',
]);
