<?php

namespace App\Support\Reports;

use App\Enums\ContactAttemptDirection;
use App\Models\ContactAttempt;
use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSource;
use App\Models\MarketingSpendEntry;
use App\Models\SaleEntry;
use App\Support\Queue\CallingHours;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Marketing > Reports (docs/slice-32-reports-plan.md, step 32c;
 * product-architecture.md, 4.6 and 21; decision 222): for a range of days
 * at the Locations given, by Lead Source or by Marketing Source, the spend,
 * leads received, appointments and booking rate, sales, close rate and sold
 * revenue, as Marketing > Performance counts them, with the costs and the
 * return worked out from them; and how quickly leads were first called and
 * how many were reached.
 *
 * Spend is entered for a month (slice 25), so a range that holds only part
 * of a month counts that share of its spend, by its days.
 */
class MarketingReport
{
    public const string BY_LEAD_SOURCE = 'lead_source';

    public const string BY_MARKETING_SOURCE = 'marketing_source';

    /** Speed to lead, in minutes: the upper end of each band but the last. */
    public const array SPEED_BANDS = [5 => 'Within 5 minutes', 30 => '5 to 30 minutes', 120 => '30 minutes to 2 hours', 1440 => '2 to 24 hours'];

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
     * One row for each source with anything in the range, the most spent
     * first, then the most leads.
     *
     * @return Collection<int, MarketingReportRow>
     */
    public function rows(string $by): Collection
    {
        $column = $by === self::BY_MARKETING_SOURCE ? 'marketing_source_id' : 'lead_source_id';
        $model = $by === self::BY_MARKETING_SOURCE ? MarketingSource::class : LeadSource::class;
        $ids = $this->activeSourceIds($column, $by === self::BY_LEAD_SOURCE);

        return $model::query()->whereIn('id', $ids)->pluck('name', 'id')
            ->map(fn (string $name, int $id): MarketingReportRow => $this->row($name, $column, $id))
            ->sortBy([fn (MarketingReportRow $a, MarketingReportRow $b): int => $b->spendCents <=> $a->spendCents, fn (MarketingReportRow $a, MarketingReportRow $b): int => $b->leads <=> $a->leads, fn (MarketingReportRow $a, MarketingReportRow $b): int => strcmp($a->source, $b->source)])
            ->values();
    }

    /**
     * Every source together, over these bounds (the range itself, unless a
     * month's part of it is given).
     *
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>|null  $bounds
     */
    public function total(?array $bounds = null): MarketingReportRow
    {
        return $this->row(__('All sources'), null, null, $bounds);
    }

    /**
     * How quickly the leads received in the range were first called, in
     * bands, with those never called; and the middle time.
     *
     * @return array{bands: array<string, int>, never: int, median: int|null}
     */
    public function speedToLead(): array
    {
        $minutes = $this->firstCallMinutes();
        $bands = array_fill_keys(array_map(fn (string $label): string => __($label), self::SPEED_BANDS), 0);
        $bands[__('Over 24 hours')] = 0;

        foreach ($minutes->filter(fn (?int $value): bool => $value !== null) as $value) {
            $limit = collect(array_keys(self::SPEED_BANDS))->first(fn (int $upper): bool => $value < $upper);
            $bands[$limit === null ? __('Over 24 hours') : __(self::SPEED_BANDS[$limit])]++;
        }

        $called = $minutes->filter(fn (?int $value): bool => $value !== null)->sort()->values();

        return [
            'bands' => $bands,
            'never' => $minutes->filter(fn (?int $value): bool => $value === null)->count(),
            'median' => $called->isEmpty() ? null : (int) $called->median(),
        ];
    }

    /**
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>|null  $bounds
     */
    private function row(string $name, ?string $column, ?int $sourceId, ?array $bounds = null): MarketingReportRow
    {
        $bounds ??= $this->bounds;
        $figures = SalesFigures::none();
        $leadIds = collect();

        foreach ($bounds as $locationId => [$from, $to]) {
            if ($from->gte($to) || ! $this->locations->has($locationId)) {
                continue;
            }

            $location = $this->locations->get($locationId);
            $figures = $figures->plus(SalesFigures::between(
                $location,
                $from,
                $to,
                leadSourceId: $column === 'lead_source_id' ? $sourceId : null,
                marketingSourceId: $column === 'marketing_source_id' ? $sourceId : null,
            ));
            $leadIds = $leadIds->merge(Lead::query()
                ->where('location_id', $locationId)
                ->where('received_at', '>=', $from)
                ->where('received_at', '<', $to)
                ->when($column !== null, fn (Builder $query) => $query->where($column, $sourceId))
                ->pluck('id'));
        }

        $connected = $leadIds->isEmpty() ? 0 : ContactAttempt::query()->whereIn('lead_id', $leadIds)->where('was_connected', true)->distinct()->count('lead_id');

        return new MarketingReportRow(
            source: $name,
            spendCents: $column === 'marketing_source_id' ? null : $this->spendCents($bounds, $sourceId),
            leads: $leadIds->count(),
            connected: $connected,
            appointments: $figures->netLeadsIssued(),
            demoed: $figures->leadsDemoed,
            sales: $figures->netWins(),
            soldCents: $figures->netSoldCents(),
        );
    }

