<?php

use App\Actions\Estimates\StartEstimate;
use App\Models\Estimate;
use App\Models\EstimateOption;
use App\Models\Lead;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The Estimates card on a Lead (docs/slice-31-estimating-plan.md, step 31c):
 * the Lead's estimates with each option's price, and "New estimate",
 * Interior or Exterior. Anyone who may see the Lead sees it; the salesperson
 * the Lead is with, or a manager, starts one.
 */
new class extends Component {
    #[Locked]
    public int $leadId;

    public string $estimateType = '';

    public function mount(Lead $lead): void
    {
        $this->leadId = $lead->id;
    }

    #[Computed]
    public function lead(): Lead
    {
        return Lead::query()->with('location')->findOrFail($this->leadId);
    }

    /**
     * @return Collection<int, Estimate>
     */
    #[Computed]
    public function estimates(): Collection
    {
        return $this->lead->estimates()->with('options')->get();
    }

    public function start(StartEstimate $startEstimate): void
    {
        $estimate = $startEstimate->handle($this->lead, $this->estimateType, Auth::user());

        $this->redirectRoute('leads.estimate', ['lead' => $this->leadId, 'estimate' => $estimate->id], navigate: true);
    }
}; ?>

<div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="estimates">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading size="lg" level="2">{{ __('Estimates') }}</flux:heading>

        @can('create', [Estimate::class, $this->lead])
            <form wire:submit="start" class="flex flex-wrap items-end gap-2">
                <flux:select wire:model="estimateType" :placeholder="__('Interior or Exterior')" size="sm" class="min-w-44" data-test="estimate-type">
                    @foreach (Estimate::offeredTypes() as $type)
                        <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" size="sm" variant="primary" icon="plus" data-test="start-estimate">{{ __('New estimate') }}</flux:button>
            </form>
        @endcan
    </div>

    <flux:error name="estimateType" />

    @forelse ($this->estimates as $estimate)
        <a href="{{ route('leads.estimate', ['lead' => $leadId, 'estimate' => $estimate->id]) }}" wire:navigate wire:key="estimate-{{ $estimate->id }}" class="flex flex-col gap-2 rounded-lg border border-zinc-200 p-4 hover:bg-zinc-50" data-test="estimate-{{ $estimate->id }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-medium text-ink">{{ $estimate->estimate_type->label() }}</span>
                <span class="text-sm text-zinc-500">{{ __('Started :date by :name', ['date' => $estimate->created_at?->timezone(config('app.display_timezone'))->format('M j, Y'), 'name' => $estimate->created_by_name]) }}</span>
            </div>
            <ul class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-zinc-600">
                @foreach ($estimate->options as $option)
                    <li wire:key="estimate-option-{{ $option->id }}">{{ $option->name }} · <span class="tabular-nums">{{ Money::format($option->total_cents) }}</span></li>
                @endforeach
            </ul>
        </a>
    @empty
        <flux:text class="text-sm">{{ __('No estimates yet.') }}</flux:text>
    @endforelse
</div>
