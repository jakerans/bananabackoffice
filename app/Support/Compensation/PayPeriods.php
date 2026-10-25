<?php

namespace App\Support\Compensation;

use App\Models\Location;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The pay periods of variable compensation: calendar months, kept as
 * "2026-11" (docs/slice-20-compensation-plan.md; decision 172, question 3).
 * A job's entries fall in the month in its Location's time; anything else in
 * the company's time.
 */
final class PayPeriods
{
    /**
     * The month a moment falls in, where it happened.
     */
    public static function of(CarbonInterface $moment, ?Location $location = null): string
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::timezone($location))->format('Y-m');
    }

    /**
     * The month it is now, where it happens.
     */
    public static function now(?Location $location = null): string
    {
        return self::of(now(), $location);
    }

    /**
     * The pay period an entry made now is due in: this month.
     */
    public static function open(?Location $location = null): string
    {
        return self::openFrom(self::now($location));
    }

    /**
     * The pay period an entry due in a month is paid in.
     */
    public static function openFrom(string $period): string
    {
        return $period;
    }

    public static function next(string $period): string
    {
        return self::start($period)->addMonthNoOverflow()->format('Y-m');
    }

    public static function previous(string $period): string
    {
        return self::start($period)->subMonthNoOverflow()->format('Y-m');
    }

    /**
     * The first moment of a month, in the company's time.
     */
    public static function start(string $period, ?Location $location = null): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $period, self::timezone($location)) ?: throw new \InvalidArgumentException('Not a pay period: '.$period);
    }

    /**
     * Whether a month is over, in the company's time.
     */
    public static function hasEnded(string $period): bool
    {
        return self::start(self::next($period))->lessThanOrEqualTo(now());
    }

    /**
     * As it is said: "November 2026".
     */
    public static function words(string $period): string
    {
        return self::start($period)->format('F Y');
    }

    public static function isPeriod(string $typed): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $typed) === 1;
    }

    private static function timezone(?Location $location): string
    {
        return $location === null ? (string) config('app.display_timezone') : CallingHours::timezoneFor($location);
    }
}
