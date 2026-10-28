<?php

use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Support\Money;
use App\Support\Pricing\EstimatePricing;
use App\Support\Pricing\PricedEstimate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * An estimate presented to the homeowner (docs/slice-31-estimating-plan.md,
 * step 31d; decision 207), on the salesperson's tablet. The options follow
 * one another down the page, largest first, each with its name, its
 * description, the rooms and areas it covers, its written line items and its
 * price after discounts; buttons at the top jump to each. Hours, rates,
 * paint and costs are never shown.
 *
 * It only shows: opening it never counts the estimate as presented
 * (decision 207, question 3). Anyone who may see the estimate may open it.
 */
new #[Title('Your estimate')] #[Layout('layouts::presentation')] class extends Component {
    #[Locked]
    public int $leadId;

    #[Locked]
    public int $estimateId;

    public function mount(Lead $lead, Estimate $estimate): void
    {
        $this->leadId = $lead->id;
        $this->estimateId = $estimate->id;
    }

    #[Computed]
    public function estimate(): Estimate
    {
        return Estimate::query()->with(['lead', 'discounts'])->where('lead_id', $this->leadId)->findOrFail($this->estimateId);
    }

    /**
     * Each option with its figures, the largest price first; options at the
     * same price keep the order they were made in.
     *
     * @return Collection<int, array{option: EstimateOption, priced: PricedEstimate}>
     */
    #[Computed]
    public function options(): Collection
    {
        $pricing = app(EstimatePricing::class);

        return $this->estimate->options()->with(['areas', 'lineItems'])->get()
            ->map(fn (EstimateOption $option): array => ['option' => $option, 'priced' => $pricing->price($this->estimate, $option)])
            ->sortBy([
                fn (array $a, array $b): int => $b['priced']->totalCents <=> $a['priced']->totalCents,
                fn (array $a, array $b): int => [$a['option']->sort_order, $a['option']->id] <=> [$b['option']->sort_order, $b['option']->id],
            ])
            ->values();
    }
}; ?>

<div class="flex flex-col" data-test="estimate-presentation">
    @php
        $estimate = $this->estimate;
        $options = $this->options;
    @endphp

    {{-- The quick buttons stay at the top while the homeowner scrolls; on a phone they scroll sideways in one row. --}}
    <nav class="sticky top-0 z-10 border-b border-zinc-200 bg-cream/95 backdrop-blur" aria-label="{{ __('Options') }}">
        <div class="mx-auto flex w-full max-w-4xl gap-2 overflow-x-auto px-4 py-3 sm:flex-wrap sm:px-6">
            @foreach ($options as $entry)
                <a
                    href="#option-{{ $entry['option']->id }}"
                    wire:key="jump-{{ $entry['option']->id }}"
                    class="inline-flex min-h-12 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-zinc-300 bg-card px-5 text-base font-medium text-ink hover:border-ink"
                    data-test="jump-{{ $entry['option']->id }}"
                >
                    {{ $entry['option']->name }}
                    <span class="tabular-nums text-zinc-600">{{ Money::format($entry['priced']->totalCents) }}</span>
                </a>
            @endforeach
        </div>
    </nav>

    <main class="mx-auto flex w-full max-w-4xl flex-col gap-8 px-4 py-6 sm:px-6 sm:py-8">
        <header class="flex flex-col items-start gap-3">
            <flux:button
                :href="route('leads.estimate', [$leadId, $estimateId])"
                wire:navigate
                variant="ghost"
                size="sm"
                icon="arrow-left"
                class="-ms-3"
                data-test="back-to-estimate"
            >{{ __('Back to the estimate') }}</flux:button>
            <div class="flex flex-col gap-1">
                <flux:heading size="xl" level="1">{{ __('Your estimate') }}</flux:heading>
                <flux:text class="text-base">
                    {{ $estimate->estimate_type->label() }}@if ($estimate->lead->displayName()) · {{ __('Prepared for :name', ['name' => $estimate->lead->displayName()]) }}@endif
                </flux:text>
            </div>
        </header>

        @foreach ($options as $entry)
            @php
                $option = $entry['option'];
                $priced = $entry['priced'];
            @endphp
            <section
                id="option-{{ $option->id }}"
                wire:key="option-{{ $option->id }}"
                class="flex scroll-mt-24 flex-col gap-5 rounded-2xl border border-zinc-200 bg-card p-6 sm:p-8"
                data-test="option-{{ $option->id }}"
            >
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0 flex-1">
                        <flux:heading size="xl" level="2">{{ $option->name }}</flux:heading>
                        @if ($option->description)
                            <flux:text class="mt-2 whitespace-pre-line text-base">{{ $option->description }}</flux:text>
                        @endif
                    </div>
                    <div class="flex flex-col gap-1 sm:items-end sm:text-right">
                        <span class="text-3xl font-semibold tabular-nums text-ink" data-test="price-{{ $option->id }}">{{ Money::format($priced->totalCents) }}</span>
                        @foreach (array_filter($priced->discounts, fn (array $discount): bool => $discount['cents'] > 0) as $discount)
                            <span class="text-sm text-wall-teal" data-test="saving-{{ $option->id }}">{{ __(':name: :amount off', ['name' => $discount['discount']->name, 'amount' => Money::format($discount['cents'])]) }}</span>
                        @endforeach
                        @if ($priced->taxCents > 0)
                            <span class="text-sm text-zinc-600">{{ __('Tax included') }}</span>
                        @endif
                    </div>
                </div>

                @if ($option->areas->isNotEmpty())
                    <div class="flex flex-col gap-2">
                        <flux:heading level="3">{{ __('Rooms and areas') }}</flux:heading>
                        <ul class="flex flex-wrap gap-2">
                            @foreach ($option->areas as $area)
                                <li wire:key="area-{{ $area->id }}" class="rounded-full bg-zinc-100 px-3 py-1 text-sm text-zinc-800">{{ $area->name }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if ($option->lineItems->isNotEmpty())
                    <div class="flex flex-col gap-3">
                        <flux:heading level="3">{{ __('Details') }}</flux:heading>
                        <ul class="flex flex-col gap-3">
                            @foreach ($option->lineItems as $item)
                                <li wire:key="line-item-{{ $item->id }}">
                                    <span class="font-medium text-ink">{{ $item->name }}</span>
                                    @if ($item->description)
                                        <flux:text class="mt-0.5 whitespace-pre-line">{{ $item->description }}</flux:text>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </section>
        @endforeach
    </main>
</div>
