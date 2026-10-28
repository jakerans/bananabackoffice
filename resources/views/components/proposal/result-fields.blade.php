@props([
    'model',
    'errorPrefix' => '',
    'result',
    'values' => [],
    'reasons',
    'objections',
    'price' => null,
    'options' => null,
])

@php
    // What a result needs, for one estimate, or one proposal recorded by hand: used by the Estimates card and by the after-action report.
    // With the estimate's options, a sale asks which was chosen; without, the amount it sold for.
    $reason = $reasons->firstWhere('id', (int) ($values['lossReasonId'] ?? 0));
    $objection = $objections->firstWhere('id', (int) ($values['objectionId'] ?? 0));
    $noteRequired = ($result === 'lost' && (($reason?->requires_note ?? false) || ($objection?->requires_note ?? false)))
        || ($result === 'pending' && ($objection?->requires_note ?? false));

    // A field already shows a refusal filed under its own name, so one is added here only when it is filed under another.
    $own = $errorPrefix === $model.'.';
@endphp

@if ($result === 'sold' && $options !== null)
    {{-- An estimate is sold for the option the customer chose, at its price (step 31e; decision 207). --}}
    <div class="flex flex-col gap-1">
        <flux:radio.group wire:model="{{ $model }}.optionId" :label="__('Which option did the customer choose?')" data-test="result-option">
            @foreach ($options as $option)
                <flux:radio :value="(string) $option->id" :label="$option->name.', '.\App\Support\Money::format($option->total_cents)" wire:key="{{ $model }}-option-{{ $option->id }}" data-test="result-option-{{ $option->id }}" />
            @endforeach
        </flux:radio.group>
        @unless ($own)
            <flux:error :name="$errorPrefix.'optionId'" />
        @endunless
    </div>

    {{-- The Brand Ambassador package, recorded with the sale (slice 21, step 21a). --}}
    <x-proposal.ambassador-fields :model="$model" :errorPrefix="$errorPrefix" :values="$values" />
@elseif ($result === 'sold')
    <div class="flex flex-col gap-1">
        <flux:input wire:model="{{ $model }}.soldAmount" inputmode="decimal" :label="__('Sold for, in dollars')" :placeholder="$price" data-test="result-sold-amount" />
        <flux:text class="text-sm">{{ __('Leave it empty if it sold for the price.') }}</flux:text>
        @unless ($own)
            <flux:error :name="$errorPrefix.'soldAmount'" />
        @endunless
    </div>

    {{-- The Brand Ambassador package, recorded with the sale (slice 21, step 21a). --}}
    <x-proposal.ambassador-fields :model="$model" :errorPrefix="$errorPrefix" :values="$values" />
@endif

@if ($result === 'lost')
    <div class="flex flex-col gap-1">
        <flux:select wire:model.live="{{ $model }}.lossReasonId" :label="__('Why it was lost')" data-test="result-loss-reason">
            <flux:select.option value="">{{ __('Choose a reason') }}</flux:select.option>
            @foreach ($reasons as $option)
                <flux:select.option :value="$option->id" wire:key="{{ $model }}-reason-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @unless ($own)
            <flux:error :name="$errorPrefix.'lossReasonId'" />
        @endunless
    </div>

    @if ($reason?->asks_for_competitor)
        <flux:input wire:model="{{ $model }}.lostTo" :label="__('Which contractor, if known')" data-test="result-lost-to" />
    @endif
@endif

@if ($result === 'lost' || $result === 'pending')
    <div class="flex flex-col gap-1">
        <flux:select wire:model.live="{{ $model }}.objectionId" :label="__('Main objection')" data-test="result-objection">
            <flux:select.option value="">{{ __('Choose an objection') }}</flux:select.option>
            @foreach ($objections as $option)
                <flux:select.option :value="$option->id" wire:key="{{ $model }}-objection-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        @unless ($own)
            <flux:error :name="$errorPrefix.'objectionId'" />
        @endunless
    </div>
@endif

@if ($result !== '')
    <div class="flex flex-col gap-1">
        <flux:textarea wire:model="{{ $model }}.note" rows="2" :label="$noteRequired ? __('Note (required)') : __('Note (optional)')" data-test="result-note" />
        <flux:error :name="$errorPrefix.'resultNote'" />
    </div>
@endif
