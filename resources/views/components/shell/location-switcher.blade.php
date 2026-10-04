@props([
    'shell',
])

@php
    $context = $shell->locationContext();
@endphp

@if ($context !== null)
    @if ($shell->canSwitchLocation())
        <flux:dropdown position="bottom" align="end">
            <flux:button variant="ghost" size="sm" icon="map-pin" icon:trailing="chevron-down" data-test="location-switcher">
                {{ $context->label() }}
            </flux:button>

            {{-- Only locations the staff member is authorized for are listed, and the server re-checks the choice. --}}
            <flux:menu>
                @foreach ($shell->locationOptions() as $location)
                    <form method="POST" action="{{ route('location.switch') }}" class="w-full" wire:key="location-{{ $location->id }}">
                        @csrf
                        <input type="hidden" name="location" value="{{ $location->id }}" />

                        <flux:menu.item
                            as="button"
                            type="submit"
                            class="w-full cursor-pointer"
                            :icon="$context->is($location) ? 'check' : 'map-pin'"
                            :aria-current="$context->is($location) ? 'true' : null"
                        >
                            {{ $location->name }}
                        </flux:menu.item>
                    </form>
                @endforeach

                @if ($shell->canViewAllLocations())
                    <flux:menu.separator />

                    <form method="POST" action="{{ route('location.switch') }}" class="w-full">
                        @csrf
                        <input type="hidden" name="location" value="{{ \App\Support\Access\CurrentLocation::ALL_LOCATIONS }}" />

                        <flux:menu.item
                            as="button"
                            type="submit"
                            class="w-full cursor-pointer"
                            :icon="$context->isAllLocations ? 'check' : 'globe-americas'"
                            :aria-current="$context->isAllLocations ? 'true' : null"
                        >
                            {{ __('All Locations') }}
                        </flux:menu.item>
                    </form>
                @endif
            </flux:menu>
        </flux:dropdown>
    @else
        <span class="flex items-center gap-1.5 px-2 font-nav text-sm font-medium text-white/80" data-test="location-label">
            <flux:icon.map-pin variant="micro" />
            {{ $context->label() }}
        </span>
    @endif
@endif
