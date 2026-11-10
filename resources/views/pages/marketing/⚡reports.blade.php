<?php

use App\Livewire\ReportPage;
use App\Support\Money;
use App\Support\Reports\MarketingReport;
use App\Support\Reports\MarketingReportRow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Marketing > Reports (docs/slice-32-reports-plan.md, step 32c;
 * product-architecture.md, 4.6 and 21; decisions 219 and 222): for any range
 * of days, at one Location or all of this person's, by Lead Source or by
 * Marketing Source, the spend, leads, cost per lead, appointments and booking
 * rate, sales, close rate, sold revenue and return on spend; month by month;
 * and speed to lead and connection rate from the call attempts. Every table
 * downloads as a spreadsheet of figures.
 */
new #[Title('Marketing reports')] class extends ReportPage {
    /** By Lead Source (where a lead came from) or Marketing Source (what the customer says brought them; 21). */
    #[Url(except: MarketingReport::BY_LEAD_SOURCE)]
    public string $by = MarketingReport::BY_LEAD_SOURCE;

    #[Computed]
    public function report(): MarketingReport
    {
        return MarketingReport::for($this->shown, $this->bounds);
    }

    #[Computed]
    public function grouping(): string
    {
        return $this->by === MarketingReport::BY_MARKETING_SOURCE ? MarketingReport::BY_MARKETING_SOURCE : MarketingReport::BY_LEAD_SOURCE;
    }

    /**
     * @return \Illuminate\Support\Collection<int, MarketingReportRow>
     */
    #[Computed]
    public function rows(): \Illuminate\Support\Collection
    {
        return $this->report->rows($this->grouping);
    }

    #[Computed]
    public function total(): MarketingReportRow
    {
        return $this->report->total();
    }

    /**
     * Each month's totals, oldest first, by "2026-11".
     *
     * @return array<string, MarketingReportRow>
     */
    #[Computed]
    public function byMonth(): array
    {
        $months = [];

        foreach ($this->months() as $month) {
            $months[$month->format('Y-m')] = $this->report->total($this->boundsWithin($month));
        }

        return $months;
    }

    /**
     * The figures in each table, in order: the column's name, and the
     * figure as shown and as downloaded.
     *
     * @return array<string, array{label: string, show: Closure(MarketingReportRow): string, cell: Closure(MarketingReportRow): string|int}>
     */
    public function columns(): array
    {
        $percent = fn (?int $value): string => $value === null ? '—' : $value.'%';
        $money = fn (?int $cents): string => $cents === null ? '—' : Money::format($cents);

        return [
            'spend' => ['label' => __('Spend'), 'show' => fn (MarketingReportRow $r): string => $money($r->spendCents), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->spendCents)],
            'leads' => ['label' => __('Leads'), 'show' => fn (MarketingReportRow $r): string => (string) $r->leads, 'cell' => fn (MarketingReportRow $r): int => $r->leads],
            'cpl' => ['label' => __('Cost per lead'), 'show' => fn (MarketingReportRow $r): string => $money($r->costPerLeadCents()), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->costPerLeadCents())],
            'connection' => ['label' => __('Connection rate'), 'show' => fn (MarketingReportRow $r): string => $percent($r->connectionRate()), 'cell' => fn (MarketingReportRow $r): string => self::percentCell($r->connectionRate())],
            'appointments' => ['label' => __('Appointments'), 'show' => fn (MarketingReportRow $r): string => (string) $r->appointments, 'cell' => fn (MarketingReportRow $r): int => $r->appointments],
            'booking' => ['label' => __('Booking rate'), 'show' => fn (MarketingReportRow $r): string => $percent($r->bookingRate()), 'cell' => fn (MarketingReportRow $r): string => self::percentCell($r->bookingRate())],
            'cpa' => ['label' => __('Cost per appointment'), 'show' => fn (MarketingReportRow $r): string => $money($r->costPerAppointmentCents()), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->costPerAppointmentCents())],
            'sales' => ['label' => __('Sales'), 'show' => fn (MarketingReportRow $r): string => (string) $r->sales, 'cell' => fn (MarketingReportRow $r): int => $r->sales],
            'close' => ['label' => __('Close Rate'), 'show' => fn (MarketingReportRow $r): string => $percent($r->closeRate()), 'cell' => fn (MarketingReportRow $r): string => self::percentCell($r->closeRate())],
            'cps' => ['label' => __('Cost per sale'), 'show' => fn (MarketingReportRow $r): string => $money($r->costPerSaleCents()), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->costPerSaleCents())],
            'sold' => ['label' => __('Sold revenue'), 'show' => fn (MarketingReportRow $r): string => Money::format($r->soldCents), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->soldCents)],
            'return' => ['label' => __('Return on spend'), 'show' => fn (MarketingReportRow $r): string => $r->returnCents() === null ? '—' : __(':amount per $1', ['amount' => Money::format($r->returnCents())]), 'cell' => fn (MarketingReportRow $r): string => self::dollarsCell($r->returnCents())],
        ];
    }

    public function download(string $table): StreamedResponse
    {
        $columns = $this->grouping === MarketingReport::BY_MARKETING_SOURCE
            ? array_diff_key($this->columns(), array_flip(['spend', 'cpl', 'cpa', 'cps', 'return']))
            : $this->columns();
        $header = array_map(fn (array $column): string => $column['label'], array_values($columns));
        $row = fn (string $name, MarketingReportRow $figures): array => [$name, ...array_map(fn (array $column): string|int => ($column['cell'])($figures), array_values($columns))];

        return match ($table) {
            'months' => $this->csv('marketing-by-month', [
                [__('Month'), ...array_map(fn (array $column): string => $column['label'], array_values($this->columns()))],
                ...collect($this->byMonth)->map(fn (MarketingReportRow $figures, string $month): array => [$month, ...array_map(fn (array $column): string|int => ($column['cell'])($figures), array_values($this->columns()))])->values(),
            ]),
            'speed' => $this->csv('speed-to-lead', [
                [__('First call'), __('Leads')],
                ...collect($this->report->speedToLead()['bands'])->map(fn (int $count, string $band): array => [$band, $count])->values(),
                [__('Never called'), $this->report->speedToLead()['never']],
            ]),
            default => $this->csv($this->grouping === MarketingReport::BY_MARKETING_SOURCE ? 'marketing-by-marketing-source' : 'marketing-by-lead-source', [
                [$this->grouping === MarketingReport::BY_MARKETING_SOURCE ? __('Marketing Source') : __('Lead Source'), ...$header],
                ...$this->rows->map(fn (MarketingReportRow $source): array => $row($source->source, $source))->values(),
                $row(__('Total'), $this->total),
            ]),
        };
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="marketing-reports">
    <div>
        <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
        <flux:subheading>{{ __('Spend, leads and what they became, by source, over any days; and how quickly leads were called.') }}</flux:subheading>
    </div>

    @if ($this->locations->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-none">
            <flux:text>{{ __('There are no reports for you to see. They are shown to managers, for their own locations.') }}</flux:text>
        </div>
    @else
        @include('partials.reports.filters')

        @php
            $total = $this->total;
            $bySpend = $this->grouping === MarketingReport::BY_LEAD_SOURCE;
            $columns = $bySpend ? $this->columns() : array_diff_key($this->columns(), array_flip(['spend', 'cpl', 'cpa', 'cps', 'return']));
            $allColumns = $this->columns();
            $speed = $this->report->speedToLead();
        @endphp

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="marketing-totals">
            @foreach (['spend', 'leads', 'cpl', 'booking', 'appointments', 'cps', 'sold', 'return'] as $key)
                <div class="rounded-xl border border-zinc-200 bg-card p-4" data-test="total-{{ $key }}">
                    <div class="text-sm font-medium text-zinc-500">{{ $allColumns[$key]['label'] }}</div>
                    <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="figure-value">{{ ($allColumns[$key]['show'])($total) }}</div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <flux:radio.group wire:model.live="by" variant="segmented" size="sm" :label="__('By')" data-test="marketing-by">
                    <flux:radio :value="MarketingReport::BY_LEAD_SOURCE" :label="__('Lead Source')" />
                    <flux:radio :value="MarketingReport::BY_MARKETING_SOURCE" :label="__('Marketing Source')" />
                </flux:radio.group>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('sources')" data-test="download-sources">{{ __('Download') }}</flux:button>
            </div>
            @unless ($bySpend)
                <flux:text class="text-sm">{{ __('Marketing Source is what the customer says brought them. Spend is entered by Lead Source, so it is not split this way.') }}</flux:text>
            @endunless
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="marketing-by-source">
                    <flux:table.columns>
                        <flux:table.column>{{ $bySpend ? __('Lead Source') : __('Marketing Source') }}</flux:table.column>
                        @foreach ($columns as $column)
                            <flux:table.column align="end">{{ $column['label'] }}</flux:table.column>
                        @endforeach
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($this->rows as $row)
                            <flux:table.row :key="'source-'.$loop->index" data-test="source-row">
                                <flux:table.cell class="ps-3 font-medium text-ink">{{ $row->source }}</flux:table.cell>
                                @foreach ($columns as $key => $column)
                                    <flux:table.cell align="end" class="tabular-nums" data-test="cell-{{ $key }}">{{ ($column['show'])($row) }}</flux:table.cell>
                                @endforeach
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell :colspan="count($columns) + 1" class="ps-3">{{ __('No spend, leads or sales in these days.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                        <flux:table.row data-test="marketing-total">
                            <flux:table.cell class="ps-3 font-semibold text-ink">{{ __('Total') }}</flux:table.cell>
                            @foreach ($columns as $key => $column)
                                <flux:table.cell align="end" class="font-semibold tabular-nums text-ink" data-test="cell-{{ $key }}">{{ ($column['show'])($total) }}</flux:table.cell>
                            @endforeach
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @if (count($this->byMonth) > 1)
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <flux:heading size="lg" level="2">{{ __('Month by month') }}</flux:heading>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('months')" data-test="download-months">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="marketing-by-month">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Month') }}</flux:table.column>
                            @foreach ($allColumns as $column)
                                <flux:table.column align="end">{{ $column['label'] }}</flux:table.column>
                            @endforeach
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($this->byMonth as $month => $figures)
                                <flux:table.row :key="'month-'.$month" data-test="marketing-month-{{ $month }}">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') }}</flux:table.cell>
                                    @foreach ($allColumns as $key => $column)
                                        <flux:table.cell align="end" class="tabular-nums" data-test="cell-{{ $key }}">{{ ($column['show'])($figures) }}</flux:table.cell>
                                    @endforeach
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif

        {{-- Contact Center: speed to lead and connection rate, from the call attempts (4.2, 4.6). --}}
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="speed-to-lead">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Speed to lead') }}</flux:heading>
                    <flux:subheading>
                        {{ $speed['median'] === null ? __('No lead received in these days was called yet.') : __('Half the leads received in these days were first called within :minutes. :connected of :leads were reached (:rate).', ['minutes' => trans_choice(':count minute|:count minutes', $speed['median']), 'connected' => $total->connected, 'leads' => $total->leads, 'rate' => ($allColumns['connection']['show'])($total)]) }}
                    </flux:subheading>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('speed')" data-test="download-speed">{{ __('Download') }}</flux:button>
            </div>

            @php
                $received = max(1, array_sum($speed['bands']) + $speed['never']);
            @endphp
            <ul class="flex flex-col gap-2">
                @foreach ($speed['bands'] + [__('Never called') => $speed['never']] as $band => $count)
                    <li wire:key="band-{{ $loop->index }}" class="grid grid-cols-[minmax(0,10rem)_1fr_3rem] items-center gap-3 text-sm" data-test="speed-band">
                        <span class="text-zinc-700">{{ $band }}</span>
                        <span class="h-2.5 overflow-hidden rounded-full bg-zinc-100">
                            <span class="block h-full rounded-full {{ $loop->last ? 'bg-zinc-400' : 'bg-amber-500' }}" style="width: {{ round($count * 100 / $received) }}%"></span>
                        </span>
                        <span class="text-end tabular-nums text-ink">{{ $count }}</span>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-how">
            <flux:heading size="lg" level="2">{{ __('How these are counted') }}</flux:heading>

            <ul class="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm text-zinc-700">
                <li>{{ __('Leads: received in these days. Appointments, sales and sold revenue are counted as Sales > Performance counts them, for the leads from each source, so a month here matches Marketing > Performance.') }}</li>
                <li>{{ __('Spend is entered by month, so days that hold only part of a month count that share of its spend.') }}</li>
                <li>{{ __('Booking rate: appointments over leads. It is kept by source, since a normal booking rate differs by channel.') }}</li>
                <li>{{ __('Connection rate: the leads received in these days that were reached, by a call or message answered both ways, over them all.') }}</li>
                <li>{{ __('Speed to lead: from when a lead was received to its first call out.') }}</li>
                <li>{{ __('Return on spend is sold revenue for each dollar spent.') }}</li>
            </ul>
        </div>
    @endif
</section>
