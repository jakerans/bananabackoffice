<?php

namespace App\Support\Scorecards;

use App\Models\Location;
use App\Support\Queue\CallingHours;
use Carbon\CarbonImmutable;

/**
 * A scorecard's week (docs/slice-27-leadership-scorecards-plan.md, step 27a;
 * decision 187, question 4): Monday to Sunday in its Location's calendar,
 * named by its Monday, "2026-10-19". Its numbers are its month's so far,
 * the month its Sunday is in. It is due by the end of the Monday after it.
 */
final class ScorecardWeek
{
    /**
     * The Monday of the last week that has ended at a Location, or in the
     * company's own time for none.
     */
    public static function lastEnded(?Location $location): CarbonImmutable
    {
        return self::today($location)->startOfWeek(CarbonImmutable::MONDAY)->subWeek();
    }

    /**
     * The Monday a typed week names, when it is one; nothing otherwise.
     */
    public static function parse(?string $typed): ?CarbonImmutable
    {
        if ($typed === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $typed) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $typed);

        return $day !== null && $day->format('Y-m-d') === $typed && $day->isMonday() ? $day : null;
    }

    public static function hasEnded(Location $location, CarbonImmutable $monday): bool
    {
        return self::today($location)->greaterThanOrEqualTo($monday->addWeek());
    }

    /**
     * Whether its scorecard is overdue: the Monday after it has passed.
     */
    public static function isOverdue(Location $location, CarbonImmutable $monday): bool
    {
        return self::today($location)->greaterThanOrEqualTo($monday->addDays(8));
    }

    public static function sunday(CarbonImmutable $monday): CarbonImmutable
    {
        return $monday->addDays(6);
    }

    /**
     * The month whose numbers it shows: "2026-10".
     */
    public static function month(CarbonImmutable $monday): string
    {
        return self::sunday($monday)->format('Y-m');
    }

    /**
     * "19 to 25 October", or "28 September to 4 October".
     */
    public static function words(CarbonImmutable $monday): string
    {
        $sunday = self::sunday($monday);

        return $monday->month === $sunday->month
            ? $monday->format('j').' to '.$sunday->format('j F')
            : $monday->format('j F').' to '.$sunday->format('j F');
    }

    /**
     * The weeks that have ended, the latest first.
     *
     * @return list<CarbonImmutable>
     */
    public static function recent(?Location $location, int $count = 8): array
    {
        $last = self::lastEnded($location);

        return array_map(fn (int $back): CarbonImmutable => $last->subWeeks($back), range(0, $count - 1));
    }

    private static function today(?Location $location): CarbonImmutable
    {
        return CarbonImmutable::parse(now()->timezone(CallingHours::timezoneFor($location))->toDateString());
    }
}
