@props([
    'section',
    'shell',
])

@php
    $itemClasses = 'flex h-9 shrink-0 items-center rounded-md px-3 font-nav text-xs whitespace-nowrap';
    $currentClasses = 'bg-ink font-bold text-white';
    $idleClasses = 'font-semibold text-zinc-600 hover:bg-zinc-100 hover:text-ink';
    $unbuiltClasses = 'cursor-not-allowed font-medium text-zinc-400';
@endphp

{{-- The pages inside the current section. Shown only for a section that has them. --}}
<nav aria-label="{{ $section->label }}" class="border-b border-zinc-200 bg-cream">
    <div class="flex items-center gap-1 overflow-x-auto px-4 py-1.5 lg:px-8">
        @foreach ($section->children as $page)
            @continue(! $shell->user()->canAny($page->permissions))

            @if ($page->isReachable())
                <a
                    href="{{ route($page->route) }}"
                    wire:navigate
                    @if ($shell->isCurrent($page)) aria-current="page" @endif
                    class="{{ $itemClasses }} {{ $shell->isCurrent($page) ? $currentClasses : $idleClasses }}"
                >
                    {{ $page->label }}
                </a>
            @else
                <flux:tooltip :content="__('Coming soon')" position="bottom">
                    <span aria-disabled="true" class="{{ $itemClasses }} {{ $unbuiltClasses }}">
                        {{ $page->label }}
                        <span class="sr-only">({{ __('coming soon') }})</span>
                    </span>
                </flux:tooltip>
            @endif
        @endforeach
    </div>
</nav>
