@inject('shell', \App\Support\Navigation\Shell::class)

@php
    $context = $shell->locationContext();
    $isCompanyLevel = collect($center->permissions)->every->isCompanyLevel();
@endphp

<x-layouts::app :title="$section->label">
    <div class="mx-auto flex max-w-5xl flex-col gap-6">
        <header class="flex flex-col gap-1">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $section->label }}</flux:heading>
                <flux:badge size="sm" color="zinc" icon="lock-closed">{{ __('Coming soon') }}</flux:badge>
            </div>

            <flux:subheading data-test="section-scope">
                {{ $center->label }}
                &middot;
                @if ($isCompanyLevel)
                    {{ __('Company-wide') }}
                @elseif ($context !== null)
                    {{ __('Working in :location', ['location' => $context->label()]) }}
                @endif
            </flux:subheading>
        </header>

        {{-- An honest empty state: no sample numbers, charts or buttons stand in for the real feature. --}}
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-14 text-center">
            <x-app-logo-icon class="h-12 w-auto" />

            <p class="font-display text-2xl text-ink">{{ __('Not built yet') }}</p>

            <p class="max-w-md text-sm text-zinc-600">
                {{ __(':section is planned but has not been built. Nothing is shown here until it has real information to show.', ['section' => $section->label]) }}
            </p>
        </div>
    </div>
</x-layouts::app>
