@props([
    'section',
    'shell',
])

{{--
    A section's pages as a menu beside the page, for Settings (decision 202,
    point 3; decision 222): only the pages built and the person may open, so
    nothing waits there marked "coming soon".
--}}
<nav aria-label="{{ $section->label }}" class="w-full shrink-0 lg:sticky lg:top-6 lg:w-56 print:hidden" data-test="side-nav">
    <div class="mb-2 px-3 font-nav text-xs font-bold tracking-wide text-zinc-500 uppercase">{{ $section->label }}</div>
    <ul class="flex gap-1 overflow-x-auto max-lg:pb-2 lg:flex-col">
        @foreach ($section->children as $page)
            @continue(! $page->isReachable() || ! $shell->user()->canAny($page->permissions))

            <li class="shrink-0">
                <a
                    href="{{ route($page->route) }}"
                    wire:navigate
                    @if ($shell->isCurrent($page)) aria-current="page" @endif
                    class="flex h-9 items-center rounded-md px-3 font-nav text-sm whitespace-nowrap {{ $shell->isCurrent($page) ? 'bg-ink font-bold text-white' : 'font-semibold text-zinc-600 hover:bg-zinc-100 hover:text-ink' }}"
                >
                    {{ $page->label }}
                </a>
            </li>
        @endforeach
    </ul>
</nav>
