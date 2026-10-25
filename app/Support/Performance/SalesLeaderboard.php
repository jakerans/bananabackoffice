<?php

namespace App\Support\Performance;

use App\Models\SaleEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The Sales leaderboard (docs/slice-23-performance-boards-plan.md, step 23c;
 * decision 179, questions 7 and 8; product-architecture.md, 5.8):
 * company-wide sold revenue by salesperson for the month, quarter and year
 * to date, in the company's time, counted as Sales > Performance counts it,
 * corrections taken off in the period they are made. A champion has the
 * most in a finished period; a tie shares it.
 */
final class SalesLeaderboard
{
    public const string MONTH = 'month';

    public const string QUARTER = 'quarter';

    public const string YEAR = 'year';

    /** How many finished periods of each kind the winners' history goes back. */
    public const array HISTORY = [self::MONTH => 12, self::QUARTER => 4, self::YEAR => 2];

    /**
     * The start of the period of a kind that holds a moment.
     */
    public static function start(string $kind, CarbonImmutable $moment): CarbonImmutable
    {
        return match ($kind) {
            self::QUARTER => $moment->startOfQuarter(),
            self::YEAR => $moment->startOfYear(),
            default => $moment->startOfMonth(),
        };
    }

    public static function next(string $kind, CarbonImmutable $start): CarbonImmutable
    {
        return match ($kind) {
            self::QUARTER => $start->addQuarterNoOverflow(),
            self::YEAR => $start->addYearNoOverflow(),
            default => $start->addMonthNoOverflow(),
        };
    }

    public static function previous(string $kind, CarbonImmutable $start): CarbonImmutable
    {
        return match ($kind) {
            self::QUARTER => $start->subQuarterNoOverflow(),
            self::YEAR => $start->subYearNoOverflow(),
            default => $start->subMonthNoOverflow(),
        };
    }

    /**
     * The period as it is said: "November 2026", "Q4 2026", "2026".
     */
    public static function words(string $kind, CarbonImmutable $start): string
    {
        return match ($kind) {
            self::QUARTER => 'Q'.$start->quarter.' '.$start->year,
            self::YEAR => (string) $start->year,
            default => $start->format('F Y'),
        };
    }

    /**
     * Sold revenue by salesperson from a moment until another, the most
     * first, leaving out anyone whose net is nothing.
     *
     * @return Collection<int, array{id: int, name: string, cents: int}>
     */
    public static function standings(CarbonImmutable $from, CarbonImmutable $until): Collection
    {
        return SaleEntry::query()
            ->whereNotNull('salesperson_id')
            ->where('occurred_at', '>=', $from->utc())
            ->where('occurred_at', '<', $until->utc())
            ->get(['salesperson_id', 'salesperson_name', 'cents', 'occurred_at'])
            ->groupBy('salesperson_id')
            ->reject(fn (Collection $entries): bool => (int) $entries->sum('cents') === 0)
            ->map(fn (Collection $entries, int $id): array => [
                'id' => $id,
                'name' => (string) $entries->sortBy('occurred_at')->last()?->salesperson_name,
                'cents' => (int) $entries->sum('cents'),
            ])
            ->sortBy([['cents', 'desc'], ['name', 'asc']])
            ->values();
    }

    /**
     * The champions of a finished period: everyone with the most, above
     * nothing.
     *
     * @return Collection<int, array{id: int, name: string, cents: int}>
     */
    public static function champions(string $kind, CarbonImmutable $start): Collection
    {
        $standings = self::standings($start, self::next($kind, $start));
        $best = $standings->first()['cents'] ?? 0;

        return $best <= 0 ? collect() : $standings->where('cents', $best)->values();
    }

    /**
     * The champions of each finished period of a kind, the latest first.
     *
     * @return list<array{period: string, champions: Collection<int, array{id: int, name: string, cents: int}>}>
     */
    public static function history(string $kind, CarbonImmutable $now): array
    {
        $start = self::start($kind, $now);
        $rows = [];

        for ($back = 0; $back < self::HISTORY[$kind]; $back++) {
            $start = self::previous($kind, $start);
            $rows[] = ['period' => self::words($kind, $start), 'champions' => self::champions($kind, $start)];
        }

        return $rows;
    }
}
