<?php

namespace App\Support\Queue;

use App\Models\ContactCenterSettings;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The company's calling hours and calling days, applied in one timezone at a
 * time: the Lead's Location's, or Eastern while the Lead is unrouted.
 *
 * A calling window is [open, close) on a ticked calling day. Hours never go
 * outside 8:00 am to 9:00 pm and clocks change at 2:00 am, so a window never
 * contains a clock change. Every instant given back is in UTC.
 */
final readonly class CallingHours
{
    /**
     * The timezone an unrouted Lead is judged in. A constant of the queue, and
     * deliberately not the company display timezone: changing how times are
     * shown must never move calling hours.
     */
    public const string UNROUTED_TIMEZONE = 'America/New_York';

    /**
     * @param  int  $opensAt  Minutes after midnight.
     * @param  int  $closesAt  Minutes after midnight.
     * @param  list<int>  $days  ISO weekday numbers, 1 (Monday) to 7 (Sunday).
     */
    public function __construct(
        public int $opensAt,
        public int $closesAt,
        public array $days,
    ) {}

    public static function fromSettings(ContactCenterSettings $settings): self
    {
        return new self(
            $settings->calling_opens_at,
            $settings->calling_closes_at,
            array_map(intval(...), $settings->calling_days),
        );
    }

    /**
     * The timezone a Lead at this Location is judged in: the Location's own,
     * or Eastern for an unrouted Lead.
     */
    public static function timezoneFor(?Location $location): string
    {
        if ($location === null || ! LocationTimezones::isAllowed($location->timezone)) {
            return self::UNROUTED_TIMEZONE;
        }

        return $location->timezone;
    }

    /**
     * How long one calling window is, in minutes.
     */
    public function windowMinutes(): int
    {
        return $this->closesAt - $this->opensAt;
    }

    /**
     * Whether the instant is inside a calling window in the given timezone.
     */
    public function isOpen(CarbonInterface $instant, string $timezone): bool
    {
        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);

        if (! $this->isCallingDay($local)) {
            return false;
        }

        $minutes = $local->hour * 60 + $local->minute;

        return $minutes >= $this->opensAt && $minutes < $this->closesAt;
    }

    /**
     * The next opening at or after an instant: the instant itself when it is
     * inside a window, otherwise the start of the next window.
     */
    public function nextOpeningAtOrAfter(CarbonInterface $instant, string $timezone): CarbonImmutable
    {
        if ($this->isOpen($instant, $timezone)) {
            return CarbonImmutable::instance($instant)->utc();
        }

        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);

        if ($this->isCallingDay($local) && $local->hour * 60 + $local->minute < $this->opensAt) {
            return $this->openingOn($local);
        }

        return $this->openingOfFirstCallingDayAfter($instant, $timezone);
    }

    /**
     * The opening of the first calling day after the local date of an instant.
     */
    public function openingOfFirstCallingDayAfter(CarbonInterface $instant, string $timezone): CarbonImmutable
    {
        $day = CarbonImmutable::instance($instant)->setTimezone($timezone)->startOfDay();

        for ($ahead = 1; $ahead <= 7; $ahead++) {
            $candidate = $day->addDays($ahead);

            if ($this->isCallingDay($candidate)) {
                return $this->openingOn($candidate);
            }
        }

        throw new LogicException('The calling days are empty, so there is no next calling day.');
    }

    private function isCallingDay(CarbonImmutable $local): bool
    {
        return in_array($local->dayOfWeekIso, $this->days, true);
    }

    /**
     * The opening time on a local date, by the clock on the wall there.
     */
    private function openingOn(CarbonImmutable $local): CarbonImmutable
    {
        return $local->setTime(intdiv($this->opensAt, 60), $this->opensAt % 60)->utc();
    }
}
