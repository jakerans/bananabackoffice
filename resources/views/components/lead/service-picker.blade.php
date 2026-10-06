@props([
    'services',
])

@php
    $pill = 'inline-flex min-h-11 items-center rounded-lg border border-zinc-300 bg-white px-4 font-nav text-sm font-semibold text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal';
    $smallPill = 'inline-flex min-h-9 items-center rounded-lg border border-zinc-300 bg-white px-3 font-nav text-xs font-semibold text-ink peer-checked:border-ink peer-checked:bg-ink peer-checked:text-white peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal';
@endphp

{{--
    Tap every service the inquiry is about; at least one is required. With one chosen it is the
    primary. With several, the person must say which is the main one: it is never picked for them.
    The page it sits on holds "serviceIds" and "primaryServiceId", and the server checks both again.
--}}
<fieldset
    x-data="{
        selected: $wire.entangle('serviceIds'),
        primary: $wire.entangle('primaryServiceId'),
        keepPrimaryValid() {
            if (this.selected.length === 1) {
                this.primary = this.selected[0];
            } else if (! this.selected.includes(this.primary)) {
                this.primary = null;
            }
        },
    }"
    x-init="$watch('selected', () => keepPrimaryValid())"
    class="flex flex-col gap-2"
    data-test="lead-services"
>
    <legend class="text-sm font-medium text-ink">
        {{ __('Service(s)') }}
        <span class="font-normal text-zinc-500">&middot; {{ __('at least one') }}</span>
    </legend>

    <div class="flex flex-wrap gap-2">
        @foreach ($services as $service)
            <label wire:key="service-{{ $service->id }}" class="cursor-pointer">
                <input type="checkbox" value="{{ $service->id }}" x-model="selected" class="peer sr-only" />
                <span class="{{ $pill }}">{{ $service->name }}</span>
            </label>
        @endforeach
    </div>

    <div x-show="selected.length > 1" x-cloak class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-cream p-3">
        <span class="text-sm font-medium text-ink">
            {{ __('Which is the main one?') }}
            <span class="font-normal text-zinc-500">&middot; {{ __('required') }}</span>
        </span>

        <div class="flex flex-wrap gap-2">
            @foreach ($services as $service)
                <label wire:key="primary-service-{{ $service->id }}" x-show="selected.includes('{{ $service->id }}')" class="cursor-pointer">
                    <input type="radio" value="{{ $service->id }}" x-model="primary" class="peer sr-only" />
                    <span class="{{ $smallPill }}">{{ $service->name }}</span>
                </label>
            @endforeach
        </div>
    </div>

    <flux:error name="serviceIds" />
    <flux:error name="primaryServiceId" />
</fieldset>
