<?php

namespace App\Support\Reports;

use App\Models\CompensationEntry;
use App\Models\Location;
use App\Models\LocationProfitAndLoss;
use App\Models\PayrollPeriod;
use App\Models\Project;
use App\Models\ProjectJobCostingStep;
use App\Models\ProjectPayment;
use App\Support\Performance\FinanceBoard;
use App\Support\Projects\JobCosting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finance > Reports (docs/slice-32-reports-plan.md, step 32d;
 * product-architecture.md, 9.7; decision 222): for a range of days at the
 * Locations given, each Location's profit and loss from QuickBooks side by
 * side, what customers owe now by age, cash collected month by month, gross
 * profit and job-cost variance on the jobs financially closed, and the pay
 * owed and not yet paid.
 *
 * Days are the company's, as on Finance > Performance. Totals and staff
 * names only: never a customer.
 */
class FinanceReport
{
    /**
     * @param  Collection<int, Location>  $locations
     */
    public function __construct(private Collection $locations, private CarbonImmutable $first, private CarbonImmutable $last) {}

    /**
     * @param  Collection<int, Location>  $locations
     */
    public static function for(Collection $locations, CarbonImmutable $first, CarbonImmutable $last): self
    {
        return new self($locations->sortBy('name')->values(), $first->startOfDay(), $last->startOfDay());
    }

    /**
     * The months the range touches, as "2026-11", oldest first.
     *
     * @return list<string>
     */
    public function months(): array
    {
        $months = [];

        for ($month = $this->first->startOfMonth(); $month->lte($this->last); $month = $month->addMonthNoOverflow()) {
            $months[] = $month->format('Y-m');
        }

        return $months;
    }

    /**
     * Each Location's profit and loss from QuickBooks over the months the
     * range touches, side by side, and the months QuickBooks has not been
     * read for. QuickBooks reports a whole month, so a month the range only
     * touches counts whole.
     *
     * @return Collection<int, array{location: string, income: int, cost: int, expenses: int, operating: int, net: int, months: int, missing: int}>
     */
    public function profitAndLoss(): Collection
    {
        $read = LocationProfitAndLoss::query()
            ->whereIn('location_id', $this->locations->pluck('id')->all())
            ->whereIn('month', $this->months())
            ->get()
            ->groupBy('location_id');

        return $this->locations->mapWithKeys(function (Location $location) use ($read): array {
            $months = $read->get($location->id, collect());

            return [$location->id => [
                'location' => $location->name,
                'income' => (int) $months->sum('income_cents'),
                'cost' => (int) $months->sum('cost_of_goods_cents'),
                'expenses' => (int) $months->sum('expenses_cents'),
                'operating' => (int) $months->sum('net_operating_income_cents'),
                'net' => (int) $months->sum('net_income_cents'),
                'months' => $months->count(),
                'missing' => count($this->months()) - $months->count(),
            ]];
        });
    }

