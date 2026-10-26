<?php

namespace App\Support\Performance;

use App\Models\Lead;
use App\Models\LeadIssue;
use App\Models\LeadSource;
use App\Models\Location;
use App\Models\MarketingSpendEntry;
use App\Models\SaleEntry;
use App\Support\Queue\CallingHours;
use App\Support\Sales\SalesFigures;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Marketing > Performance (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25b; decision 183, questions 4 and 5; product-architecture.md, 4.1
 * and 21): for a month and one or more Locations, by Lead Source, the spend,
 * leads received, appointments issued, sales and sold revenue, as Sales >
 * Performance counts them, with the costs and return worked out from them.
 */
final readonly class MarketingBoard
{
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
     * One row for each Lead Source with spend, leads, appointments or sales
     * in the month, the most spent first.
     *
     * @return Collection<int, MarketingRow>
     */
    public function rows(): Collection
    {
        $names = LeadSource::query()->whereIn('id', $this->activeSourceIds())->pluck('name', 'id');

        return $names
            ->map(fn (string $name, int $id): MarketingRow => $this->row($name, $id))
            ->sortBy([fn (MarketingRow $a, MarketingRow $b): int => $b->spendCents <=> $a->spendCents, fn (MarketingRow $a, MarketingRow $b): int => strcmp($a->source, $b->source)])
            ->values();
    }

    /**
     * Every source together.
     */
    public function total(): MarketingRow
    {
        $rows = $this->rows();

        return new MarketingRow(
            'All sources',
            (int) $rows->sum('spendCents'),
            (int) $rows->sum('leads'),
            (int) $rows->sum('appointments'),
            (int) $rows->sum('sales'),
            (int) $rows->sum('soldCents'),
            (int) $rows->sum('demoed'),
        );
    }

    private function row(string $name, int $sourceId): MarketingRow
    {
        $spend = (int) MarketingSpendEntry::query()->whereIn('location_id', $this->locations->pluck('id')->all())->where('month', $this->month)->where('lead_source_id', $sourceId)->sum('amount_cents');
        $leads = 0;
        $appointments = 0;
        $sales = 0;
        $sold = 0;
        $demoed = 0;

        foreach ($this->locations as $location) {
            [$from, $to] = SalesFigures::monthAt($location, $this->start($location));
            $leads += Lead::query()->where('location_id', $location->id)->where('lead_source_id', $sourceId)->where('received_at', '>=', $from)->where('received_at', '<', $to)->count();

            $figures = SalesFigures::for($location, $this->start($location), null, $sourceId);
            $appointments += $figures->netLeadsIssued();
            $sales += $figures->netWins();
            $sold += $figures->netSoldCents();
            $demoed += $figures->leadsDemoed;
        }

        return new MarketingRow($name, $spend, $leads, $appointments, $sales, $sold, $demoed);
    }

    /**
     * The sources with anything in the month at these Locations.
     *
     * @return list<int>
     */
    private function activeSourceIds(): array
    {
        $ids = $this->locations->pluck('id')->all();
        $from = CarbonImmutable::createFromFormat('!Y-m', $this->month, (string) config('app.display_timezone')) ?: CarbonImmutable::now();
        // A day either side, so every Location's own month is inside.
        $window = [$from->subDay()->utc(), $from->addMonth()->addDay()->utc()];
        $leadSources = fn ($leadIds) => Lead::query()->whereIn('id', $leadIds)->distinct()->pluck('lead_source_id');

        return MarketingSpendEntry::query()->whereIn('location_id', $ids)->where('month', $this->month)->distinct()->pluck('lead_source_id')
            ->merge(Lead::query()->whereIn('location_id', $ids)->whereBetween('received_at', $window)->distinct()->pluck('lead_source_id'))
            ->merge($leadSources(LeadIssue::query()->whereIn('location_id', $ids)->whereBetween('issued_at', $window)->select('lead_id')))
            ->merge($leadSources(SaleEntry::query()->whereIn('location_id', $ids)->whereBetween('occurred_at', $window)->select('lead_id')))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->pipe(fn (Collection $ids): array => array_values($ids->all()));
    }

    private function start(Location $location): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month, CallingHours::timezoneFor($location)) ?: CarbonImmutable::now();
    }
}
