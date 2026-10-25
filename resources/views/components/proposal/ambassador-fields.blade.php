{{--
    The Brand Ambassador package of a sale (slice 21, step 21a; decision
    175), for the Proposals card, each proposal in the after-action report,
    and the job. Bound to the component's `model` array: ambassadorTier, and
    for Custom ambassadorDiscount, ambassadorItems and ambassadorReason.
--}}
@props([
    'model',
    'errorPrefix' => '',
    'values' => [],
])

@use('App\Support\Projects\AmbassadorPackage')

@php
    // A field already shows a refusal filed under its own name, so one is added here only when it is filed under another.
    $own = $errorPrefix === $model.'.';
@endphp

<div class="flex flex-col gap-3" data-test="ambassador-fields">
    <div class="flex flex-col gap-1">
        <flux:select wire:model.live="{{ $model }}.ambassadorTier" :label="__('Brand Ambassador package')" data-test="ambassador-tier">
            @foreach (AmbassadorPackage::TIERS as $tier => $terms)
                <flux:select.option :value="$tier" wire:key="{{ $model }}-ambassador-{{ $tier }}">{{ __($terms['label']) }}</flux:select.option>
            @endforeach
        </flux:select>
        @unless ($own)
            <flux:error :name="$errorPrefix.'ambassadorTier'" />
        @endunless
    </div>

    @if (($values['ambassadorTier'] ?? '') === AmbassadorPackage::CUSTOM)
        <div class="flex flex-col gap-3 rounded-lg bg-zinc-50 p-3">
            <flux:text class="text-sm">{{ __('Only when the package itself is the one thing stopping the sale. The Location\'s managers are told.') }}</flux:text>
            <div class="flex flex-col gap-1">
                <flux:input wire:model="{{ $model }}.ambassadorDiscount" inputmode="numeric" :label="__('Discount, in percent (up to :max)', ['max' => AmbassadorPackage::MAX_DISCOUNT_PERCENT])" class="max-w-48" data-test="ambassador-discount" />
                @unless ($own)
                    <flux:error :name="$errorPrefix.'ambassadorDiscount'" />
                @endunless
            </div>
            <div class="flex flex-col gap-1">
                <flux:checkbox.group wire:model="{{ $model }}.ambassadorItems" :label="__('What the customer promises')" data-test="ambassador-items">
                    @foreach (AmbassadorPackage::ITEMS as $item => $label)
                        <flux:checkbox :value="$item" :label="__($label)" wire:key="{{ $model }}-ambassador-item-{{ $item }}" />
                    @endforeach
                </flux:checkbox.group>
                @unless ($own)
                    <flux:error :name="$errorPrefix.'ambassadorItems'" />
                @endunless
            </div>
            <div class="flex flex-col gap-1">
                <flux:textarea wire:model="{{ $model }}.ambassadorReason" rows="2" :label="__('Why it is custom (required)')" data-test="ambassador-reason" />
                @unless ($own)
                    <flux:error :name="$errorPrefix.'ambassadorReason'" />
                @endunless
            </div>
        </div>
    @endif
</div>
