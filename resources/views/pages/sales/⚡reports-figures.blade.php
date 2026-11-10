<?php

use App\Enums\Permission;
use App\Livewire\ReportPage;
use App\Support\Money;
use App\Support\Reports\SalesReport;
use App\Support\Sales\SalesFigures;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales > Reports > Sales figures (docs/slice-32-reports-plan.md, step 32b;
 * product-architecture.md, 5.9; decisions 219 and 222): for any range of
 * days, at one Location or all of this person's, Sales > Performance's
 * figures in total, by salesperson and month by month; sold revenue by
 * salesperson each month; why Leads were lost; and the objections heard.
 * Every table downloads as a spreadsheet of figures and staff names.
 */
new #[Title('Sales figures')] class extends ReportPage {
    protected function centerPermission(): Permission
    {
        return Permission::SalesAccess;
    }

    #[Computed]
    public function report(): SalesReport
    {
        return SalesReport::for($this->shown, $this->bounds);
    }

    #[Computed]
    public function totals(): SalesFigures
    {
        return $this->report->figures();
    }

    /**
     * Each month's part of the range, by "2026-11".
     *
     * @return array<string, array<int, array{\Carbon\CarbonImmutable, \Carbon\CarbonImmutable}>>
     */
    #[Computed]
    public function monthBounds(): array
    {
        $months = [];

        foreach ($this->months() as $month) {
            $months[$month->format('Y-m')] = $this->boundsWithin($month);
        }

        return $months;
    }

    /**
     * Each month's figures, oldest first, by "2026-11".
     *
     * @return array<string, SalesFigures>
     */
    #[Computed]
    public function byMonth(): array
    {
        return array_map(fn (array $bounds): SalesFigures => $this->report->figures(bounds: $bounds), $this->monthBounds);
    }

    /**
     * The figures shown in each table, in order: the column's name, and the
     * figure as shown and as downloaded.
     *
     * @return array<string, array{label: string, show: Closure(SalesFigures): string, cell: Closure(SalesFigures): string|int}>
     */
    public function columns(): array
    {
        $percent = fn (?int $value): string => $value === null ? '—' : $value.'%';
        $money = fn (?int $cents): string => $cents === null ? '—' : Money::format($cents);

        return [
            'issued' => ['label' => __('Leads issued'), 'show' => fn (SalesFigures $f): string => (string) $f->netLeadsIssued(), 'cell' => fn (SalesFigures $f): int => $f->netLeadsIssued()],
            'run' => ['label' => __('Ran'), 'show' => fn (SalesFigures $f): string => (string) $f->appointmentsRun, 'cell' => fn (SalesFigures $f): int => $f->appointmentsRun],
            'demo_rate' => ['label' => __('Demo Rate'), 'show' => fn (SalesFigures $f): string => $percent($f->demoRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->demoRate())],
            'cancellation_rate' => ['label' => __('Cancellation Rate'), 'show' => fn (SalesFigures $f): string => $percent($f->cancellationRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->cancellationRate())],
            'wins' => ['label' => __('Sales'), 'show' => fn (SalesFigures $f): string => (string) $f->netWins(), 'cell' => fn (SalesFigures $f): int => $f->netWins()],
            'close_rate' => ['label' => __('Close Rate'), 'show' => fn (SalesFigures $f): string => $percent($f->closeRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->closeRate())],
            'on_the_spot' => ['label' => __('Close-on-the-Spot'), 'show' => fn (SalesFigures $f): string => $percent($f->closeOnTheSpotRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->closeOnTheSpotRate())],
            'pending_rate' => ['label' => __('Pending Rate'), 'show' => fn (SalesFigures $f): string => $percent($f->pendingRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->pendingRate())],
            'return_visit' => ['label' => __('Pending with a return visit'), 'show' => fn (SalesFigures $f): string => $percent($f->returnVisitRate()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->returnVisitRate())],
            'rehash' => ['label' => __('Rehash conversion'), 'show' => fn (SalesFigures $f): string => $percent($f->rehashConversion()), 'cell' => fn (SalesFigures $f): string => self::percentCell($f->rehashConversion())],
            'average_job' => ['label' => __('Average job size'), 'show' => fn (SalesFigures $f): string => $money($f->averageJobSizeCents()), 'cell' => fn (SalesFigures $f): string => self::dollarsCell($f->averageJobSizeCents())],
            'sold' => ['label' => __('Sold revenue'), 'show' => fn (SalesFigures $f): string => Money::format($f->netSoldCents()), 'cell' => fn (SalesFigures $f): string => self::dollarsCell($f->netSoldCents())],
            'nsli' => ['label' => __('NSLI'), 'show' => fn (SalesFigures $f): string => $money($f->nsliCents()), 'cell' => fn (SalesFigures $f): string => self::dollarsCell($f->nsliCents())],
        ];
    }

    public function download(string $table): StreamedResponse
    {
        $columns = $this->columns();
        $header = array_map(fn (array $column): string => $column['label'], array_values($columns));
        $row = fn (string $name, SalesFigures $figures): array => [$name, ...array_map(fn (array $column): string|int => ($column['cell'])($figures), array_values($columns))];

        return match ($table) {
            'months' => $this->csv('sales-figures-by-month', [
                [__('Month'), ...$header],
                ...collect($this->byMonth)->map(fn (SalesFigures $figures, string $month): array => $row($month, $figures))->values(),
                $row(__('Total'), $this->totals),
            ]),
            'leaderboard' => $this->csv('sold-revenue-by-month', [
                [__('Salesperson'), ...array_keys($this->monthBounds), __('Total')],
                ...$this->report->soldByMonth($this->monthBounds)->map(fn (array $person): array => [$person['name'], ...array_map(fn (int $cents): string => self::dollarsCell($cents), array_values($person['months'])), self::dollarsCell($person['total'])])->values(),
            ]),
            'lost' => $this->csv('lost-reasons', [
                [__('Lost reason'), __('Leads')],
                ...$this->report->lostReasons()->map(fn (int $count, string $reason): array => [$reason, $count])->values(),
            ]),
            'objections' => $this->csv('objections', [
                [__('Objection'), __('Estimates')],
                ...$this->report->objections()->map(fn (int $count, string $objection): array => [$objection, $count])->values(),
            ]),
            default => $this->csv('sales-figures-by-salesperson', [
                [__('Salesperson'), ...$header],
                ...$this->report->bySalesperson()->map(fn (array $person): array => $row($person['name'], $person['figures']))->values(),
                $row(__('Total'), $this->totals),
            ]),
        };
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="sales-figures-report">
    <div>
        <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
        <flux:subheading>{{ __('Sales figures over any days: the figures on Performance, by salesperson and month by month, with why leads were lost.') }}</flux:subheading>
    </div>

    @include('partials.reports.tabs', ['tabs' => ['sales.reports' => __('Appointments'), 'sales.reports.figures' => __('Sales figures')], 'current' => 'sales.reports.figures'])

    @if ($this->locations->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-none">
            <flux:text>{{ __('There are no reports for you to see. They are shown to managers, for their own locations.') }}</flux:text>
        </div>
    @else
        @include('partials.reports.filters')

        @php
            $totals = $this->totals;
            $columns = $this->columns();
            $severalMonths = count($this->monthBounds) > 1;
        @endphp

        {{-- The plan's primary coaching figure first (5.2), then what explains it. --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="sales-totals">
            @foreach (['nsli', 'sold', 'close_rate', 'demo_rate', 'issued', 'run', 'average_job', 'on_the_spot'] as $key)
                <div class="rounded-xl border border-zinc-200 bg-card p-4" data-test="total-{{ $key }}">
                    <div class="text-sm font-medium text-zinc-500">{{ $columns[$key]['label'] }}</div>
                    <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ ($columns[$key]['show'])($totals) }}</div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-zinc-700" data-test="sales-secondary">
            @foreach (['cancellation_rate', 'pending_rate', 'return_visit', 'rehash', 'wins'] as $key)
                <span>{{ $columns[$key]['label'] }}: <span class="font-semibold tabular-nums text-ink">{{ ($columns[$key]['show'])($totals) }}</span></span>
            @endforeach
        </div>

        {{-- By salesperson, with the total: revenue by rep (5.9). --}}
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('By salesperson') }}</flux:heading>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('salespeople')" data-test="download-salespeople">{{ __('Download') }}</flux:button>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="figures-by-salesperson">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Salesperson') }}</flux:table.column>
                        @foreach ($columns as $column)
                            <flux:table.column align="end">{{ $column['label'] }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->report->bySalesperson() as $id => $person)
                            <flux:table.row :key="'person-'.$id" data-test="figures-person-{{ $id }}">
                                <flux:table.cell class="ps-3 font-medium text-ink">{{ $person['name'] }}</flux:table.cell>
                                @foreach ($columns as $key => $column)
                                    <flux:table.cell align="end" class="tabular-nums" data-test="cell-{{ $key }}">{{ ($column['show'])($person['figures']) }}</flux:table.cell>
                                @endforeach
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="count($columns) + 1" class="ps-3">{{ __('Nothing was counted for anyone in these days.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                        <flux:table.row data-test="figures-total">
                            <flux:table.cell class="ps-3 font-semibold text-ink">{{ __('Total') }}</flux:table.cell>
                            @foreach ($columns as $key => $column)
                                <flux:table.cell align="end" class="font-semibold tabular-nums text-ink" data-test="cell-{{ $key }}">{{ ($column['show'])($totals) }}</flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @if ($severalMonths)
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">{{ __('Month by month') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('months')" data-test="download-months">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="figures-by-month">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Month') }}</flux:table.column>
                            @foreach ($columns as $column)
                                <flux:table.column align="end">{{ $column['label'] }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->byMonth as $month => $figures)
                                <flux:table.row :key="'month-'.$month" data-test="figures-month-{{ $month }}">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') }}</flux:table.cell>
                                    @foreach ($columns as $key => $column)
                                        <flux:table.cell align="end" class="tabular-nums" data-test="cell-{{ $key }}">{{ ($column['show'])($figures) }}</flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif

        {{-- Sold revenue by salesperson each month: the Leaderboard's history (5.8, 5.9). --}}
        @php
            $leaderboard = $this->report->soldByMonth($this->monthBounds);
        @endphp
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Sold revenue by month') }}</flux:heading>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('leaderboard')" data-test="download-leaderboard">{{ __('Download') }}</flux:button>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="sold-by-month">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Salesperson') }}</flux:table.column>
                        @foreach (array_keys($this->monthBounds) as $month)
                            <flux:table.column align="end">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') }}</flux:table.column>
                        @endforeach
                        @if ($severalMonths)
                            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                        @endif
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($leaderboard as $id => $person)
                            <flux:table.row :key="'sold-'.$id">
                                <flux:table.cell class="ps-3 font-medium text-ink">{{ $loop->iteration }}. {{ $person['name'] }}</flux:table.cell>
                                @foreach ($person['months'] as $month => $cents)
                                    @php
                                        $best = $leaderboard->max(fn (array $other): int => $other['months'][$month]);
                                    @endphp
                                    <flux:table.cell align="end" class="tabular-nums {{ $cents > 0 && $cents === $best ? 'font-semibold text-ink' : '' }}">{{ Money::format($cents) }}@if ($cents > 0 && $cents === $best && $severalMonths) <flux:icon.trophy variant="micro" class="inline size-3.5 text-amber-600" />@endif</flux:table.cell>
                                @endforeach
                                @if ($severalMonths)
                                    <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ Money::format($person['total']) }}</flux:table.cell>
                                @endif
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="count($this->monthBounds) + 2" class="ps-3">{{ __('Nothing sold in these days.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            @foreach (['lost' => [__('Why leads were lost'), __('Lost reason'), __('Leads'), $this->report->lostReasons(), __('No lead was marked lost in these days.')], 'objections' => [__('Objections heard'), __('Objection'), __('Estimates'), $this->report->objections(), __('No objection was recorded in these days.')]] as $table => [$heading, $name, $unit, $counts, $none])
                @php
                    $all = $counts->sum();
                @endphp
                <div class="flex min-w-0 flex-col gap-3" wire:key="table-{{ $table }}">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
                        <flux:button size="sm" icon="arrow-down-tray" wire:click="download('{{ $table }}')" data-test="download-{{ $table }}">{{ __('Download') }}</flux:button>
                    </div>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                        <flux:table data-test="{{ $table }}-table">
                            <flux:table.columns>
                                <flux:table.column>{{ $name }}</flux:table.column>
                                <flux:table.column align="end">{{ $unit }}</flux:table.column>
                                <flux:table.column align="end">{{ __('Share') }}</flux:table.column>
                            </flux:table.columns>
                            <flux:table.rows>
                                @forelse ($counts as $label => $count)
                                    <flux:table.row :key="$table.'-'.$loop->index">
                                        <flux:table.cell class="ps-3 font-medium text-ink whitespace-normal">{{ $label }}</flux:table.cell>
                                        <flux:table.cell align="end" class="tabular-nums">{{ $count }}</flux:table.cell>
                                        <flux:table.cell align="end" class="pe-3 tabular-nums">{{ (int) round($count * 100 / $all) }}%</flux:table.cell>
                                    </flux:table.row>
                                @empty
                                    <flux:table.row>
                                        <flux:table.cell colspan="3" class="ps-3">{{ $none }}</flux:table.cell>
                                    </flux:table.row>
                                @endforelse
                            </flux:table.rows>
                        </flux:table>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-how">
            <flux:heading size="lg" level="2">{{ __('How these are counted') }}</flux:heading>

            <ul class="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm text-zinc-700">
                <li>{{ __('Each figure is counted as Sales > Performance counts it, over each location\'s own days, so a month here matches that month there.') }}</li>
                <li>{{ __('A lead issued counts for the salesperson it was issued to; an appointment for the salesperson it is with; a sale for whoever had the lead when it was sold.') }}</li>
                <li>{{ __('A sale or lead issued taken back later stays where it was and comes off the days it was taken back in.') }}</li>
                <li>{{ __('Close Rate is leads won over leads demoed for the first time in these days, so days of sales closed from earlier demos can read above 100%.') }}</li>
                <li>{{ __('NSLI is sold revenue over leads issued, each after corrections.') }}</li>
                <li>{{ __('Why leads were lost: leads Sales marked lost in these days. Objections: estimates whose objection was recorded in these days.') }}</li>
            </ul>
        </div>
    @endif
</section>
