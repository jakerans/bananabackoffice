@props([
    'mode',
    'suggestions' => [],
    'selected' => null,
    'available' => false,
    'failed' => false,
    'subject' => 'lead',
])

{{--
    The property address: looked up, or typed by hand. The page it sits on uses the
    CapturesPropertyAddress concern, which holds the state these controls change.
    Typing by hand is always possible, so an address never stops anything being saved.
--}}
<fieldset class="flex flex-col gap-3" data-test="lead-address">
    <legend class="text-sm font-medium text-ink">{{ __('Property address') }}</legend>

    @if ($mode === 'selected' && is_array($selected))
        <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-300 bg-white p-4" data-test="address-selected">
            <div>
                <div class="font-medium text-ink">{{ $selected['formatted'] ?? '' }}</div>
                <div class="mt-1 text-xs text-zinc-500">{{ __('Found by address lookup') }} &middot; &copy; Mapbox</div>
            </div>

            <flux:button size="sm" type="button" wire:click="searchForAddress">{{ __('Change') }}</flux:button>
        </div>
    @elseif ($mode === 'manual')
        @if ($failed)
            <flux:text class="text-sm text-amber-700" data-test="address-lookup-failed">
                {{ $subject === 'property' ? __('That address could not be confirmed, so type it here.') : __('That address could not be confirmed, so type it here. The lead can still be saved.') }}
            </flux:text>
        @elseif (! $available)
            <flux:text class="text-sm">{{ $subject === 'property' ? __('Type the address. The street is needed; the rest can be added later.') : __('Type the address as they gave it. Any part can be left empty.') }}</flux:text>
        @endif

        <flux:input wire:model="addressLine1" :label="__('Street address')" type="text" autocomplete="off" />
        <flux:input wire:model="addressLine2" :label="__('Unit, suite or lot')" type="text" autocomplete="off" />

        <div class="grid gap-3 sm:grid-cols-6">
            <div class="sm:col-span-3">
                <flux:input wire:model="addressCity" :label="__('City')" type="text" autocomplete="off" />
            </div>
            <div class="sm:col-span-1">
                <flux:input wire:model="addressState" :label="__('State')" type="text" autocomplete="off" />
            </div>
            <div class="sm:col-span-2">
                <flux:input wire:model.blur="addressPostalCode" :label="__('ZIP code')" type="text" inputmode="numeric" autocomplete="off" />
            </div>
        </div>

        @if ($available)
            <div>
                <flux:button size="sm" variant="ghost" type="button" wire:click="searchForAddress">{{ __('Look the address up instead') }}</flux:button>
            </div>
        @endif
    @else
        <flux:input
            wire:model.live.debounce.400ms="addressSearch"
            type="search"
            icon="map-pin"
            :placeholder="__('Start typing the street address')"
            autocomplete="off"
            aria-label="{{ __('Property address') }}"
            data-test="address-search"
        />

        @if (count($suggestions) > 0)
            <ul class="overflow-hidden rounded-lg border border-zinc-300 bg-white" data-test="address-suggestions">
                @foreach ($suggestions as $index => $suggestion)
                    <li wire:key="address-suggestion-{{ $index }}" class="border-b border-zinc-200 last:border-0">
                        <button
                            type="button"
                            wire:click="selectAddress({{ $index }})"
                            class="flex min-h-11 w-full items-center px-4 py-2 text-left text-sm text-ink hover:bg-cream focus-visible:bg-cream focus-visible:outline-none"
                        >
                            {{ $suggestion['label'] }}
                        </button>
                    </li>
                @endforeach
                <li class="px-4 py-1 text-xs text-zinc-500">&copy; Mapbox</li>
            </ul>
        @endif

        <div>
            <flux:button size="sm" variant="ghost" type="button" wire:click="enterAddressManually" data-test="address-manual">
                {{ __('Can\'t find it? Type the address by hand') }}
            </flux:button>
        </div>
    @endif

    <flux:error name="addressLine1" />
</fieldset>
