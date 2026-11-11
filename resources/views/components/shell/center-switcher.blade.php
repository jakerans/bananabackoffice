@props([
    'centers',
    'externalSurfaces',
    'current' => null,
])

@if (count($centers) + count($externalSurfaces) > 0)
    <flux:dropdown position="bottom" align="start" class="flex min-w-0">
        {{-- On a phone the word "Center" is left off and a long name is shortened, so the top bar fits the screen. --}}
        <flux:button variant="ghost" size="sm" icon:trailing="chevron-down" class="max-w-full [&>span]:min-w-0 [&>span]:truncate" data-test="center-switcher">
            @if ($current !== null && str_ends_with($current->label, ' Center'))
                {{ substr($current->label, 0, -strlen(' Center')) }}<span class="max-sm:hidden">&nbsp;{{ __('Center') }}</span>
            @else
                {{ $current?->label ?? __('Centers') }}
            @endif
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
