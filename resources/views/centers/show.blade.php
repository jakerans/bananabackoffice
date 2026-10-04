@inject('shell', \App\Support\Navigation\Shell::class)

@php
    $context = $shell->locationContext();
    $isCompanyLevel = collect($center->permissions)->every->isCompanyLevel();
    $live = array_filter($center->liveChildren(), fn ($item) => auth()->user()->canAny($item->permissions));
    $comingSoon = $center->comingSoonChildren();
@endphp

<x-layouts::app :title="$center->label">
    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            {{-- A center landing page is one of the few places the design system allows the display typeface. --}}
            <h1 class="font-display text-4xl leading-tight text-ink">{{ $center->label }}</h1>

            <p class="text-base text-zinc-600">{{ $center->description }}</p>

            <p class="text-sm text-zinc-500" data-test="center-scope">
                @if ($isCompanyLevel)
                    {{ __('Company-wide') }}
                @elseif ($context !== null)
                    {{ __('Working in :location', ['location' => $context->label()]) }}
                @endif
            </p>
        </header>

        <section aria-labelledby="available-now" class="flex flex-col gap-3">
            <h2 id="available-now" class="font-nav text-sm font-semibold tracking-wide text-zinc-700 uppercase">
                {{ __('Available now') }}
            </h2>

            @if (count($live) > 0)
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($live as $item)
                        <a
                            href="{{ route($item->route) }}"
                            wire:navigate
                            class="flex items-center justify-between rounded-lg border border-zinc-200 bg-card px-4 py-3 font-nav text-sm font-semibold text-ink shadow-xs hover:border-zinc-300"
                        >
                            {{ $item->label }}
                            <flux:icon.arrow-right variant="micro" class="text-zinc-400" />
                        </a>
                    @endforeach
                </div>
            @else
                <div class="rounded-lg border border-dashed border-zinc-300 bg-card px-4 py-6 text-sm text-zinc-600">
                    {{ __('Nothing in this center is switched on yet. The sections below are planned and will appear here as they are built.') }}
                </div>
            @endif
        </section>

        @if (count($comingSoon) > 0)
            <section aria-labelledby="coming-soon" class="flex flex-col gap-3">
                <h2 id="coming-soon" class="font-nav text-sm font-semibold tracking-wide text-zinc-700 uppercase">
                    {{ __('Coming soon') }}
                </h2>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($comingSoon as $item)
                        <div class="flex flex-col gap-2 rounded-lg border border-zinc-200 bg-card/60 px-4 py-3">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-nav text-sm font-semibold text-zinc-500">{{ $item->label }}</span>
                                <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Coming soon') }}</flux:badge>
                            </div>

                            @if (count($item->children) > 0)
                                <ul class="flex flex-col gap-0.5 text-sm text-zinc-400">
                                    @foreach ($item->children as $child)
                                        <li>{{ $child->label }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif
    </div>
</x-layouts::app>
