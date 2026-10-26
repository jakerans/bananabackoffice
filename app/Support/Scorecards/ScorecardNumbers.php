<?php

namespace App\Support\Scorecards;

use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\LocationSalesTarget;
use App\Support\Performance\MarketingBoard;
use App\Support\Performance\ProductionBoard;
use App\Support\Queue\CallingHours;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;

/**
 * The KPIs of a Location's weekly scorecard, as Backoffice works them out
 * (docs/slice-27-leadership-scorecards-plan.md, "The first scorecard";
 * decision 187, question 2; product-architecture.md, 17.3): the provisional
 * "weekly five". Net Profit % comes from the Location's QuickBooks company
 * (slice 28, step 28c). Each is its month's so far, from the same figures as the boards.
 *
 * Amounts are in cents; a percentage is in hundredths of a percent.
 */
final class ScorecardNumbers
{
    public const string SOLD = 'sold_revenue';

    public const string GROSS_PROFIT = 'gross_profit';

    public const string NET_PROFIT = 'net_profit';

    public const string NSLI = 'nsli';

    public const string COST_PER_CLOSE = 'cost_per_close';

    /** @var array<string, string> */
    public const array KPIS = [
        self::SOLD => 'Sold Revenue vs Plan',
        self::GROSS_PROFIT => 'Gross Profit %',
        self::NET_PROFIT => 'Net Profit %',
        self::NSLI => 'NSLI',
        self::COST_PER_CLOSE => 'Blended Cost per Close',
    ];

    /**
     * @return list<array{kpi: string, actual: int|null, target: int|null, due: int|null, onTarget: bool|null}>
     */
    public static function for(Location $location, CarbonImmutable $monday): array
    {
        $month = ScorecardWeek::month($monday);
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, CallingHours::timezoneFor($location)) ?: CarbonImmutable::now();
        $sunday = ScorecardWeek::sunday($monday);
        $sales = SalesFigures::for($location, $start);

        $sold = $sales->netSoldCents();
        $soldTarget = LocationSalesTarget::for($location->id, $month);
        $soldDue = $soldTarget === null ? null : (int) round($soldTarget * $sunday->day / $sunday->daysInMonth);

        $percent = ProductionBoard::for([$location->id], $month)->completed()['percent'];
        $grossProfit = $percent === null ? null : (int) round($percent * 100);
        $grossProfitTarget = $location->target_margin_percent * 100;

        $netProfit = LocationProfitAndLoss::query()->where('location_id', $location->id)->where('month', $month)->first()?->netProfitBasisPoints();
        $netProfitTarget = $location->net_profit_target_percent === null ? null : $location->net_profit_target_percent * 100;

        $nsli = $sales->nsliCents();
        $costPerClose = MarketingBoard::for(collect([$location]), $month)->total()->costPerSaleCents();

        return [
            self::line(self::SOLD, $sold, $soldTarget, $soldDue, $soldDue === null ? null : $sold >= $soldDue),
            self::line(self::GROSS_PROFIT, $grossProfit, $grossProfitTarget, null, $grossProfit === null ? null : $grossProfit >= $grossProfitTarget),
            self::line(self::NET_PROFIT, $netProfit, $netProfitTarget, null, $netProfit === null || $netProfitTarget === null ? null : $netProfit >= $netProfitTarget),
            self::line(self::NSLI, $nsli, $location->nsli_target_cents, null, $nsli === null || $location->nsli_target_cents === null ? null : $nsli >= $location->nsli_target_cents),
            self::line(self::COST_PER_CLOSE, $costPerClose, $location->cost_per_close_target_cents, null, $costPerClose === null || $location->cost_per_close_target_cents === null ? null : $costPerClose <= $location->cost_per_close_target_cents),
        ];
    }

    /**
     * A KPI's amount in words: "$12,400", "52.5%".
     */
    public static function format(string $kpi, ?int $value): string
    {
        if ($value === null) {
            return '—';
        }

        return in_array($kpi, [self::GROSS_PROFIT, self::NET_PROFIT], true)
            ? rtrim(rtrim(number_format($value / 100, 1), '0'), '.').'%'
            : '$'.number_format(intdiv($value, 100));
    }

    /**
     * @return array{kpi: string, actual: int|null, target: int|null, due: int|null, onTarget: bool|null}
     */
    private static function line(string $kpi, ?int $actual, ?int $target, ?int $due, ?bool $onTarget): array
    {
        return ['kpi' => $kpi, 'actual' => $actual, 'target' => $target, 'due' => $due, 'onTarget' => $onTarget];
    }
}
