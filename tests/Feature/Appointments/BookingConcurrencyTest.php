<?php

use Tests\Support\QueueProcesses;

/*
 * "No double booking" (rulebook, rule 5), raced by real operating-system
 * processes in a database they genuinely share, as the call queue tests are:
 * the suite's own MySQL 8.4 database in the MySQL job, a temporary file-backed
 * SQLite database otherwise. See Tests\Support\QueueProcesses.
 *
 * Every process boots the application and books through the application's own
 * action. A process that ends in a deadlock, a "database is locked" or any
 * other error fails the test: a booking is either saved or refused with the
 * words the person would be shown.
 *
 * Every process uses one fixed clock: Wednesday 7 October 2026, 10:00 am Eastern.
 */

/** How far apart, in milliseconds, the two bookings of each round are started. */
const BOOKING_STAGGER = [[0, 0], [0, 2], [2, 0], [0, 5], [5, 0], [0, 15], [15, 0], [0, 0]];

beforeEach(function () {
    $this->processes = QueueProcesses::start();
});

afterEach(function () {
    // Always, also after a failure: nothing may be left behind for other tests.
    expect($this->processes->finish())->toBe([]);
});

test('two people booking the same salesperson into the same time at the same moment: one is saved and one is refused', function () {
    $leads = [];

    foreach (array_keys(BOOKING_STAGGER) as $round) {
        $leads[] = "first{$round}";
        $leads[] = "second{$round}";
    }

    $made = $this->processes->run('setup-bookings', ['timezone' => 'America/New_York', 'salespeople' => ['sam'], 'leads' => $leads]);

    expect(array_key_exists('error', $made))->toBeFalse(json_encode($made));

    $results = [];

    foreach (BOOKING_STAGGER as $round => [$firstDelay, $secondDelay]) {
        // Each round is a day of its own, at 3:00 pm, so earlier rounds do not get in the way.
        $booking = ['salesperson' => $made['salespeople']['sam'], 'actor' => $made['owner'], 'date' => sprintf('2026-10-%02d', 8 + $round), 'time' => '15:00', 'minutes' => 90];

        $results[$round] = $this->processes->together([
            ['book', $booking + ['lead' => $made['leads']["first{$round}"], 'delay_ms' => $firstDelay]],
            // The second starts half an hour into the first: an overlap, not the same start.
            ['book', ['time' => '15:30'] + $booking + ['lead' => $made['leads']["second{$round}"], 'delay_ms' => $secondDelay]],
        ]);

        foreach ($results[$round] as $result) {
            expect(array_key_exists('error', $result))->toBeFalse("round {$round}: ".json_encode($result));
        }

        $statuses = array_column($results[$round], 'status');
        sort($statuses);

        // Exactly one of the two was saved, and the other was told why not.
        expect($statuses)->toBe(['booked', 'refused'], "round {$round}: ".json_encode($results[$round]));

        $refused = collect($results[$round])->firstWhere('status', 'refused');

        expect($refused['fields'])->toBe(['appointmentTime'], json_encode($refused));
        expect($refused['refusal'])->toContain('already has an appointment from');
    }

    $state = $this->processes->run('appointments', ['above' => $this->processes->baseline()]);

    expect(array_key_exists('error', $state))->toBeFalse(json_encode($state));

    // One standing appointment for each round, one issue each, one notice each, and nothing half-saved.
    expect($state['appointments'])->toHaveCount(count(BOOKING_STAGGER));
    expect(collect($state['appointments'])->pluck('state')->unique()->all())->toBe(['booked']);
    expect($state['issues'])->toBe(count(BOOKING_STAGGER));
    expect($state['notices'])->toBe(count(BOOKING_STAGGER));

    $bookedLeads = collect($state['appointments'])->pluck('lead_id')->all();

    foreach ($state['leads'] as $leadId => $lead) {
        $booked = in_array($leadId, $bookedLeads, true);

        expect($lead['status'])->toBe($booked ? 'appointment_booked' : 'qualified', "lead {$leadId}");
        expect($lead['assigned_user_id'] === null)->toBe(! $booked, "lead {$leadId}");
    }

    // No two standing appointments of the salesperson share any time.
    $spans = collect($state['appointments'])->sortBy('starts_at')->values();

    foreach ($spans as $index => $span) {
        if ($index > 0) {
            expect($span['starts_at'] >= $spans[$index - 1]['ends_at'])->toBeTrue(json_encode([$spans[$index - 1], $span]));
        }
    }
});
