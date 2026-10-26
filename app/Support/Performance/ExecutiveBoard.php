<?php

namespace App\Support\Performance;

use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\LocationSalesTarget;
use App\Support\Compensation\PayPeriods;
use App\Support\Compensation\ProductionBonus;
use App\Support\Queue\CallingHours;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Company > Executive Board (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25c; decision 183, questions 6 and 7; product-architecture.md, 18):
 * each Location's key numbers for a month, side by side, and the company's
 * together, each KPI with its own status and no single health score.
 */
final readonly class ExecutiveBoard
{
    public const string ON_PACE = 'on_pace';

    public const string BEHIND = 'behind';

    public const string NO_TARGET = 'no_target';

    /**
     * @param  Collection<int, Location>  $locations
     */
    private function __construct(public Collection $locations, public string $month) {}

    /**
     * @param  Collection<int, Location>  $locations
     */
    public static function for(Collection $locations, string $month): self
    {
        return new self($locations, $month);
    }

    /**
     * One column for each Location, then the company's.
     *
     * @return list<array{name: string, sold: int, target: int|null, pace: string, completed: int, margin: int, marginPercent: float|null, targetMargin: int|null, cash: int, late: int, collections: int, leads: int, costPerLead: int|null, atRisk: int, booksIncome: int|null, netIncome: int|null, netProfit: int|null}>
     */
    public function columns(): array
    {
        $columns = array_values($this->locations->map(fn (Location $location): array => $this->column($location))->all());

        if (count($columns) < 2) {
            return $columns;
        }

        $sum = fn (string $key): int => (int) array_sum(array_column($columns, $key));
        $targets = array_filter(array_column($columns, 'target'), fn (?int $target): bool => $target !== null);
        $target = $targets === [] ? null : (int) array_sum($targets);
        $marketing = MarketingBoard::for($this->locations, $this->month)->total();

        $columns[] = [
            'name' => 'Company',
            'sold' => $sum('sold'),
            'target' => $target,
            'pace' => $this->pace($sum('sold'), $target),
            'completed' => $sum('completed'),
            'margin' => $sum('margin'),
            'marginPercent' => ProductionBonus::marginPercent($sum('completed'), $sum('margin')),
            'targetMargin' => null,
            'cash' => $sum('cash'),
            'late' => $sum('late'),
            'collections' => $sum('collections'),
            'leads' => $sum('leads'),
            'costPerLead' => $marketing->costPerLeadCents(),
            'atRisk' => $sum('atRisk'),
            ...$this->companyBooks($columns),
        ];

        return $columns;
    }

    /**
     * This month's top three on the leaderboard, company-wide.
     *
     * @return Collection<int, array{id: int, name: string, cents: int}>
     */
    public function leaders(): Collection
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month, (string) config('app.display_timezone')) ?: CarbonImmutable::now();

        return SalesLeaderboard::standings($start, SalesLeaderboard::next(SalesLeaderboard::MONTH, $start))->take(3)->values();
    }

    /**
     * @return array{name: string, sold: int, target: int|null, pace: string, completed: int, margin: int, marginPercent: float|null, targetMargin: int|null, cash: int, late: int, collections: int, leads: int, costPerLead: int|null, atRisk: int, booksIncome: int|null, netIncome: int|null, netProfit: int|null}
     */
    private function column(Location $location): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $this->month, CallingHours::timezoneFor($location)) ?: CarbonImmutable::now();
        $sold = SalesFigures::for($location, $start)->netSoldCents();
        $target = LocationSalesTarget::for($location->id, $this->month);
        $production = ProductionBoard::for([$location->id], $this->month);
        $completed = $production->completed();
        $owed = $production->owed();
        $marketing = MarketingBoard::for(collect([$location]), $this->month)->total();

        return [
            'name' => $location->name,
            'sold' => $sold,
            'target' => $target,
            'pace' => $this->pace($sold, $target),
            'completed' => $completed['revenue'],
            'margin' => $completed['margin'],
            'marginPercent' => $completed['percent'],
            'targetMargin' => $location->target_margin_percent,
            'cash' => FinanceBoard::for([$location->id], $this->month)->cashCollected()['cents'],
            'late' => $owed['late'] - $owed['collections'],
            'collections' => $owed['collections'],
            'leads' => $marketing->leads,
            'costPerLead' => $marketing->costPerLeadCents(),
            'atRisk' => $production->atRisk()->count(),
            ...$this->books($location),
        ];
    }

    /**
     * A Location's income and net income as its QuickBooks company last
     * reported them for the month, and its Net Profit % in hundredths of a
     * percent (slice 28, step 28c); nothing when not read.
     *
     * @return array{booksIncome: int|null, netIncome: int|null, netProfit: int|null}
     */
    private function books(Location $location): array
    {
        $statement = LocationProfitAndLoss::query()->where('location_id', $location->id)->where('month', $this->month)->first();

        return [
            'booksIncome' => $statement?->income_cents,
            'netIncome' => $statement?->net_income_cents,
            'netProfit' => $statement?->netProfitBasisPoints(),
        ];
    }

    /**
     * The company's, adding up the Locations whose QuickBooks has been read.
     *
     * @param  list<array{booksIncome: int|null, netIncome: int|null}>  $columns
     * @return array{booksIncome: int|null, netIncome: int|null, netProfit: int|null}
     */
    private function companyBooks(array $columns): array
    {
        $read = array_filter($columns, fn (array $column): bool => $column['booksIncome'] !== null);

        if ($read === []) {
            return ['booksIncome' => null, 'netIncome' => null, 'netProfit' => null];
        }

        $income = (int) array_sum(array_column($read, 'booksIncome'));
        $net = (int) array_sum(array_column($read, 'netIncome'));

        return ['booksIncome' => $income, 'netIncome' => $net, 'netProfit' => LocationProfitAndLoss::percent($income, $net)];
    }

    /**
     * Sold revenue against the month's target so far (question 7): on pace
     * when at or above the share of the target for the days gone; a month
     * that is over needs all of it.
     */
    private function pace(int $sold, ?int $target): string
    {
        if ($target === null) {
            return self::NO_TARGET;
        }

        $now = CarbonImmutable::now((string) config('app.display_timezone'));
        $share = $this->month < PayPeriods::now() ? 1.0 : ($this->month > PayPeriods::now() ? 0.0 : $now->day / $now->daysInMonth);

        return $sold >= (int) round($target * $share) ? self::ON_PACE : self::BEHIND;
    }
}
