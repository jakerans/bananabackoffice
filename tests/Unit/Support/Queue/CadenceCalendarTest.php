<?php

use App\Support\Queue\CadenceSchedule;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;

/*
 * The calendar of the call queue, with fixed clocks. Every instant is written
 * in UTC; the comments give the local time it stands for.
 *
 * In 2026 clocks go forward on Sunday 8 March and back on Sunday 1 November.
 */

const EASTERN = 'America/New_York';
const CENTRAL = 'America/Chicago';
const ARIZONA = 'America/Phoenix';
const PACIFIC = 'America/Los_Angeles';

/**
 * @param  list<int>  $days
 */
function callingHours(int $opens = 480, int $closes = 1260, array $days = [1, 2, 3, 4, 5, 6, 7]): CallingHours
{
    return new CallingHours($opens, $closes, $days);
}

function defaultCadence(?CallingHours $hours = null): CadenceSchedule
{
    return new CadenceSchedule([
        ['type' => 'timed', 'waits_minutes' => [0, 30, 90]],
        ['type' => 'spread', 'attempts' => 3],
        ['type' => 'spread', 'attempts' => 2],
        ['type' => 'spread', 'attempts' => 2],
        ['type' => 'spread', 'attempts' => 2],
    ], $hours ?? callingHours());
}

function utc(string $time): CarbonImmutable
{
    return CarbonImmutable::parse($time, 'UTC');
}

/**
 * Every due time of a whole run, each attempt made a fixed time after it fell due.
 *
 * @return list<string> Local times, "D H:i".
 */
function runOf(CadenceSchedule $schedule, string $capturedAt, string $timezone, int $lateByMinutes = 0): array
{
    $due = $schedule->firstDueAt(utc($capturedAt), $timezone);
    $times = [];

    for ($step = 1; $due !== null; $step++) {
        $times[] = $due->setTimezone($timezone)->format('D H:i');
        $due = $schedule->dueAtAfter($step + 1, $due->addMinutes($lateByMinutes), $timezone);
    }

    return $times;
}

test('attempt 1 is due at the next opening at or after the moment the lead is captured', function (string $capturedAt, string $dueAt) {
    expect(defaultCadence()->firstDueAt(utc($capturedAt), EASTERN)->toDateTimeString())->toBe($dueAt);
})->with([
    'at 10:00 am, at once' => ['2026-10-07 14:00:00', '2026-10-07 14:00:00'],
    'at 8:50 pm, at once' => ['2026-10-08 00:50:00', '2026-10-08 00:50:00'],
    'at exactly 9:00 pm, the next morning at 8:00 am' => ['2026-10-08 01:00:00', '2026-10-08 12:00:00'],
    'at 3:00 am, the same morning at 8:00 am' => ['2026-10-07 07:00:00', '2026-10-07 12:00:00'],
    'one minute before opening, at 8:00 am' => ['2026-10-07 11:59:00', '2026-10-07 12:00:00'],
    'at 8:00 am exactly, at once' => ['2026-10-07 12:00:00', '2026-10-07 12:00:00'],
]);

test('a lead captured on an unticked day waits for the next calling day', function () {
    $weekdays = defaultCadence(callingHours(days: [1, 2, 3, 4, 5]));

    // Saturday 10 October, 10:00 am Eastern: Monday 12 October at 8:00 am.
    expect($weekdays->firstDueAt(utc('2026-10-10 14:00:00'), EASTERN)->toDateTimeString())->toBe('2026-10-12 12:00:00');

    // Friday at 9:30 pm: the weekend is skipped as well.
    expect($weekdays->firstDueAt(utc('2026-10-10 01:30:00'), EASTERN)->toDateTimeString())->toBe('2026-10-12 12:00:00');
});

test('a day 1 wait that crosses closing rolls to the next opening and keeps its place', function () {
    $schedule = defaultCadence();

    // Attempt 1 made at 8:50 pm. Thirty minutes later is 9:20 pm: closed.
    $second = $schedule->dueAtAfter(2, utc('2026-10-08 00:50:00'), EASTERN);
    expect($second->toDateTimeString())->toBe('2026-10-08 12:00:00');

    // Attempt 2 made at that 8:00 am opening: attempt 3 follows it by 90 minutes.
    $third = $schedule->dueAtAfter(3, $second, EASTERN);
    expect($third->toDateTimeString())->toBe('2026-10-08 13:30:00');

    // The next cadence day starts the day after attempt 3 was made.
    expect($schedule->dueAtAfter(4, $third, EASTERN)->toDateTimeString())->toBe('2026-10-09 12:00:00');
});

