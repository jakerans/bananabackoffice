@props([
    'centers',
    'externalSurfaces',
    'current' => null,
])

@if (count($centers) + count($externalSurfaces) > 0)
    <flux:dropdown position="bottom" align="start">
        {{-- On a phone a long name is cut short rather than pushing past the screen's edge. --}}
        <flux:button variant="ghost" size="sm" icon:trailing="chevron-down" class="max-w-full [&>span]:truncate" data-test="center-switcher">
            {{ $current?->label ?? __('Centers') }}
        </flux:button>

        <flux:navmenu>
            @foreach ($centers as $center)
                <flux:navmenu.item :href="route($center->route)" :icon="$center->icon" wire:navigate>
                    {{ $center->label }}
                </flux:navmenu.item>
            @endforeach

            @if (count($externalSurfaces) > 0)
                <flux:navmenu.separator />

                <div class="px-2 pb-1 pt-2 text-xs font-semibold tracking-wide text-zinc-500 uppercase dark:text-zinc-300">
                    {{ __('External') }}
                </div>

                @foreach ($externalSurfaces as $surface)
                    <flux:navmenu.item :href="route($surface->route)" :icon="$surface->icon" wire:navigate>
                        {{ $surface->label }}
                    </flux:navmenu.item>
                @endforeach
            @endif
        </flux:navmenu>
    </flux:dropdown>
@endif
