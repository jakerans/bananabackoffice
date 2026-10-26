<?php

use App\Enums\Permission;
use App\Models\Location;
use App\Models\Scorecard;
use App\Models\ScorecardLine;
use App\Support\Scorecards\ScorecardNumbers;
use App\Support\Scorecards\ScorecardOwnership;
use App\Support\Scorecards\ScorecardWeek;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Company > Leadership Scorecards (docs/slice-27-leadership-scorecards-plan.md,
 * step 27b; decision 187, question 7; product-architecture.md, 17.3 and 18):
 * every Location's scorecard for a week side by side, each KPI with the
 * status its owner chose, and who has not submitted. For the Owner and
 * Leadership.
 */
new #[Title('Leadership Scorecards')] class extends Component {
    #[Url(as: 'week')]
    public string $week = '';

    public function mount(): void
    {
        $this->authorize(Permission::CompanyOverviewAccess);
    }

    #[Computed]
    public function monday(): CarbonImmutable
    {
        $chosen = ScorecardWeek::parse($this->week);
        $last = ScorecardWeek::lastEnded(null);

        return $chosen !== null && $chosen->lessThanOrEqualTo($last) ? $chosen : $last;
    }

    /**
     * Each active Location with its owners and its scorecard for the week:
     * submitted, or the numbers as they are now.
     *
     * @return Collection<int, array{location: Location, owners: string, scorecard: Scorecard|null, overdue: bool, lines: array<string, array{actual: int|null, target: int|null, onTarget: bool|null, status: string|null, note: string|null}>}>
     */
    #[Computed]
    public function rows(): Collection
    {
        $monday = $this->monday();
        $scorecards = Scorecard::query()->whereDate('week_starts_on', $monday->toDateString())->with('lines')->get()->keyBy('location_id');

        return Location::query()->where('is_active', true)->orderBy('name')->get()->map(function (Location $location) use ($monday, $scorecards): array {
            $scorecard = $scorecards->get($location->id);
            $lines = $scorecard !== null
                ? $scorecard->lines->mapWithKeys(fn (ScorecardLine $line): array => [$line->kpi => ['actual' => $line->actual, 'target' => $line->target, 'onTarget' => $line->on_target, 'status' => $line->status, 'note' => $line->note]])->all()
                : collect(ScorecardNumbers::for($location, $monday))->mapWithKeys(fn (array $number): array => [$number['kpi'] => ['actual' => $number['actual'], 'target' => $number['target'], 'onTarget' => $number['onTarget'], 'status' => null, 'note' => null]])->all();

            return [
                'location' => $location,
                'owners' => ScorecardOwnership::owners($location)->pluck('name')->join(', '),
                'scorecard' => $scorecard,
                'overdue' => $scorecard === null && ScorecardWeek::isOverdue($location, $monday),
                'lines' => $lines,
            ];
        });
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="leadership-scorecards">
    @php
        $colors = [ScorecardLine::GREEN => 'green', ScorecardLine::YELLOW => 'yellow', ScorecardLine::RED => 'red'];
        $monday = $this->monday;
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Leadership Scorecards') }}</flux:heading>
            <flux:subheading>{{ __('Each Location\'s week, as its general manager scored it: Backoffice fills in the numbers, the manager chooses the status.') }}</flux:subheading>
        </div>

        <flux:select wire:model.live="week" :label="__('Week')" class="max-w-64" data-test="leadership-week">
            @foreach (ScorecardWeek::recent(null) as $recent)
                <flux:select.option :value="$recent->toDateString()">{{ ScorecardWeek::words($recent) }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Location') }}</flux:table.column>
                @foreach (ScorecardNumbers::KPIS as $label)
                    <flux:table.column>{{ __($label) }}</flux:table.column>
                @endforeach
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->rows as $row)
                    <flux:table.row :key="$row['location']->id" data-test="leadership-row-{{ $row['location']->id }}">
                        <flux:table.cell class="align-top">
                            <a href="{{ route('scorecards.show', ['location' => $row['location'], 'week' => $monday->toDateString()]) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $row['location']->name }}</a>
                            <span class="block text-xs text-zinc-500">{{ $row['owners'] }}</span>
                            @if ($row['scorecard'] !== null)
                                <flux:badge size="sm" color="green" class="mt-1">{{ __('Submitted') }}</flux:badge>
                            @elseif ($row['overdue'])
                                <flux:badge size="sm" color="rose" class="mt-1">{{ __('Not submitted') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="amber" class="mt-1">{{ __('Due') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                        @foreach (array_keys(ScorecardNumbers::KPIS) as $kpi)
                            @php
                                $line = $row['lines'][$kpi] ?? null;
                            @endphp
                            <flux:table.cell class="align-top" data-test="leadership-{{ $row['location']->id }}-{{ $kpi }}">
                                @if ($line !== null)
                                    <span class="flex flex-col gap-1 whitespace-normal">
                                        @if ($line['status'] !== null)
                                            <flux:badge size="sm" :color="$colors[$line['status']] ?? 'zinc'" class="self-start">{{ __(ScorecardLine::STATUSES[$line['status']] ?? $line['status']) }}</flux:badge>
                                        @endif
                                        <span class="tabular-nums font-semibold text-ink">{{ ScorecardNumbers::format($kpi, $line['actual']) }}</span>
                                        <span class="text-xs text-zinc-500">{{ $line['target'] === null ? __('No target') : __('Target :target', ['target' => ScorecardNumbers::format($kpi, $line['target'])]) }}</span>
                                        @if ($line['note'] !== null)
                                            <span class="max-w-56 text-xs text-zinc-600">{{ $line['note'] }}</span>
                                        @endif
                                    </span>
                                @endif
                            </flux:table.cell>
                        @endforeach
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>

    <flux:text class="text-sm">{{ __('Week of :week. Each number is the month so far; a submitted scorecard keeps the numbers it was submitted with. Net Profit % joins once QuickBooks is connected.', ['week' => ScorecardWeek::words($monday)]) }}</flux:text>
</section>
