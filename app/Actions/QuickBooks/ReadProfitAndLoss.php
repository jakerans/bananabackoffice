<?php

namespace App\Actions\QuickBooks;

use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\QuickBooksConnection;
use App\Support\Queue\CallingHours;
use App\Support\QuickBooks\ProfitAndLossReport;
use App\Support\QuickBooks\QuickBooksClient;
use App\Support\QuickBooks\QuickBooksUnavailable;
use Carbon\CarbonImmutable;

/**
 * Read a Location's profit and loss for a month from its QuickBooks company
 * (docs/slice-28-quickbooks-plan.md, step 28b; decision 189, questions 5 and
 * 6): the month so far, in the Location's calendar, on the accrual basis,
 * kept with when it was read. Read again, it replaces what was read before.
 */
class ReadProfitAndLoss
{
    public function __construct(private QuickBooksClient $client) {}

    /**
     * @throws QuickBooksUnavailable
     */
    public function handle(Location $location, string $month): LocationProfitAndLoss
    {
        $connection = QuickBooksConnection::query()->with('location')->where('location_id', $location->id)->first()
            ?? throw new QuickBooksUnavailable(__(':location is not connected to QuickBooks.', ['location' => $location->name]));

        $timezone = CallingHours::timezoneFor($location);
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, $timezone) ?? throw new QuickBooksUnavailable(__('That month cannot be read.'));
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $end = $start->endOfMonth()->startOfDay()->min($today);

        $totals = ProfitAndLossReport::totals($this->client->get($connection, 'reports/ProfitAndLoss', [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'accounting_method' => 'Accrual',
        ]));

        $statement = LocationProfitAndLoss::query()->where('location_id', $location->id)->where('month', $month)->first() ?? new LocationProfitAndLoss;
        $statement->forceFill([
            'location_id' => $location->id,
            'month' => $month,
            'income_cents' => $totals['income'],
            'cost_of_goods_cents' => $totals['costOfGoods'],
            'expenses_cents' => $totals['expenses'],
            'net_operating_income_cents' => $totals['netOperatingIncome'],
            'net_income_cents' => $totals['netIncome'],
            'read_at' => now(),
        ])->save();

        return $statement;
    }

    /**
     * Read this month and last month for every connected Location, or the
     * ones given. A Location QuickBooks cannot answer for is skipped and
     * named; the others are still read.
     *
     * @param  list<int>|null  $locationIds
     * @return array{read: int, failed: list<string>}
     */
    public function recent(?array $locationIds = null): array
    {
        $read = 0;
        $failed = [];

        $connections = QuickBooksConnection::query()
            ->with('location')
            ->when($locationIds !== null, fn ($query) => $query->whereIn('location_id', $locationIds))
            ->get();

        foreach ($connections as $connection) {
            $thisMonth = CarbonImmutable::now(CallingHours::timezoneFor($connection->location))->startOfMonth();

            try {
                foreach ([$thisMonth->subMonth(), $thisMonth] as $month) {
                    $this->handle($connection->location, $month->format('Y-m'));
                    $read++;
                }
            } catch (QuickBooksUnavailable) {
                $failed[] = $connection->location->name;
            }
        }

        return ['read' => $read, 'failed' => $failed];
    }
}
