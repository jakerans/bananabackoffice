{{-- The wordmark treatment drawn in the Backoffice design system: a Yellow block reading BANANA. --}}
<a {{ $attributes->class('flex shrink-0 items-center gap-2.5') }}>
    <span class="rounded-sm bg-banana px-2 py-1 font-nav text-sm leading-none font-extrabold tracking-wide text-ink">BANANA</span>
    <span class="font-nav text-xs leading-none font-semibold tracking-[0.2em]">BACKOFFICE</span>
    <span class="sr-only">{{ config('app.name') }}</span>
</a>
