<?php

use App\Enums\Permission;
use App\Livewire\ReportPage;
use App\Support\Money;
use App\Support\Reports\FinanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Finance > Reports (docs/slice-32-reports-plan.md, step 32d;
 * product-architecture.md, 9.7; decisions 219 and 222): profit and loss by
 * Location from QuickBooks side by side (the default, 9.7), what customers
 * owe by age, cash collected by month, gross profit and job-cost variance on
 * the jobs financially closed, and the pay owed and not yet paid. Days are
 * the company's, as on Finance > Performance. Every table downloads.
 */
new #[Title('Finance reports')] class extends ReportPage {
    protected function centerPermission(): Permission
    {
        return Permission::FinanceAccess;
    }

    /**
     * The range's first and last day in the company's time, as Finance > Performance counts days.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    public function days(): array
    {
        return $this->chosenRange->days(CarbonImmutable::now((string) config('app.display_timezone'))) ?? $this->customDays;
    }

    public function scopeWords(): string
    {
        return $this->shown->count() > 1
            ? __(':days, at :count locations, in the company\'s time.', ['days' => $this->rangeWords(), 'count' => $this->shown->count()])
            : __(':days, at :location.', ['days' => $this->rangeWords(), 'location' => $this->shown->first()?->name]);
    }

    #[Computed]
    public function report(): FinanceReport
    {
        [$first, $last] = $this->days();

        return FinanceReport::for($this->shown, $first, $last);
    }

    #[Computed]
    public function seesPay(): bool
    {
        return Gate::allows(Permission::ManageCompensation);
    }

    /**
     * The profit and loss lines, in order: the name and the figure from a Location's sums.
     *
     * @return array<string, array{label: string, value: Closure(array<string, int|string>): int|null}>
     */
    public function lines(): array
    {
        return [
            'income' => ['label' => __('Income'), 'value' => fn (array $pl): int => (int) $pl['income']],
            'cost' => ['label' => __('Cost of goods sold'), 'value' => fn (array $pl): int => (int) $pl['cost']],
            'gross' => ['label' => __('Gross profit'), 'value' => fn (array $pl): int => (int) $pl['income'] - (int) $pl['cost']],
            'expenses' => ['label' => __('Expenses'), 'value' => fn (array $pl): int => (int) $pl['expenses']],
            'operating' => ['label' => __('Net operating income'), 'value' => fn (array $pl): int => (int) $pl['operating']],
            'net' => ['label' => __('Net income'), 'value' => fn (array $pl): int => (int) $pl['net']],
        ];
    }

    /**
     * Every Location's profit and loss added together.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function profitAndLossTotal(): array
    {
        $all = $this->report->profitAndLoss();

        return collect(['income', 'cost', 'expenses', 'operating', 'net'])->mapWithKeys(fn (string $key): array => [$key => (int) $all->sum($key)])->all();
    }

    public static function netPercent(int $income, int $net): string
    {
        return $income === 0 ? '—' : round($net * 100 / $income, 1).'%';
    }

    public function download(string $table): StreamedResponse
    {
        $report = $this->report;

        return match ($table) {
            'aging' => $this->csv('ar-aging', [
                [__('Location'), __('Not due yet'), __('8 to 30 days'), __('With Collections'), __('Total owed'), __('Jobs')],
                ...$report->aging()->map(fn (array $row): array => [$row['location'], self::dollarsCell($row['notDue']), self::dollarsCell($row['late']), self::dollarsCell($row['collections']), self::dollarsCell($row['total']), $row['jobs']])->values(),
            ]),
            'cash' => $this->csv('cash-collected', [
                [__('Month'), __('Cash collected'), __('Payments')],
                ...collect($report->cashByMonth())->map(fn (array $month, string $key): array => [$key, self::dollarsCell($month['cents']), $month['payments']])->values(),
            ]),
            'jobs' => $this->csv('job-costing', [
                [__('Location'), __('Jobs closed'), __('Revenue'), __('Budget'), __('Cost'), __('Gross profit'), __('Under (over) budget'), __('Jobs over budget')],
                ...$report->jobCosts()->map(fn (array $row): array => [$row['location'], $row['jobs'], self::dollarsCell($row['revenue']), self::dollarsCell($row['budget']), self::dollarsCell($row['cost']), self::dollarsCell($row['profit']), self::dollarsCell($row['variance']), $row['overBudget']])->values(),
            ]),
            'pay' => $this->seesPay ? $this->csv('pay-owed', [
                [__('Person'), __('Due in open periods'), __('Scheduled, not paid')],
                ...FinanceReport::payOwed()->map(fn (array $person): array => [$person['name'], self::dollarsCell($person['open']), self::dollarsCell($person['scheduled'])])->values(),
            ]) : abort(403),
            default => $this->csv('profit-and-loss', [
                [__('Line'), ...$this->shown->sortBy('name')->pluck('name')->all(), __('Total')],
                ...collect($this->lines())->map(fn (array $line): array => [$line['label'], ...$report->profitAndLoss()->map(fn (array $pl): string => self::dollarsCell(($line['value'])($pl)))->values()->all(), self::dollarsCell(($line['value'])($this->profitAndLossTotal))])->values(),
            ]),
        };
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="finance-reports">
    <div>
        <flux:heading size="xl" level="1">{{ __('Reports') }}</flux:heading>
        <flux:subheading>{{ __('Profit and loss by location, what customers owe, cash collected, job costing and pay owed.') }}</flux:subheading>
    </div>

    @if ($this->locations->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-none">
            <flux:text>{{ __('There are no reports for you to see.') }}</flux:text>
        </div>
    @else
        @include('partials.reports.filters')

        @php
            $report = $this->report;
            $pl = $report->profitAndLoss();
            $total = $this->profitAndLossTotal;
            $several = $this->shown->count() > 1;
            $missing = $pl->filter(fn (array $row): bool => $row['missing'] > 0);
        @endphp

        {{-- Profit and loss, the default (9.7): each Location side by side, from QuickBooks. --}}
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Profit and loss') }}</flux:heading>
                    <flux:subheading>{{ __('From each location\'s QuickBooks, for the whole months these days touch: :months.', ['months' => collect($report->months())->map(fn (string $month): string => CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y'))->join(', ', ' and ')]) }}</flux:subheading>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('pl')" data-test="download-pl">{{ __('Download') }}</flux:button>
            </div>
            @if ($missing->isNotEmpty())
                <flux:text class="text-sm text-amber-800" data-test="pl-missing">{{ __('Not read from QuickBooks yet for some months: :locations. Connect each location\'s QuickBooks in Settings > Integrations; it is read each night.', ['locations' => $missing->pluck('location')->join(', ')]) }}</flux:text>
            @endif
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="profit-and-loss">
                    <flux:table.columns>
                        <flux:table.column></flux:table.column>
                        @foreach ($pl as $row)
                            <flux:table.column align="end">{{ $row['location'] }}</flux:table.column>
                        @endforeach
                        @if ($several)
                            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                        @endif
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($this->lines() as $key => $line)
                            <flux:table.row :key="'pl-'.$key" data-test="pl-{{ $key }}">
                                <flux:table.cell class="ps-3 {{ in_array($key, ['gross', 'net'], true) ? 'font-semibold text-ink' : 'text-zinc-700' }}">{{ $line['label'] }}</flux:table.cell>
                                @foreach ($pl as $locationId => $row)
                                    <flux:table.cell align="end" class="tabular-nums {{ in_array($key, ['gross', 'net'], true) ? 'font-semibold text-ink' : '' }}">{{ Money::format(($line['value'])($row)) }}</flux:table.cell>
                                @endforeach
                                @if ($several)
                                    <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ Money::format(($line['value'])($total)) }}</flux:table.cell>
                                @endif
                            </flux:table.row>
                        @endforeach
                        <flux:table.row data-test="pl-percent">
                            <flux:table.cell class="ps-3 text-zinc-700">{{ __('Net profit %') }}</flux:table.cell>
                            @foreach ($pl as $row)
                                <flux:table.cell align="end" class="tabular-nums">{{ self::netPercent($row['income'], $row['net']) }}</flux:table.cell>
                            @endforeach
                            @if ($several)
                                <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ self::netPercent($total['income'], $total['net']) }}</flux:table.cell>
                            @endif
                        </flux:table.row>
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            {{-- What customers owe now, by age (10.6). --}}
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('AR aging') }}</flux:heading>
                        <flux:subheading>{{ __('What customers owe on final invoices today, whatever the days chosen.') }}</flux:subheading>
                    </div>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('aging')" data-test="download-aging">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="ar-aging">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Location') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Not due yet') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('8 to 30 days') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Collections') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Total') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($report->aging() as $locationId => $row)
                                <flux:table.row :key="'aging-'.$locationId">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ $row['location'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['notDue']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['late']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['collections']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ Money::format($row['total']) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>

            {{-- Cash collected by month (9.7). --}}
            <div class="flex min-w-0 flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Cash collected') }}</flux:heading>
                        <flux:subheading>{{ __('Payments received in these days, approved and not reversed.') }}</flux:subheading>
                    </div>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('cash')" data-test="download-cash">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="cash-collected">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Month') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Payments') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Collected') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach ($report->cashByMonth() as $month => $cash)
                                <flux:table.row :key="'cash-'.$month" data-test="cash-{{ $month }}">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ CarbonImmutable::createFromFormat('!Y-m', $month)->format('M Y') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ $cash['payments'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 tabular-nums">{{ Money::format($cash['cents']) }}</flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        </div>

        {{-- Gross profit and job-cost variance on the jobs financially closed (6.9, 9.3). --}}
        <div class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Job costing on closed jobs') }}</flux:heading>
                    <flux:subheading>{{ __('Jobs whose final gross margin Finance approved in these days: the budget each was set, what it cost, and how far under or over it came in.') }}</flux:subheading>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" wire:click="download('jobs')" data-test="download-jobs">{{ __('Download') }}</flux:button>
            </div>
            <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                <flux:table data-test="job-costing">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Location') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Jobs') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Revenue') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Budget') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Cost') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Gross profit') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('GP %') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Under (over) budget') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Over budget') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @foreach ($report->jobCosts() as $locationId => $row)
                            <flux:table.row :key="'jobs-'.$locationId" data-test="jobs-{{ $locationId }}">
                                <flux:table.cell class="ps-3 font-medium text-ink">{{ $row['location'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ $row['jobs'] }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['revenue']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['budget']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['cost']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ Money::format($row['profit']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums">{{ self::netPercent($row['revenue'], $row['profit']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="tabular-nums {{ $row['variance'] < 0 ? 'text-red-700' : '' }}">{{ $row['variance'] < 0 ? '('.Money::format(-$row['variance']).')' : Money::format($row['variance']) }}</flux:table.cell>
                                <flux:table.cell align="end" class="pe-3 tabular-nums">{{ $row['overBudget'] }}</flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>
        </div>

        @if ($this->seesPay)
            @php
                $owed = FinanceReport::payOwed();
            @endphp
            <div class="flex flex-col gap-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading size="lg" level="2">{{ __('Pay owed, not yet paid') }}</flux:heading>
                        <flux:subheading>{{ __('Commission and bonuses company-wide, today: due in pay periods still open, and scheduled in finalized periods not yet paid.') }}</flux:subheading>
                    </div>
                    <flux:button size="sm" icon="arrow-down-tray" wire:click="download('pay')" data-test="download-pay">{{ __('Download') }}</flux:button>
                </div>
                <div class="overflow-x-auto rounded-xl border border-zinc-200 bg-card">
                    <flux:table data-test="pay-owed">
                        <flux:table.columns>
                            <flux:table.column>{{ __('Person') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Due in open periods') }}</flux:table.column>
                            <flux:table.column align="end">{{ __('Scheduled, not paid') }}</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($owed as $userId => $person)
                                <flux:table.row :key="'pay-'.$userId">
                                    <flux:table.cell class="ps-3 font-medium text-ink">{{ $person['name'] }}</flux:table.cell>
                                    <flux:table.cell align="end" class="tabular-nums">{{ Money::format($person['open']) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 tabular-nums">{{ Money::format($person['scheduled']) }}</flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="ps-3">{{ __('Nothing is owed.') }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                            @if ($owed->count() > 1)
                                <flux:table.row>
                                    <flux:table.cell class="ps-3 font-semibold text-ink">{{ __('Total') }}</flux:table.cell>
                                    <flux:table.cell align="end" class="font-semibold tabular-nums text-ink">{{ Money::format($owed->sum('open')) }}</flux:table.cell>
                                    <flux:table.cell align="end" class="pe-3 font-semibold tabular-nums text-ink">{{ Money::format($owed->sum('scheduled')) }}</flux:table.cell>
                                </flux:table.row>
                            @endif
                        </flux:table.rows>
                    </flux:table>
                </div>
            </div>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="reports-how">
            <flux:heading size="lg" level="2">{{ __('How these are counted') }}</flux:heading>

            <ul class="mt-3 flex list-disc flex-col gap-2 ps-5 text-sm text-zinc-700">
                <li>{{ __('Days are the company\'s, as on Finance > Performance.') }}</li>
                <li>{{ __('Profit and loss is QuickBooks\' own, read each night, for whole months: QuickBooks is the ledger, Backoffice never keeps a second one.') }}</li>
                <li>{{ __('AR aging and pay owed are how things stand today.') }}</li>
                <li>{{ __('Job costing: budget is the most a job could cost and still make its location\'s target margin at its floor price; cost is its payouts and costs at Finance\'s approval.') }}</li>
            </ul>
        </div>
    @endif
</section>