    /**
     * The spend entered at the Locations for the months the bounds touch,
     * each month's counted for the share of its days inside them.
     *
     * @param  array<int, array{CarbonImmutable, CarbonImmutable}>  $bounds
     */
    private function spendCents(array $bounds, ?int $leadSourceId): int
    {
        $cents = 0.0;

        foreach ($bounds as $locationId => [$from, $to]) {
            if ($from->gte($to) || ! $this->locations->has($locationId)) {
                continue;
            }

            $timezone = CallingHours::timezoneFor($this->locations->get($locationId));
            $month = $from->setTimezone($timezone)->startOfMonth();

            while ($month->utc()->lt($to)) {
                $monthStart = $month->utc();
                $monthEnd = $month->addMonthNoOverflow()->utc();
                $share = $from->max($monthStart)->diffInSeconds($to->min($monthEnd)) / $monthStart->diffInSeconds($monthEnd);

                $entered = (int) MarketingSpendEntry::query()
                    ->where('location_id', $locationId)
                    ->where('month', $month->format('Y-m'))
                    ->when($leadSourceId !== null, fn (Builder $query) => $query->where('lead_source_id', $leadSourceId))
                    ->sum('amount_cents');

                $cents += $entered * $share;
                $month = $month->addMonthNoOverflow();
            }
        }

        return (int) round($cents);
    }

    /**
     * Minutes from each lead received in the range to its first call out;
     * none for a lead never called.
     *
     * @return Collection<int, int|null>
     */
    private function firstCallMinutes(): Collection
    {
        $leads = $this->within(Lead::query(), 'received_at')->get(['id', 'received_at']);

        $firstCalls = $leads->isEmpty() ? collect() : ContactAttempt::query()
            ->whereIn('lead_id', $leads->modelKeys())
            ->where('direction', ContactAttemptDirection::Outbound)
            ->selectRaw('lead_id, min(occurred_at) as first_call')
            ->groupBy('lead_id')
            ->pluck('first_call', 'lead_id');

        return $leads->mapWithKeys(fn (Lead $lead): array => [$lead->id => isset($firstCalls[$lead->id])
            ? (int) max(0, floor($lead->received_at->diffInSeconds(CarbonImmutable::parse((string) $firstCalls[$lead->id], 'UTC'), false) / 60))
            : null]);
    }

    /**
     * The sources with spend, leads, appointments or sales in the range.
     *
     * @return list<int>
     */
    private function activeSourceIds(string $column, bool $withSpend): array
    {
        $leadColumn = fn (Builder $leadIds) => Lead::query()->whereIn('id', $leadIds)->distinct()->pluck($column);
        $ids = collect()
            ->merge($this->within(Lead::query(), 'received_at')->distinct()->pluck($column))
            ->merge($leadColumn($this->within(LeadIssue::query(), 'issued_at')->select('lead_id')))
            ->merge($leadColumn($this->within(SaleEntry::query(), 'occurred_at')->select('lead_id')));

        if ($withSpend) {
            $months = collect($this->bounds)->flatMap(function (array $range, int $locationId): array {
                $timezone = CallingHours::timezoneFor($this->locations->get($locationId));
                $months = [];

                for ($month = $range[0]->setTimezone($timezone)->startOfMonth(); $month->utc()->lt($range[1]); $month = $month->addMonthNoOverflow()) {
                    $months[] = $month->format('Y-m');
                }

                return $months;
            })->unique()->values()->all();

            $ids = $ids->merge(MarketingSpendEntry::query()->whereIn('location_id', array_keys($this->bounds))->whereIn('month', $months)->distinct()->pluck('lead_source_id'));
        }

        return array_values($ids->filter()->map(fn (mixed $id): int => (int) $id)->unique()->all());
    }

    /**
     * Narrow a query to the shown Locations, each within its own range.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function within(Builder $query, string $column): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->where(function (Builder $any) use ($column, $table): void {
            $any->whereRaw('1 = 0');

            foreach ($this->bounds as $locationId => [$from, $to]) {
                $any->orWhere(fn (Builder $here) => $here->where($table.'.location_id', $locationId)->where($table.'.'.$column, '>=', $from)->where($table.'.'.$column, '<', $to));
            }
        });
    }
}
