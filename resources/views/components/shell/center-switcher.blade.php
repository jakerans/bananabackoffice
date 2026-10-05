@props([
    'centers',
    'current' => null,
])

@if (count($centers) > 0)
    <flux:dropdown position="bottom" align="start">
        <flux:button variant="ghost" size="sm" icon:trailing="chevron-down" data-test="center-switcher">
            {{ $current?->label ?? __('Centers') }}
        </flux:button>

        <flux:navmenu>
            @foreach ($centers as $center)
                <flux:navmenu.item :href="route($center->route)" :icon="$center->icon" wire:navigate>
                    {{ $center->label }}
                </flux:navmenu.item>
            @endforeach
        </flux:navmenu>
    </flux:dropdown>
@endif
