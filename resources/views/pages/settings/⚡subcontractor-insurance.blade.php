<?php

use App\Actions\Projects\ChangeInsuranceDeduction;
use App\Models\InsuranceDeductionRate;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Settings > Subcontractor Insurance (decision 224): the company's insurance
 * deduction, taken from a subcontractor's payout when they use Banana's
 * coverage (slice 13, step 13c; decision 151, question 7). One rate for the
 * whole company, effective-dated; moved here from Company > Locations.
 */
new #[Title('Subcontractor Insurance')] class extends Component {
    /** A new insurance deduction rate, as a percent, and the day it takes effect. */
    public string $deductionPercent = '';

    public string $deductionEffectiveOn = '';

    /**
     * Every insurance deduction rate, the latest first: the one in force and
     * any to come.
     *
     * @return Collection<int, InsuranceDeductionRate>
     */
    #[Computed]
    public function deductionRates(): Collection
    {
        return InsuranceDeductionRate::query()->orderByDesc('effective_on')->orderByDesc('id')->get();
    }

    public function saveDeduction(ChangeInsuranceDeduction $changeInsuranceDeduction): void
    {
        $this->resetValidation();

        $changeInsuranceDeduction->handle($this->deductionPercent, $this->deductionEffectiveOn, Auth::user());

        $this->reset('deductionPercent', 'deductionEffectiveOn');
        unset($this->deductionRates);

        Flux::toast(variant: 'success', text: __('Deduction rate saved.'));
    }
}; ?>

<section class="mx-auto flex w-full max-w-4xl flex-col gap-6" data-test="subcontractor-insurance">
    @php
        $inForce = InsuranceDeductionRate::inForceOn(now());
    @endphp

    <div>
        <flux:heading size="xl" level="1">{{ __('Subcontractor Insurance') }}</flux:heading>
        <flux:subheading>{{ __('What a subcontractor pays when they work under Banana\'s insurance instead of their own.') }}</flux:subheading>
    </div>

    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="insurance-deduction">
        <div>
            <flux:heading size="lg" level="2">{{ __('Insurance deduction') }}</flux:heading>
            <flux:subheading>{{ __('Taken from a subcontractor\'s payout when they use Banana\'s coverage instead of their own insurance. One rate for every Location. A work order uses the rate in force on its first day.') }}</flux:subheading>
        </div>

        <flux:text data-test="insurance-deduction-now">{{ $inForce === null ? __('No rate is set.') : __('Now :rate of payout.', ['rate' => $inForce->percent()]) }}</flux:text>

        @if ($this->deductionRates->count() > 1 || $this->deductionRates->contains(fn (InsuranceDeductionRate $rate): bool => $rate->effective_on->isFuture()))
            <ul class="flex flex-col gap-1 text-sm text-zinc-600" data-test="insurance-deduction-history">
                @foreach ($this->deductionRates as $rate)
                    <li wire:key="deduction-rate-{{ $rate->id }}">{{ __(':rate from :day', ['rate' => $rate->percent(), 'day' => $rate->effective_on->format('M j, Y')]) }}@if ($rate->set_by_name !== null) &middot; {{ $rate->set_by_name }}@endif</li>
                @endforeach
            </ul>
        @endif

        <form wire:submit="saveDeduction" class="flex flex-wrap items-end gap-3">
            <flux:input wire:model="deductionPercent" inputmode="decimal" :label="__('New rate (%)')" placeholder="10" class="max-w-32" data-test="deduction-percent" />
            <flux:input wire:model="deductionEffectiveOn" type="date" :label="__('From')" class="max-w-48" data-test="deduction-effective-on" />
            <flux:button type="submit" variant="primary" data-test="save-deduction">{{ __('Save') }}</flux:button>
        </form>
    </div>
</section>
