<?php

namespace App\Support\Queue;

use App\Models\ContactCenterSettings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Works out when an attempt is due, from the cadence and calling hours in
 * force at that moment (docs/slice-4-plan.md, "How an attempt is scheduled").
 *
 * The cadence is a list of cadence days. Day 1 is timed: a list of waits in
 * minutes, the first always 0. Every later day is spread: a number of attempts
 * at equal gaps through the calling window.
 *
 * Nothing here stores anything, and nothing is worked out in advance: one
 * attempt at a time, when the one before it is counted.
 */
final readonly class CadenceSchedule
{
    /**
     * @param  list<array{type: string, waits_minutes?: list<int>, attempts?: int}>  $cadence
     */
    public function __construct(
        public array $cadence,
        public CallingHours $hours,
    ) {}

    public static function fromSettings(ContactCenterSettings $settings): self
    {
        return new self($settings->cadence, CallingHours::fromSettings($settings));
    }

    public function totalAttempts(): int
    {
        return ContactCenterSettings::countAttempts($this->cadence);
    }

    /**
     * When attempt 1 is due: the next opening at or after the moment the Lead
     * enters. A Lead that arrives outside calling hours waits for the next
     * opening, and that day is its day 1.
     */
    public function firstDueAt(CarbonInterface $enteredAt, string $timezone): CarbonImmutable
    {
        return $this->hours->nextOpeningAtOrAfter($enteredAt, $timezone);
    }

    /**
     * When a later attempt is due, given when the one before it was made. Null
     * when the cadence holds no such attempt.
     *
     * The first attempt of a new cadence day is due at the opening of the first
     * calling day after the local date of the previous attempt. Any other is
     * due one gap after the previous attempt, or at the next opening when that
     * falls outside calling hours; it keeps its place, so nothing is skipped.
     */
    public function dueAtAfter(int $step, CarbonInterface $previousAttemptAt, string $timezone): ?CarbonImmutable
    {
        $position = $this->positionOf($step);

        if ($position === null) {
            return null;
        }

        [$day, $index] = $position;

        if ($index === 0) {
            return $this->hours->openingOfFirstCallingDayAfter($previousAttemptAt, $timezone);
        }

        $earliest = CarbonImmutable::instance($previousAttemptAt)->utc()->addSeconds($this->gapSeconds($day, $index));

        return $this->hours->nextOpeningAtOrAfter($earliest, $timezone);
    }

    /**
     * Which cadence day an attempt belongs to, and its place in that day, both
     * counted from zero. Null when the cadence has fewer attempts than that.
     *
     * @return array{0: int, 1: int}|null
     */
    public function positionOf(int $step): ?array
    {
        if ($step < 1) {
            return null;
        }

        $remaining = $step - 1;

        foreach ($this->cadence as $day => $settings) {
            $attempts = $this->attemptsOn($settings);

            if ($remaining < $attempts) {
                return [$day, $remaining];
            }

            $remaining -= $attempts;
        }

        return null;
    }

    /**
     * The wait before an attempt that is not the first of its cadence day: its
     * configured wait on a timed day, an equal share of the window on a spread day.
     */
    private function gapSeconds(int $day, int $index): int
    {
        $settings = $this->cadence[$day];

        if ($settings['type'] === 'timed') {
            return (int) ($settings['waits_minutes'][$index] ?? 0) * 60;
        }

        return intdiv($this->hours->windowMinutes() * 60, max(1, $this->attemptsOn($settings)));
    }

    /**
     * @param  array{type: string, waits_minutes?: list<int>, attempts?: int}  $settings
     */
    private function attemptsOn(array $settings): int
    {
        return $settings['type'] === 'timed'
            ? count($settings['waits_minutes'] ?? [])
            : (int) ($settings['attempts'] ?? 0);
    }
}