test('a full run made on time yields all twelve attempts at the documented times', function () {
    // Captured Wednesday 7 October 2026 at 10:00 am Eastern.
    expect(runOf(defaultCadence(), '2026-10-07 14:00:00', EASTERN))->toBe([
        'Wed 10:00', 'Wed 10:30', 'Wed 12:00',
        'Thu 08:00', 'Thu 12:20', 'Thu 16:40',
        'Fri 08:00', 'Fri 14:30',
        'Sat 08:00', 'Sat 14:30',
        'Sun 08:00', 'Sun 14:30',
    ]);
});

test('a run made late still yields twelve attempts, in order and inside calling hours', function (int $lateByMinutes) {
    $schedule = defaultCadence();
    $due = $schedule->firstDueAt(utc('2026-10-07 14:00:00'), EASTERN);
    $all = [];

    for ($step = 1; $due !== null; $step++) {
        expect($schedule->hours->isOpen($due, EASTERN))->toBeTrue();
        expect($all === [] || $due->gt(end($all)))->toBeTrue();

        $all[] = $due;
        $due = $schedule->dueAtAfter($step + 1, $due->addMinutes($lateByMinutes), EASTERN);
    }

    expect($all)->toHaveCount(12);
})->with([
    'ten minutes late' => 10,
    'five hours late' => 300,
    'a day and a half late' => 2160,
    'nine days late' => 12960,
]);

test('there is no thirteenth attempt, and no attempt zero', function () {
    $schedule = defaultCadence();

    expect($schedule->totalAttempts())->toBe(12);
    expect($schedule->dueAtAfter(13, utc('2026-10-11 18:30:00'), EASTERN))->toBeNull();
    expect($schedule->positionOf(0))->toBeNull();
    expect($schedule->positionOf(1))->toBe([0, 0]);
    expect($schedule->positionOf(4))->toBe([1, 0]);
    expect($schedule->positionOf(12))->toBe([4, 1]);
});

test('narrowed hours move the openings and the gaps', function () {
    // 9:00 am to 5:00 pm.
    $schedule = defaultCadence(callingHours(540, 1020));

    // Captured Wednesday at 7:00 pm, after the narrowed closing.
    expect(runOf($schedule, '2026-10-07 23:00:00', EASTERN))->toBe([
        'Thu 09:00', 'Thu 09:30', 'Thu 11:00',
        'Fri 09:00', 'Fri 11:40', 'Fri 14:20',
        'Sat 09:00', 'Sat 13:00',
        'Sun 09:00', 'Sun 13:00',
        'Mon 09:00', 'Mon 13:00',
    ]);
});

test('with one calling day a week every cadence day falls on it', function () {
    $schedule = defaultCadence(callingHours(days: [3]));

    $due = $schedule->firstDueAt(utc('2026-10-07 14:00:00'), EASTERN);
    $dates = [];

    for ($step = 1; $due !== null; $step++) {
        $dates[] = $due->setTimezone(EASTERN)->format('D M j H:i');
        $due = $schedule->dueAtAfter($step + 1, $due, EASTERN);
    }

    expect($dates)->toBe([
        'Wed Oct 7 10:00', 'Wed Oct 7 10:30', 'Wed Oct 7 12:00',
        'Wed Oct 14 08:00', 'Wed Oct 14 12:20', 'Wed Oct 14 16:40',
        'Wed Oct 21 08:00', 'Wed Oct 21 14:30',
        'Wed Oct 28 08:00', 'Wed Oct 28 14:30',
        'Wed Nov 4 08:00', 'Wed Nov 4 14:30',
    ]);
});

test('calling hours are judged in each timezone', function (string $timezone, string $summerOpening, string $winterOpening) {
    $hours = callingHours();

    // 11:30 UTC is before 8:00 am in all four zones, in summer and in winter.
    expect($hours->nextOpeningAtOrAfter(utc('2026-10-07 11:30:00'), $timezone)->toDateTimeString())->toBe($summerOpening);
    expect($hours->nextOpeningAtOrAfter(utc('2026-01-14 11:30:00'), $timezone)->toDateTimeString())->toBe($winterOpening);

    expect($hours->isOpen(utc($summerOpening), $timezone))->toBeTrue();
    expect($hours->isOpen(utc($summerOpening)->subMinute(), $timezone))->toBeFalse();
    expect($hours->isOpen(utc($summerOpening)->addHours(13)->subMinute(), $timezone))->toBeTrue();
    expect($hours->isOpen(utc($summerOpening)->addHours(13), $timezone))->toBeFalse();
})->with([
    'Eastern' => [EASTERN, '2026-10-07 12:00:00', '2026-01-14 13:00:00'],
    'Central' => [CENTRAL, '2026-10-07 13:00:00', '2026-01-14 14:00:00'],
    'Arizona, which keeps one time all year' => [ARIZONA, '2026-10-07 15:00:00', '2026-01-14 15:00:00'],
    'Pacific' => [PACIFIC, '2026-10-07 15:00:00', '2026-01-14 16:00:00'],
]);

