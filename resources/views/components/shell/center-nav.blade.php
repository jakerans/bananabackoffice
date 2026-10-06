@props([
    'center',
    'shell',
])

@php
    $itemClasses = 'flex h-11 shrink-0 items-center border-b-2 px-3 font-nav text-sm whitespace-nowrap';
    $currentClasses = 'border-banana font-bold text-ink';
    $idleClasses = 'border-transparent font-semibold text-zinc-600 hover:text-ink';
    $unbuiltClasses = 'cursor-not-allowed border-transparent font-medium text-zinc-400';
@endphp

{{-- The sections of the current center, and nothing else. --}}
<nav aria-label="{{ $center->label }}" class="border-b border-zinc-200 bg-card">
    <div class="flex items-center gap-1 overflow-x-auto px-4 lg:px-8">
        @foreach ($center->children as $section)
            @continue(! $shell->user()->canAny($section->permissions))

            @php
                // A section with pages of its own opens on the first of them the person may open.
                $landing = $shell->landingFor($section);
                $isCurrent = $shell->isWithin($section);
            @endphp

            @if ($landing)
                <a
                    href="{{ route($landing->route) }}"
                    wire:navigate
                    @if ($isCurrent) aria-current="page" @endif
                    class="{{ $itemClasses }} {{ $isCurrent ? $currentClasses : $idleClasses }}"
                >
                    {{ $section->label }}
                </a>
            @else
                {{-- Authorized but not built yet: visible, muted and never a link. --}}
                <flux:tooltip :content="__('Coming soon')" position="bottom">
                    <span aria-disabled="true" class="{{ $itemClasses }} {{ $unbuiltClasses }}">
                        {{ $section->label }}
                        <span class="sr-only">({{ __('coming soon') }})</span>
                    </span>
                </flux:tooltip>
            @endif
        @endforeach
    </div>
</nav>
