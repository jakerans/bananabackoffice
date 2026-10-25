<?php

use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Support\Money;
use App\Support\Performance\FinanceBoard;
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
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="finance-performance">
    @php
        $board = $this->board;
        $completed = $board->completed();
        $cash = $board->cashCollected();
        $aging = $board->aging();
        $waiting = $board->awaitingClose();
        $percent = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
        <flux:subheading>{{ __('The month\'s revenue, margin and cash, and what is owed now. Net profit and the P&L stay in QuickBooks.') }}</flux:subheading>
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
