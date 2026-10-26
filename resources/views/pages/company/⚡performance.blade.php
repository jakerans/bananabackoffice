<?php

use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Support\Money;
use App\Support\Performance\ExecutiveBoard;
use App\Support\Performance\MarketingRow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * Company > Executive Board (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25c; decision 183, questions 6 to 8; product-architecture.md, 18):
 * each Location's key numbers for a month side by side, and the company's,
 * each KPI with its own status; the leaderboard's top three. Each links to
 * its own board. For Company Overview access. Read only.
 */
new #[Title('Executive Board')] class extends PerformanceBoard {
    public function mount(): void
    {
        $this->authorize(Permission::CompanyOverviewAccess);
    }

    protected function boardPermission(): Permission
    {
        return Permission::CompanyOverviewAccess;
    }

    #[Computed]
    public function board(): ExecutiveBoard
    {
        return ExecutiveBoard::for($this->locations, $this->chosenMonth);
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6" data-test="executive-board">
    @php
        $columns = $this->board->columns();
        $leaders = $this->board->leaders();
        $percent = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
        $paceWords = [ExecutiveBoard::ON_PACE => __('On pace'), ExecutiveBoard::BEHIND => __('Behind'), ExecutiveBoard::NO_TARGET => __('No target')];
        $paceColors = [ExecutiveBoard::ON_PACE => 'emerald', ExecutiveBoard::BEHIND => 'rose', ExecutiveBoard::NO_TARGET => 'zinc'];
    @endphp

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Executive Board') }}</flux:heading>
            <flux:subheading>{{ __('Each Location\'s key numbers side by side, each with its own status. Net profit is as each Location\'s QuickBooks last reported it.') }}</flux:subheading>
        </div>
        <div class="flex flex-wrap items-end gap-3">
            <flux:select wire:model.live="month" :label="__('Month')" class="max-w-xs" data-test="executive-month">
                @foreach ($this->months as $option)
                    <flux:select.option :value="$option['value']" wire:key="month-{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button icon="tv" :href="route('company.performance.tv')" data-test="enter-tv">{{ __('Enter TV Mode') }}</flux:button>
        </div>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="executive-kpis">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-zinc-500">
                        <th class="py-2 pe-4 font-medium">{{ $this->chosenMonthWords() }}</th>
                        @foreach ($columns as $column)
                            <th wire:key="executive-head-{{ $loop->index }}" class="py-2 pe-4 text-right font-semibold {{ $loop->last && count($columns) > 1 ? 'text-ink' : 'text-zinc-700' }}">{{ __($column['name']) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 tabular-nums">
                    <tr data-test="executive-sold">
                        <td class="py-2 pe-4"><flux:link :href="route('sales.performance')" wire:navigate>{{ __('Sold revenue') }}</flux:link></td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-sold-{{ $loop->index }}" class="py-2 pe-4 text-right">
                                <span class="block font-semibold text-ink">{{ Money::format($column['sold']) }}</span>
                                <span class="block text-xs text-zinc-500">{{ $column['target'] === null ? '' : __('of :target', ['target' => Money::format($column['target'])]) }}</span>
                                <flux:badge size="sm" :color="$paceColors[$column['pace']]">{{ $paceWords[$column['pace']] }}</flux:badge>
                            </td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-completed">
                        <td class="py-2 pe-4"><flux:link :href="route('production.performance')" wire:navigate>{{ __('Completed revenue') }}</flux:link></td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-completed-{{ $loop->index }}" class="py-2 pe-4 text-right font-semibold text-ink">{{ Money::format($column['completed']) }}</td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-margin">
                        <td class="py-2 pe-4">{{ __('Gross margin') }}</td>
                        @foreach ($columns as $column)
                            @php
                                $belowTarget = $column['marginPercent'] !== null && $column['targetMargin'] !== null && $column['marginPercent'] < $column['targetMargin'];
                            @endphp
                            <td wire:key="executive-margin-{{ $loop->index }}" class="py-2 pe-4 text-right">
                                <span class="block font-semibold {{ $belowTarget ? 'text-rose-700' : 'text-ink' }}">{{ $percent($column['marginPercent']) }}</span>
                                @if ($column['targetMargin'] !== null)
                                    <span class="block text-xs text-zinc-500">{{ __('target :percent%', ['percent' => $column['targetMargin']]) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-net-profit">
                        <td class="py-2 pe-4">{{ __('Net profit, from QuickBooks') }}</td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-net-{{ $loop->index }}" class="py-2 pe-4 text-right">
                                <span class="block font-semibold {{ ($column['netProfit'] ?? 0) < 0 ? 'text-rose-700' : 'text-ink' }}">{{ $column['netProfit'] === null ? '—' : $percent($column['netProfit'] / 100) }}</span>
                                @if ($column['netIncome'] !== null)
                                    <span class="block text-xs text-zinc-500">{{ Money::format($column['netIncome']) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-cash">
                        <td class="py-2 pe-4"><flux:link :href="route('finance.performance')" wire:navigate>{{ __('Cash collected') }}</flux:link></td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-cash-{{ $loop->index }}" class="py-2 pe-4 text-right font-semibold text-ink">{{ Money::format($column['cash']) }}</td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-late">
                        <td class="py-2 pe-4">{{ __('Balances late / with Collections, now') }}</td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-late-{{ $loop->index }}" class="py-2 pe-4 text-right font-semibold {{ $column['late'] + $column['collections'] > 0 ? 'text-rose-700' : 'text-ink' }}">{{ $column['late'] }} / {{ $column['collections'] }}</td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-leads">
                        <td class="py-2 pe-4"><flux:link :href="route('marketing.performance')" wire:navigate>{{ __('Leads, cost per lead') }}</flux:link></td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-leads-{{ $loop->index }}" class="py-2 pe-4 text-right">
                                <span class="block font-semibold text-ink">{{ $column['leads'] }}</span>
                                <span class="block text-xs text-zinc-500">{{ MarketingRow::money($column['costPerLead']) }}</span>
                            </td>
                        @endforeach
                    </tr>
                    <tr data-test="executive-at-risk">
                        <td class="py-2 pe-4">{{ __('Jobs at risk, now') }}</td>
                        @foreach ($columns as $column)
                            <td wire:key="executive-risk-{{ $loop->index }}" class="py-2 pe-4 text-right font-semibold {{ $column['atRisk'] > 0 ? 'text-rose-700' : 'text-ink' }}">{{ $column['atRisk'] }}</td>
                        @endforeach
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="executive-leaders">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <flux:heading size="lg" level="2">{{ __('Leaderboard, :month', ['month' => $this->chosenMonthWords()]) }}</flux:heading>
            <flux:link :href="route('sales.leaderboard')" wire:navigate class="text-sm">{{ __('Leaderboard') }}</flux:link>
        </div>
        @if ($leaders->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing sold yet.') }}</flux:text>
        @else
            <ol class="flex flex-col divide-y divide-zinc-200">
                @foreach ($leaders as $row)
                    <li wire:key="executive-leader-{{ $row['id'] }}" class="flex items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                        <span class="flex items-center gap-3"><span class="w-5 text-right font-nav font-bold text-zinc-500">{{ $loop->iteration }}</span><span class="text-ink">{{ $row['name'] }}</span></span>
                        <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($row['cents']) }}</span>
                    </li>
                @endforeach
            </ol>
        @endif
    </div>
</section>
