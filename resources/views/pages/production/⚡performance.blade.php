<?php

use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Support\Money;
use App\Support\Performance\ProductionBoard;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * Production > Performance (docs/slice-23-performance-boards-plan.md, step
 * 23a; decision 179; product-architecture.md, 6.1): the month's completed
 * revenue and gross margin, work on time and running over, what customers
 * owe, job-costing timeliness and accuracy, warranty, the jobs at risk, and
 * each Production Manager's month. Read only.
 */
new #[Title('Production Performance')] class extends PerformanceBoard {
    public function mount(): void
    {
        $this->authorize(Permission::ProductionAccess);
    }

    protected function boardPermission(): Permission
    {
        return Permission::ProductionAccess;
    }

    #[Computed]
    public function board(): ProductionBoard
    {
        return ProductionBoard::for($this->shownLocationIds, $this->chosenMonth);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="production-performance">
    @php
        $board = $this->board;
        $completed = $board->completed();
        $onTime = $board->onTime();
        $owed = $board->owed();
        $jobCosting = $board->jobCosting();
        $warranty = $board->warranty();
        $atRisk = $board->atRisk();
        $managers = $board->productionManagers();
        $percent = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
        $statusWords = ['on_track' => __('On track'), 'at_risk' => __('At risk'), 'below_floor' => __('Below the floor')];
        $statusColors = ['on_track' => 'emerald', 'at_risk' => 'amber', 'below_floor' => 'zinc'];
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
        <flux:subheading>{{ __('How production is going: the month\'s completed work, and how things stand now. Everything is done on each job.') }}</flux:subheading>
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
            <div class="mt-1 text-sm text-zinc-700">{{ __(':margin of gross-margin dollars, before allocations', ['margin' => Money::format($completed['margin'])]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-on-time">
            <flux:text class="text-sm">{{ __('Work finished on time') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $onTime['onTime'] }} / {{ $onTime['onTime'] + $onTime['late'] }}</div>
            <div class="mt-1 text-sm {{ $onTime['runningOver'] > 0 ? 'text-rose-700' : 'text-zinc-700' }}">{{ trans_choice(':count running over now|:count running over now', $onTime['runningOver']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-owed">
            <flux:text class="text-sm">{{ __('Customers owe now') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ Money::format($owed['owed']) }}</div>
            <div class="mt-1 text-sm {{ $owed['late'] > 0 ? 'text-rose-700' : 'text-zinc-700' }}">{{ __(':jobs jobs; :late late, :collections with Collections', ['jobs' => $owed['jobs'], 'late' => $owed['late'], 'collections' => $owed['collections']]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-job-costing">
            <flux:text class="text-sm">{{ __('Days from signoff to job costing') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $jobCosting['averageDays'] ?? '—' }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count submitted this month|:count submitted this month', $jobCosting['submitted']) }} &middot; {{ trans_choice(':count accuracy strike in 90 days|:count accuracy strikes in 90 days', $jobCosting['strikes']) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-warranty">
            <flux:text class="text-sm">{{ __('Warranty cases opened') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $warranty['opened'] }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count open now|:count open now', $warranty['open']) }}</div>
        </div>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-production-managers">
        <div>
            <flux:heading size="lg" level="2">{{ __('Production Managers, :month', ['month' => $this->chosenMonthWords()]) }}</flux:heading>
            <flux:subheading>{{ __('Gross-margin dollars and margin on the jobs each closed. On track means a 55% margin or more and above $37,500.') }}</flux:subheading>
        </div>

        @if ($managers->isEmpty())
            <flux:text class="text-sm">{{ __('No job was financially closed this month.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($managers as $row)
                    <li wire:key="board-manager-{{ $loop->index }}" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0" data-test="board-manager">
                        <span class="font-semibold text-ink">{{ $row['name'] }}</span>
                        <span class="flex items-center gap-3 text-sm">
                            <span class="font-nav font-bold tabular-nums text-ink">{{ Money::format($row['margin']) }}</span>
                            <span class="tabular-nums text-zinc-700">{{ $percent($row['percent']) }}</span>
                            <flux:badge size="sm" :color="$statusColors[$row['status']]">{{ $statusWords[$row['status']] }}</flux:badge>
                        </span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-at-risk">
        <div>
            <flux:heading size="lg" level="2">{{ __('Jobs at risk now') }}</flux:heading>
            <flux:subheading>{{ __('Work running past its last day, over budget, a final balance past due, or an open warranty case.') }}</flux:subheading>
        </div>

        @if ($atRisk->isEmpty())
            <flux:text class="text-sm">{{ __('No job is at risk.') }}</flux:text>
        @else
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($atRisk as $row)
                    <li wire:key="board-risk-{{ $row['project']->id }}" class="flex flex-col py-2 first:pt-0 last:pb-0" data-test="board-risk">
                        <flux:link :href="route('projects.show', $row['project'])" wire:navigate class="font-semibold">{{ $row['project']->title }}</flux:link>
                        <span class="text-sm text-rose-700">{{ collect($row['reasons'])->map(fn (string $reason): string => __($reason))->implode(' · ') }}</span>
                        <span class="text-sm text-zinc-500">{{ $row['project']->production_manager_name ?? __('No Production Manager') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    @if ($jobCosting['overdue']->isNotEmpty())
        <div class="flex flex-col gap-4 rounded-xl border border-amber-300 bg-card p-5" data-test="board-costing-overdue">
            <flux:heading size="lg" level="2">{{ __('Signed off, job costing not submitted after 7 days') }}</flux:heading>
            <ul class="flex flex-col divide-y divide-zinc-200">
                @foreach ($jobCosting['overdue'] as $project)
                    <li wire:key="board-overdue-{{ $project->id }}" class="flex flex-col py-2 first:pt-0 last:pb-0">
                        <flux:link :href="route('projects.show', $project)" wire:navigate class="font-semibold">{{ $project->title }}</flux:link>
                        <span class="text-sm text-zinc-500">{{ $project->production_manager_name ?? __('No Production Manager') }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</section>
