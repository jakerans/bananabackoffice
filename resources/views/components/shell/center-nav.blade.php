@props([
    'center',
    'shell',
])

@php
    $linkClasses = 'flex h-11 shrink-0 items-center border-b-2 px-3 font-nav text-sm whitespace-nowrap';
    $currentClasses = 'border-banana font-bold text-ink';
    $idleClasses = 'border-transparent font-semibold text-zinc-600 hover:text-ink';

    $onLanding = request()->routeIs($center->route);
    $comingSoon = $center->comingSoonChildren();
@endphp

<nav aria-label="{{ $center->label }}" class="border-b border-zinc-200 bg-card">
    <div class="flex items-center gap-1 overflow-x-auto px-4 lg:px-8">
        <a
            href="{{ route($center->route) }}"
            wire:navigate
            @if ($onLanding) aria-current="page" @endif
            class="{{ $linkClasses }} {{ $onLanding ? $currentClasses : $idleClasses }}"
        >
            {{ __('Overview') }}
        </a>

        @foreach ($center->liveChildren() as $item)
            @if ($shell->user()->canAny($item->permissions))
                <a
                    href="{{ route($item->route) }}"
                    wire:navigate
                    @if ($shell->isCurrent($item)) aria-current="page" @endif
                    class="{{ $linkClasses }} {{ $shell->isCurrent($item) ? $currentClasses : $idleClasses }}"
                >
                    {{ $item->label }}
                </a>
            @endif
        @endforeach

        {{-- Not built yet: shown muted, labelled once, and never a link. --}}
        @if (count($comingSoon) > 0)
            <span class="ms-3 flex h-11 shrink-0 items-center gap-1.5 border-s border-zinc-200 ps-4 text-xs font-semibold tracking-wide whitespace-nowrap text-zinc-500 uppercase">
                <flux:icon.lock-closed variant="micro" />
                {{ __('Coming soon') }}
            </span>

            @foreach ($comingSoon as $item)
                <span aria-disabled="true" class="flex h-11 shrink-0 cursor-default items-center px-2.5 font-nav text-sm font-medium whitespace-nowrap text-zinc-400">
                    {{ $item->label }}
                </span>
            @endforeach
        @endif
    </div>
</nav>