    /**
     * Cash collected each month of the range: the payments that count,
     * approved and not reversed, by the day received, as on Finance >
     * Performance.
     *
     * @return array<string, array{cents: int, payments: int}>
     */
    public function cashByMonth(): array
    {
        $payments = ProjectPayment::query()
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->locations->pluck('id')->all()))
            ->whereBetween('received_on', [$this->first->toDateString(), $this->last->toDateString()])
            ->whereNull('reversed_at')
            ->where(fn (Builder $query) => $query->where('needs_approval', false)->orWhereNotNull('approved_at'))
            ->get(['amount_cents', 'received_on']);

        $months = array_fill_keys($this->months(), ['cents' => 0, 'payments' => 0]);

        foreach ($payments as $payment) {
            $month = $payment->received_on->format('Y-m');
            $months[$month] = [
                'cents' => ($months[$month]['cents'] ?? 0) + $payment->amount_cents,
                'payments' => ($months[$month]['payments'] ?? 0) + 1,
            ];
        }

        return $months;
    }

    /**
     * What customers owe now on final invoices, by age, for each Location,
     * as on Finance > Performance. It is how things stand today, whatever
     * the range.
     *
     * @return Collection<int, array{location: string, notDue: int, late: int, collections: int, total: int, jobs: int}>
     */
    public function aging(): Collection
    {
        return $this->locations->mapWithKeys(fn (Location $location): array => [$location->id => ['location' => $location->name, ...FinanceBoard::for([$location->id], now()->format('Y-m'))->aging()]]);
    }

    /**
     * The jobs whose final gross margin Finance approved in the range and
     * that stand approved now, by Location: their revenue, the budget each
     * was set (the target cost), what they cost, the gross profit, and how
     * far over or under budget they came in (6.9, 9.3).
     *
     * @return Collection<int, array{location: string, jobs: int, revenue: int, budget: int, cost: int, profit: int, variance: int, overBudget: int}>
     */
    public function jobCosts(): Collection
    {
        $approvals = ProjectJobCostingStep::query()
            ->where('kind', ProjectJobCostingStep::KIND_APPROVED)
            ->where('created_at', '>=', CarbonImmutable::parse($this->first->toDateString(), (string) config('app.display_timezone'))->utc())
            ->where('created_at', '<', CarbonImmutable::parse($this->last->toDateString(), (string) config('app.display_timezone'))->addDay()->utc())
            ->whereHas('project', fn (Builder $project) => $project->whereIn('location_id', $this->locations->pluck('id')->all()))
            ->orderBy('id')
            ->get()
            ->keyBy('project_id');

        $closed = Project::query()
            ->whereIn('id', $approvals->keys())
            ->with('location')
            ->get()
            ->map(fn (Project $project): JobCosting => JobCosting::for($project))
            ->filter(fn (JobCosting $costing): bool => $costing->final()?->id === $approvals->get($costing->project->id)?->id);

        return $this->locations->mapWithKeys(function (Location $location) use ($closed): array {
            $here = $closed->filter(fn (JobCosting $costing): bool => $costing->project->location_id === $location->id);
            $cost = fn (JobCosting $costing): int => $costing->final()->payouts_cents + $costing->final()->costs_cents;

            return [$location->id => [
                'location' => $location->name,
                'jobs' => $here->count(),
                'revenue' => (int) $here->sum(fn (JobCosting $costing): int => $costing->final()->revenue_cents),
                'budget' => (int) $here->sum(fn (JobCosting $costing): int => $costing->targetCostCents()),
                'cost' => (int) $here->sum($cost),
                'profit' => (int) $here->sum(fn (JobCosting $costing): int => $costing->final()->margin_cents),
                'variance' => (int) $here->sum(fn (JobCosting $costing): int => $costing->targetCostCents() - $cost($costing)),
                'overBudget' => $here->filter(fn (JobCosting $costing): bool => $cost($costing) > $costing->targetCostCents())->count(),
            ]];
        });
    }

    /**
     * Variable pay owed and not yet paid now, company-wide, for each person:
     * due in pay periods still open, and scheduled in finalized periods not
     * yet paid (slice 20), as on Finance > Performance.
     *
     * @return Collection<int, array{name: string, open: int, scheduled: int}>
     */
    public static function payOwed(): Collection
    {
        $finalized = PayrollPeriod::query()->get(['period', 'paid_at']);
        $scheduled = $finalized->whereNull('paid_at')->pluck('period')->all();
        $closedPeriods = $finalized->pluck('period')->all();

        return CompensationEntry::query()
            ->whereNotNull('period')
            ->where(fn (Builder $query) => $query->whereNotIn('period', $closedPeriods)->orWhereIn('period', $scheduled))
            ->get(['user_id', 'user_name', 'period', 'amount_cents'])
            ->groupBy('user_id')
            ->map(fn (Collection $entries): array => [
                'name' => (string) $entries->last()?->user_name,
                'open' => (int) $entries->reject(fn (CompensationEntry $entry): bool => in_array($entry->period, $closedPeriods, true))->sum('amount_cents'),
                'scheduled' => (int) $entries->filter(fn (CompensationEntry $entry): bool => in_array($entry->period, $scheduled, true))->sum('amount_cents'),
            ])
            ->filter(fn (array $person): bool => $person['open'] !== 0 || $person['scheduled'] !== 0)
            ->sortBy('name');
    }
}
