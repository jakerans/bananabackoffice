<?php

use App\Actions\QuickBooks\ReadProfitAndLoss;
use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Models\LocationProfitAndLoss;
use App\Models\QuickBooksConnection;
use App\Support\Money;
use App\Support\Performance\FinanceBoard;
use App\Support\QuickBooks\QuickBooksClient;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * Finance > Performance (docs/slice-23-performance-boards-plan.md, step
 * 23b; decision 179; product-architecture.md, 9.1): the month's completed
 * revenue and gross margin, cash collected and warranty cost; what is owed
 * by age, the jobs waiting on Finance and, for Finance and the Owner,
 * variable pay owed. Read only.
 */
new #[Title('Finance Performance')] class extends PerformanceBoard {
    public function mount(): void
    {
        $this->authorize(Permission::FinanceAccess);
    }

    protected function boardPermission(): Permission
    {
        return Permission::FinanceAccess;
    }

    #[Computed]
    public function board(): FinanceBoard
    {
        return FinanceBoard::for($this->shownLocationIds, $this->chosenMonth);
    }

    /**
     * Each shown Location's profit and loss for the month, as last read from
     * its QuickBooks company (slice 28, step 28b).
     *
     * @return Collection<int, LocationProfitAndLoss>
     */
    #[Computed]
    public function statements(): Collection
    {
        return LocationProfitAndLoss::query()
            ->whereIn('location_id', $this->shownLocationIds())
            ->where('month', $this->chosenMonth())
            ->get()
            ->keyBy('location_id');
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function connectedIds(): array
    {
        return array_values(QuickBooksConnection::query()->whereIn('location_id', $this->shownLocationIds())->pluck('location_id')->map(fn (mixed $id): int => (int) $id)->all());
    }

    /**
     * Read this month and last month from QuickBooks now, for the shown
     * Locations (decision 189, question 6).
     */
    public function readQuickBooks(ReadProfitAndLoss $readProfitAndLoss): void
    {
        $this->authorize(Permission::FinanceAccess);

        $done = $readProfitAndLoss->recent($this->shownLocationIds());
        unset($this->statements);

        if ($done['failed'] !== []) {
            Flux::toast(variant: 'warning', text: __('QuickBooks could not be read for :locations. Try again in a moment, or connect it again in Settings > Integrations.', ['locations' => implode(', ', $done['failed'])]));

            return;
        }

        Flux::toast(variant: 'success', text: __('Read from QuickBooks.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-performance">
    @php
        $board = $this->board;
        $completed = $board->completed();
        $cash = $board->cashCollected();
        $aging = $board->aging();
        $waiting = $board->awaitingClose();
        $adjusted = $board->adjustments();
        $percent = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
        <flux:subheading>{{ __('The month\'s revenue, margin and cash, what is owed now, and each Location\'s profit and loss from QuickBooks.') }}</flux:subheading>
    </div>

    <div class="flex flex-wrap items-end gap-4" data-test="performance-filters">
        <flux:select wire:model.live="month" :label="__('Month')" class="max-w-xs" data-test="performance-month">
            @foreach ($this->months as $option)
                <flux:select.option :value="$option['value']" wire:key="month-{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($this->locations->count() > 1)
            <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="performance-location">
                <flux:select.option value="">{{ __('All my Locations') }}</flux:select.option>
                @foreach ($this->locations as $option)
                    <flux:select.option :value="$option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                @endforeach
            </flux:select>
        @endif
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-completed">
            <flux:text class="text-sm">{{ __('Completed revenue, :month', ['month' => $this->chosenMonthWords()]) }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ Money::format($completed['revenue']) }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count job financially closed|:count jobs financially closed', $completed['jobs']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-margin">
            <flux:text class="text-sm">{{ __('Gross margin on it') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $percent($completed['percent']) }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ __(':margin before allocations; :warranty of warranty cost this month', ['margin' => Money::format($completed['margin']), 'warranty' => Money::format($board->warrantyCents())]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-cash">
            <flux:text class="text-sm">{{ __('Cash collected') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ Money::format($cash['cents']) }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count payment received|:count payments received', $cash['payments']) }}</div>
        </div>
    </div>

    {{-- Profit and loss, as each Location's QuickBooks company reports it (slice 28, step 28b). --}}
    @php
        $shown = $this->locations->whereIn('id', $this->shownLocationIds)->values();
        $statements = $this->statements;
        $netPercent = fn (?int $basisPoints): string => $basisPoints === null ? '—' : rtrim(rtrim(number_format($basisPoints / 100, 1), '0'), '.').'%';
        $totalIncome = (int) $statements->sum('income_cents');
        $totalNet = (int) $statements->sum('net_income_cents');
    @endphp
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-profit-and-loss">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Profit and loss, :month', ['month' => $this->chosenMonthWords()]) }}</flux:heading>
                <flux:subheading>{{ __('As each Location\'s QuickBooks company reports it, the month so far. Read every night.') }}</flux:subheading>
            </div>
            @if (QuickBooksClient::isConfigured() && $this->connectedIds !== [])
                <flux:button size="sm" icon="arrow-path" wire:click="readQuickBooks" data-test="read-quickbooks">{{ __('Refresh now') }}</flux:button>
            @endif
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-zinc-500">
                        <th class="py-2 pe-4 font-medium">{{ __('Location') }}</th>
                        <th class="py-2 pe-4 text-right font-medium">{{ __('Income') }}</th>
                        <th class="py-2 pe-4 text-right font-medium">{{ __('Cost of goods sold') }}</th>
                        <th class="py-2 pe-4 text-right font-medium">{{ __('Expenses') }}</th>
                        <th class="py-2 pe-4 text-right font-medium">{{ __('Net income') }}</th>
                        <th class="py-2 pe-4 text-right font-medium">{{ __('Net profit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 tabular-nums">
                    @foreach ($shown as $location)
                        @php
                            $statement = $statements->get($location->id);
                        @endphp
                        <tr wire:key="pl-{{ $location->id }}" data-test="pl-{{ $location->id }}">
                            <td class="py-2 pe-4">
                                <span class="block text-ink">{{ $location->name }}</span>
                                <span class="block text-xs text-zinc-500">
                                    @if ($statement !== null)
                                        {{ __('Read :when', ['when' => $statement->read_at->timezone((string) config('app.display_timezone'))->format('j M, g:i a')]) }}
                                    @elseif (in_array($location->id, $this->connectedIds, true))
                                        {{ __('Not read yet') }}
                                    @else
                                        {{ __('Not connected to QuickBooks') }}
                                    @endif
                                </span>
                            </td>
                            @if ($statement !== null)
                                <td class="py-2 pe-4 text-right">{{ Money::format($statement->income_cents) }}</td>
                                <td class="py-2 pe-4 text-right">{{ Money::format($statement->cost_of_goods_cents) }}</td>
                                <td class="py-2 pe-4 text-right">{{ Money::format($statement->expenses_cents) }}</td>
                                <td class="py-2 pe-4 text-right font-semibold {{ $statement->net_income_cents < 0 ? 'text-rose-700' : 'text-ink' }}">{{ Money::format($statement->net_income_cents) }}</td>
                                <td class="py-2 pe-4 text-right font-semibold text-ink">{{ $netPercent($statement->netProfitBasisPoints()) }}</td>
                            @else
                                <td colspan="5" class="py-2 pe-4 text-right text-zinc-400">—</td>
                            @endif
                        </tr>
                    @endforeach
                    @if ($shown->count() > 1 && $statements->isNotEmpty())
                        <tr data-test="pl-total" class="font-semibold">
                            <td class="py-2 pe-4 text-ink">{{ __('Together') }}</td>
                            <td class="py-2 pe-4 text-right">{{ Money::format($totalIncome) }}</td>
                            <td class="py-2 pe-4 text-right">{{ Money::format((int) $statements->sum('cost_of_goods_cents')) }}</td>
                            <td class="py-2 pe-4 text-right">{{ Money::format((int) $statements->sum('expenses_cents')) }}</td>
                            <td class="py-2 pe-4 text-right {{ $totalNet < 0 ? 'text-rose-700' : 'text-ink' }}">{{ Money::format($totalNet) }}</td>
                            <td class="py-2 pe-4 text-right text-ink">{{ $netPercent(LocationProfitAndLoss::percent($totalIncome, $totalNet)) }}</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Credits, refunds and write-offs of the month (slice 24). --}}
    <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-adjustments">
        <flux:heading size="lg" level="2">{{ __('Credits, refunds and write-offs, :month', ['month' => $this->chosenMonthWords()]) }}</flux:heading>
        <dl class="grid grid-cols-3 gap-3 text-sm">
            <div><dt class="text-zinc-500">{{ __('Credits approved') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="board-credits">{{ Money::format($adjusted['credits']) }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('Refunds paid back') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="board-refunds">{{ Money::format($adjusted['refunds']) }}</dd></div>
            <div><dt class="text-zinc-500">{{ __('Written off as bad debt') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums {{ $adjusted['writeOffs'] > 0 ? 'text-rose-700' : 'text-ink' }}" data-test="board-write-offs">{{ Money::format($adjusted['writeOffs']) }}</dd></div>
        </dl>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-aging">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <flux:heading size="lg" level="2">{{ __('Owed now') }}</flux:heading>
                <span class="font-nav text-2xl font-bold tabular-nums text-ink">{{ Money::format($aging['total']) }}</span>
            </div>
            <dl class="grid grid-cols-3 gap-3 text-sm">
                <div><dt class="text-zinc-500">{{ __('Not due yet') }}</dt><dd class="font-semibold tabular-nums text-ink" data-test="aging-not-due">{{ Money::format($aging['notDue']) }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Late') }}</dt><dd class="font-semibold tabular-nums {{ $aging['late'] > 0 ? 'text-rose-700' : 'text-ink' }}" data-test="aging-late">{{ Money::format($aging['late']) }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('With Collections') }}</dt><dd class="font-semibold tabular-nums {{ $aging['collections'] > 0 ? 'text-rose-700' : 'text-ink' }}" data-test="aging-collections">{{ Money::format($aging['collections']) }}</dd></div>
            </dl>
            <flux:link :href="route('finance.receivables')" wire:navigate class="text-sm">{{ trans_choice('{1} 1 job on Receivables|[0,*] :count jobs on Receivables', $aging['jobs']) }}</flux:link>
        </div>

        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-waiting">
            <flux:heading size="lg" level="2">{{ __('Waiting on Finance') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">{{ __('To reconcile') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="waiting-reconcile">{{ $waiting['toReconcile'] }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Approved, to close') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="waiting-close">{{ $waiting['toClose'] }}</dd></div>
            </dl>
            <flux:link :href="route('finance.job-costing')" wire:navigate class="text-sm">{{ __('Job Costing') }}</flux:link>
        </div>
    </div>

    {{-- Variable pay is one company payroll, for Finance and the Owner (slice 20). --}}
    @if (Gate::allows(Permission::ManageCompensation))
        @php($owed = FinanceBoard::payOwed())
        <div class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-pay-owed">
            <div>
                <flux:heading size="lg" level="2">{{ __('Variable pay owed') }}</flux:heading>
                <flux:subheading>{{ __('Commission and Production Bonus not yet paid, for every Location.') }}</flux:subheading>
            </div>
            <dl class="grid grid-cols-2 gap-3 text-sm">
                <div><dt class="text-zinc-500">{{ __('Due in open periods') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="pay-owed-open">{{ Money::format($owed['open']) }}</dd></div>
                <div><dt class="text-zinc-500">{{ __('Scheduled for payroll') }}</dt><dd class="font-nav text-2xl font-bold tabular-nums text-ink" data-test="pay-owed-scheduled">{{ Money::format($owed['scheduled']) }}</dd></div>
            </dl>
            <flux:link :href="route('finance.payroll')" wire:navigate class="text-sm">{{ __('Payroll') }}</flux:link>
        </div>
    @endif
</section>
