<?php

namespace App\Support\Reports;

use App\Enums\AppointmentState;
use App\Enums\LeadStatusEvent;
use App\Models\Appointment;
use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\LeadStatusChange;
use App\Models\Location;
use App\Models\Proposal;
use App\Models\SaleEntry;
use App\Models\User;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Sales > Reports > Sales figures (docs/slice-32-reports-plan.md, step 32b;
 * product-architecture.md, 5.9): for a range of days at the Locations given,
 * the figures Sales > Performance shows for a month, in total, by
 * salesperson and month by month; sold revenue by salesperson each month,
 * the Leaderboard's history; why Leads were lost; and the objections heard.
 *
 * Every figure is counted by SalesFigures, as Performance counts it, over
 * each Location's own days, then added together; each rate is worked out
 * from the sums. Whose figure each is follows decision 133.
 */
class SalesReport
{
    /**
     * @param  Collection<int, Location>  $locations  By id.
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>  $bounds  Each Location's range, by its id, in UTC.
     */
    public function __construct(private Collection $locations, private array $bounds) {}

    /**
     * @param  Collection<int, Location>  $locations
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>  $bounds
     */
    public static function for(Collection $locations, array $bounds): self
    {
        return new self($locations->keyBy('id'), $bounds);
    }

    /**
     * The figures for everyone, or one salesperson, over these bounds (the
     * range itself, unless a month's part of it is given).
     *
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>|null  $bounds
     */
    public function figures(?int $salespersonId = null, ?array $bounds = null): SalesFigures
    {
        $sum = SalesFigures::none();

        foreach ($bounds ?? $this->bounds as $locationId => [$from, $to]) {
            if ($from->lt($to) && $this->locations->has($locationId)) {
                $sum = $sum->plus(SalesFigures::between($this->locations->get($locationId), $from, $to, $salespersonId));
            }
        }

        return $sum;
    }

    /**
     * Each salesperson with anything counted in the range, and their figures,
     * the most sold revenue first.
     *
     * @return Collection<int, array{name: string, figures: SalesFigures}>
     */
    public function bySalesperson(): Collection
    {
        $names = User::query()->whereIn('id', $this->salespersonIds())->pluck('name', 'id');

        return collect($this->salespersonIds())
            ->mapWithKeys(fn (int $id): array => [$id => ['name' => (string) ($names[$id] ?? __('Former staff')), 'figures' => $this->figures($id)]])
            ->sortBy([fn (array $a, array $b): int => $b['figures']->netSoldCents() <=> $a['figures']->netSoldCents(), fn (array $a, array $b): int => strcmp($a['name'], $b['name'])]);
    }

    /**
     * Sold revenue by salesperson in each month's part of the range: the
     * Leaderboard's history, as Performance counts it.
     *
     * @param  array<string, array<int, array{CarbonImmutable, CarbonImmutable}>>  $monthBounds  Each month's part of the range, by "2026-11".
     * @return Collection<int, array{name: string, months: non-empty-array<string, int>, total: int}>
     */
    public function soldByMonth(array $monthBounds): Collection
    {
        $rows = [];

        foreach ($monthBounds as $month => $bounds) {
            foreach ($this->entriesWithin($bounds)->get(['salesperson_id', 'salesperson_name', 'kind', 'cents']) as $entry) {
                $id = (int) $entry->salesperson_id;
                $rows[$id] ??= ['name' => (string) $entry->salesperson_name, 'months' => array_fill_keys(array_keys($monthBounds), 0), 'total' => 0];
                $rows[$id]['months'][$month] += $entry->cents;
                $rows[$id]['total'] += $entry->cents;
            }
        }

        return collect($rows)->sortBy([fn (array $a, array $b): int => $b['total'] <=> $a['total'], fn (array $a, array $b): int => strcmp($a['name'], $b['name'])]);
    }

    /**
     * Why Leads were lost: those Sales marked lost in the range, by the
     * reason given, the most first.
     *
     * @return Collection<string, int>
     */
    public function lostReasons(): Collection
    {
        return $this->within(Lead::query(), 'lost_at')
            ->whereNotNull('lost_at')
            ->get(['lost_reason_name'])
            ->countBy(fn (Lead $lead): string => $lead->lost_reason_name ?? __('No reason given'))
            ->sortDesc();
    }

    /**
     * The objections heard: the estimates whose objection was recorded in the
     * range, by the objection, the most first.
     *
     * @return Collection<string, int>
     */
    public function objections(): Collection
    {
        return $this->within(Proposal::query()->counted(), 'result_at')
            ->whereNotNull('objection_name')
            ->get(['objection_name'])
            ->countBy(fn (Proposal $proposal): string => (string) $proposal->objection_name)
            ->sortDesc();
    }

    /**
     * Everyone with something counted for them in the range: a Lead issued
     * or taken back, an appointment, a sale or its correction, a Lead
     * released to Rehash.
     *
     * @return list<int>
     */
    private function salespersonIds(): array
    {
        $ids = collect()
            ->merge($this->within(LeadIssue::query(), 'issued_at')->pluck('salesperson_id'))
            ->merge($this->within(LeadIssue::query(), 'voided_at')->pluck('salesperson_id'))
            ->merge($this->within(Appointment::query()->where('state', '<>', AppointmentState::BookedInError), 'starts_at')->pluck('salesperson_id'))
            ->merge($this->entriesWithin($this->bounds)->pluck('salesperson_id'))
            ->merge($this->within(LeadStatusChange::query()->where('event', LeadStatusEvent::ReleasedToRehash), 'created_at')->pluck('assigned_user_id'));

        return array_values($ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->sort()->all());
    }

    /**
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>  $bounds
     * @return Builder<SaleEntry>
     */
    private function entriesWithin(array $bounds): Builder
    {
        return $this->within(SaleEntry::query(), 'occurred_at', $bounds);
    }

    /**
     * Narrow a query to the shown Locations, each within its own range.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>|null  $bounds
     * @return Builder<TModel>
     */
    private function within(Builder $query, string $column, ?array $bounds = null): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $any) use ($bounds, $column, $table): void {
            $any->whereRaw('1 = 0');

            foreach ($bounds ?? $this->bounds as $locationId => [$from, $to]) {
                $any->orWhere(fn (Builder $here) => $here->where($table.'.location_id', $locationId)->where($table.'.'.$column, '>=', $from)->where($table.'.'.$column, '<', $to));
            }
        });
    }
}
