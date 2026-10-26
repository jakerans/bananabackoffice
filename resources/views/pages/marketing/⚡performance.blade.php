<?php

use App\Enums\Permission;
use App\Livewire\PerformanceBoard;
use App\Support\Money;
use App\Support\Performance\MarketingBoard;
use App\Support\Performance\MarketingRow;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;

/**
 * Marketing > Performance (docs/slice-25-marketing-and-executive-boards-plan.md,
 * step 25b; decision 183, questions 4 and 5; product-architecture.md, 4.1):
 * for a month, by Lead Source and in total, spend, leads, cost per lead,
 * appointments issued, cost per appointment, sales, cost per sale, sold
 * revenue, return on spend and close rate. Read only.
 */
new #[Title('Marketing Performance')] class extends PerformanceBoard {
    public function mount(): void
    {
        $this->authorize(Permission::MarketingAccess);
    }

    protected function boardPermission(): Permission
    {
        return Permission::MarketingAccess;
    }

    #[Computed]
    public function board(): MarketingBoard
    {
        return MarketingBoard::for($this->locations->whereIn('id', $this->shownLocationIds)->values(), $this->chosenMonth);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="marketing-performance">
    @php
        $rows = $this->board->rows();
        $total = $this->board->total();
        $percent = fn (?int $value): string => $value === null ? '—' : $value.'%';
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Performance') }}</flux:heading>
            <flux:subheading>{{ __('What each Lead Source cost and brought in: leads received, appointments issued and sales accepted in the month, against the spend recorded for it.') }}</flux:subheading>
        </div>
        @can(Permission::MarketingSpendManage)
            <flux:button size="sm" :href="route('marketing.performance.spend', ['month' => $this->chosenMonth])" wire:navigate icon="banknotes" data-test="go-to-spend">{{ __('Marketing spend') }}</flux:button>
        @endcan
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

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-spend">
            <flux:text class="text-sm">{{ __('Spend, :month', ['month' => $this->chosenMonthWords()]) }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ Money::format($total->spendCents) }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count lead|:count leads', $total->leads) }} &middot; {{ __(':cost per lead', ['cost' => MarketingRow::money($total->costPerLeadCents())]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-appointments">
            <flux:text class="text-sm">{{ __('Appointments issued') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $total->appointments }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ __(':cost per appointment', ['cost' => MarketingRow::money($total->costPerAppointmentCents())]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-sold">
            <flux:text class="text-sm">{{ __('Sold revenue') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ Money::format($total->soldCents) }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ trans_choice(':count sale|:count sales', $total->sales) }} &middot; {{ __(':cost per sale', ['cost' => MarketingRow::money($total->costPerSaleCents())]) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="board-return">
            <flux:text class="text-sm">{{ __('Return on spend') }}</flux:text>
            <div class="mt-1 font-nav text-3xl font-bold tabular-nums text-ink" data-test="board-value">{{ $total->returnWords() ?? '—' }}</div>
            <div class="mt-1 text-sm text-zinc-700">{{ __('Close rate :rate', ['rate' => $percent($total->closeRate())]) }}</div>
        </div>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="board-sources">
        <flux:heading size="lg" level="2">{{ __('By Lead Source') }}</flux:heading>

        @if ($rows->isEmpty())
            <flux:text class="text-sm">{{ __('Nothing this month: no spend, leads or sales.') }}</flux:text>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[46rem] text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-left text-zinc-500">
                            <th class="py-2 pe-3 font-medium">{{ __('Lead Source') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Spend') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Leads') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Per lead') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Appts.') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Per appt.') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Sales') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Per sale') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Sold') }}</th>
                            <th class="py-2 pe-3 text-right font-medium">{{ __('Return') }}</th>
                            <th class="py-2 text-right font-medium">{{ __('Close rate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 tabular-nums">
                        @foreach ($rows as $row)
                            <tr wire:key="source-{{ $loop->index }}" data-test="board-source-row">
                                <td class="py-2 pe-3 font-semibold text-ink">{{ $row->source }}</td>
                                <td class="py-2 pe-3 text-right">{{ Money::format($row->spendCents) }}</td>
                                <td class="py-2 pe-3 text-right">{{ $row->leads }}</td>
                                <td class="py-2 pe-3 text-right">{{ MarketingRow::money($row->costPerLeadCents()) }}</td>
                                <td class="py-2 pe-3 text-right">{{ $row->appointments }}</td>
                                <td class="py-2 pe-3 text-right">{{ MarketingRow::money($row->costPerAppointmentCents()) }}</td>
                                <td class="py-2 pe-3 text-right">{{ $row->sales }}</td>
                                <td class="py-2 pe-3 text-right">{{ MarketingRow::money($row->costPerSaleCents()) }}</td>
                                <td class="py-2 pe-3 text-right">{{ Money::format($row->soldCents) }}</td>
                                <td class="py-2 pe-3 text-right">{{ $row->returnWords() ?? '—' }}</td>
                                <td class="py-2 text-right">{{ $percent($row->closeRate()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</section>
