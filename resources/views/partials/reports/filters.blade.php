{{-- The frame every report shares (docs/slice-32-reports-plan.md): its days and its Location. Used inside an App\Livewire\ReportPage. --}}
<div class="flex flex-wrap items-end gap-4" data-test="reports-filters">
    <flux:select wire:model.live="range" :label="__('Days')" class="max-w-xs" data-test="reports-range">
        @foreach ($this->rangeOptions() as $value => $label)
            <flux:select.option :value="$value" wire:key="range-{{ $value === '' ? 'default' : $value }}">{{ $label }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->chosenRange === \App\Enums\ReportRange::Custom)
        <flux:input type="date" wire:model.live.blur="from" :label="__('From')" class="max-w-44" data-test="reports-from" />
        <flux:input type="date" wire:model.live.blur="to" :label="__('To')" class="max-w-44" data-test="reports-to" />
    @endif

    @if ($this->locations->count() > 1)
        <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="reports-location">
            <flux:select.option value="">{{ __('All my locations') }}</flux:select.option>
            @foreach ($this->locations as $option)
                <flux:select.option :value="(string) $option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
    @endif
</div>

<flux:text class="text-sm" data-test="reports-scope">{{ $this->scopeWords() }}</flux:text>
