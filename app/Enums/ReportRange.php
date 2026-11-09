<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * The ranges of dates a report can cover (docs/slice-32-reports-plan.md,
 * questions 3 and 8). Each is worked out from a Location's own today, so
 * "Today" is each Location's own day.
 */
enum ReportRange: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case ThisMonth = 'this_month';
    case LastMonth = 'last_month';
    case ThisQuarter = 'this_quarter';
    case YearToDate = 'year_to_date';
    case Last12Months = 'last_12_months';

    /** Any two days, chosen by the person reading the report. */
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::ThisQuarter => 'This quarter',
            self::YearToDate => 'Year to date',
            self::Last12Months => 'Last 12 months',
            self::Custom => 'Choose the days',
        };
    }

    /**
     * The first and last day covered, given today where the Location is. A
     * custom range has no days of its own and is given them by the caller.
     *
     * @return array{CarbonImmutable, CarbonImmutable}|null
     */
    public function days(CarbonImmutable $today): ?array
    {
        $today = $today->startOfDay();

        return match ($this) {
            self::Today => [$today, $today],
            self::Yesterday => [$today->subDay(), $today->subDay()],
            self::ThisMonth => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
            self::LastMonth => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::ThisQuarter => [$today->startOfQuarter(), $today->endOfQuarter()->startOfDay()],
            self::YearToDate => [$today->startOfYear(), $today],
            self::Last12Months => [$today->subMonthsNoOverflow(11)->startOfMonth(), $today],
            self::Custom => null,
        };
    }
}