test('the same instant can be open in one timezone and closed in another', function () {
    $hours = callingHours();
    $noonUtc = utc('2026-10-07 12:30:00');

    expect($hours->isOpen($noonUtc, EASTERN))->toBeTrue();
    expect($hours->isOpen($noonUtc, CENTRAL))->toBeFalse();
    expect($hours->isOpen($noonUtc, ARIZONA))->toBeFalse();
    expect($hours->isOpen($noonUtc, PACIFIC))->toBeFalse();
});

test('the weekend clocks go forward, 8:00 am is still 8:00 am on the wall', function (string $timezone, string $sundayOpening) {
    $schedule = defaultCadence();

    // The last attempt of a cadence day is made on Saturday 7 March at 2:30 pm local.
    $saturday = CarbonImmutable::parse('2026-03-07 14:30:00', $timezone);

    expect($schedule->dueAtAfter(4, $saturday, $timezone)->toDateTimeString())->toBe($sundayOpening);

    // From 1:30 am on the Sunday, before the change, the answer is the same.
    $early = CarbonImmutable::parse('2026-03-08 01:30:00', $timezone);

    expect($schedule->hours->nextOpeningAtOrAfter($early, $timezone)->toDateTimeString())->toBe($sundayOpening);
    expect($schedule->hours->isOpen(utc($sundayOpening)->subMinute(), $timezone))->toBeFalse();

    // The spread attempts that Sunday keep their usual wall-clock times.
    $second = $schedule->dueAtAfter(5, utc($sundayOpening), $timezone);

    expect($second->setTimezone($timezone)->format('D H:i'))->toBe('Sun 12:20');
})->with([
    'Eastern' => [EASTERN, '2026-03-08 12:00:00'],
    'Central' => [CENTRAL, '2026-03-08 13:00:00'],
    'Arizona, where nothing changes' => [ARIZONA, '2026-03-08 15:00:00'],
    'Pacific' => [PACIFIC, '2026-03-08 15:00:00'],
]);

test('the weekend clocks go back, 8:00 am is still 8:00 am on the wall', function (string $timezone, string $sundayOpening) {
    $schedule = defaultCadence();

    // The last attempt of a cadence day is made on Saturday 31 October at 2:30 pm local.
    $saturday = CarbonImmutable::parse('2026-10-31 14:30:00', $timezone);

    expect($schedule->dueAtAfter(4, $saturday, $timezone)->toDateTimeString())->toBe($sundayOpening);

    // 1:30 am happens twice that night; from either one the opening is the same.
    $early = CarbonImmutable::parse('2026-11-01 01:30:00', $timezone);

    expect($schedule->hours->nextOpeningAtOrAfter($early, $timezone)->toDateTimeString())->toBe($sundayOpening);
    expect($schedule->hours->nextOpeningAtOrAfter($early->addHour(), $timezone)->toDateTimeString())->toBe($sundayOpening);
    expect($schedule->hours->isOpen(utc($sundayOpening)->subMinute(), $timezone))->toBeFalse();

    $second = $schedule->dueAtAfter(5, utc($sundayOpening), $timezone);

    expect($second->setTimezone($timezone)->format('D H:i'))->toBe('Sun 12:20');

    // Closing that Sunday is 9:00 pm on the wall, thirteen hours after opening.
    expect($schedule->hours->isOpen(utc($sundayOpening)->addHours(13)->subMinute(), $timezone))->toBeTrue();
    expect($schedule->hours->isOpen(utc($sundayOpening)->addHours(13), $timezone))->toBeFalse();
})->with([
    'Eastern' => [EASTERN, '2026-11-01 13:00:00'],
    'Central' => [CENTRAL, '2026-11-01 14:00:00'],
    'Arizona, where nothing changes' => [ARIZONA, '2026-11-01 15:00:00'],
    'Pacific' => [PACIFIC, '2026-11-01 16:00:00'],
]);

test('the fallback for an unrouted lead is Eastern, by a constant of the queue', function () {
    expect(CallingHours::UNROUTED_TIMEZONE)->toBe('America/New_York');
    expect(CallingHours::timezoneFor(null))->toBe('America/New_York');
});
