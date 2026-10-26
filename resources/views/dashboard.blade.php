@inject('shell', \App\Support\Navigation\Shell::class)

@php
    $centers = $shell->centers();
    $externalSurfaces = $shell->externalSurfaces();
    $context = $shell->locationContext();
@endphp

<x-layouts::app :title="__('Home')">
    <div class="mx-auto flex max-w-5xl flex-col gap-8">
        <header class="flex flex-col gap-2">
            <h1 class="font-display text-4xl leading-tight text-ink">{{ __('Welcome back') }}</h1>

            <p class="text-base text-zinc-600">
                {{ auth()->user()->name }}
                @if ($context !== null)
                    <span class="text-zinc-400">&middot;</span>
                    {{ __('Working in :location', ['location' => $context->label()]) }}
                @endif
            </p>
        </header>

        @if ($context === null)
            @can(\App\Enums\Permission::ManageLocations)
                <flux:callout icon="map-pin" color="yellow" data-test="no-locations">
                    <flux:callout.heading>{{ __('Add your first location') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('Marketing, Sales, Production, Finance and Color Studio open once at least one operating location exists.') }}
                    </flux:callout.text>
                    <x-slot name="actions">
                        <flux:button :href="route('settings.locations')" variant="primary" size="sm" wire:navigate>
                            {{ __('Add a location') }}
                        </flux:button>
                    </x-slot>
                </flux:callout>
            @endcan
        @endif

        @if (count($centers) + count($externalSurfaces) > 0)
            {{-- The morning briefing (slice 26, step 26c), then Needs Attention (step 26b): the person's own items, then a manager's Locations. --}}
            <x-attention.briefing />

            <livewire:pages::attention.list scope="mine" />

            @can(\App\Enums\Permission::CompanyOverviewAccess)
                <flux:link :href="route('company.needs_attention')" wire:navigate class="-mt-4 text-sm" data-test="attention-company-link">{{ __('See everything that needs attention, company-wide') }}</flux:link>
            @else
                @if (auth()->user()->locationsWhere(\App\Enums\Permission::ManageProjects)->isNotEmpty())
                    <livewire:pages::attention.list scope="locations" />
                @endif
            @endcan
        @endif

        @if (count($centers) + count($externalSurfaces) > 0)
            @if (count($centers) > 0)
                <section class="flex flex-col gap-3" aria-labelledby="centers-heading">
                    <h2 id="centers-heading" class="font-nav text-sm font-bold tracking-wide text-zinc-500 uppercase">
                        {{ __('Centers') }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($centers as $center)
                            <a
                                href="{{ route($center->route) }}"
                                wire:navigate
                                class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5 shadow-xs hover:border-zinc-300"
                            >
                                <span class="flex items-center gap-2 font-nav text-base font-bold text-ink">
                                    <flux:icon :icon="$center->icon" variant="mini" class="text-wall-teal" />
                                    {{ $center->label }}
                                </span>
                                <span class="text-sm text-zinc-600">{{ $center->description }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif

            @if (count($externalSurfaces) > 0)
                <section class="flex flex-col gap-3" aria-labelledby="external-heading">
                    <h2 id="external-heading" class="font-nav text-sm font-bold tracking-wide text-zinc-500 uppercase">
                        {{ __('External') }}
                    </h2>

                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($externalSurfaces as $surface)
                            <a
                                href="{{ route($surface->route) }}"
                                wire:navigate
                                class="flex flex-col gap-2 rounded-xl border border-zinc-200 bg-card p-5 shadow-xs hover:border-zinc-300"
                            >
                                <span class="flex items-center gap-2 font-nav text-base font-bold text-ink">
                                    <flux:icon :icon="$surface->icon" variant="mini" class="text-wall-teal" />
                                    {{ $surface->label }}
                                </span>
                                <span class="text-sm text-zinc-600">{{ $surface->description }}</span>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endif
        @else
            {{-- Signed in, but nothing has been granted. This is not an error and is not "coming soon". --}}
            <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="no-access">
                <x-app-logo-icon class="h-12 w-auto" />
                <h2 class="font-nav text-lg font-bold text-ink">{{ __('No access assigned yet') }}</h2>
                <p class="max-w-md text-sm text-zinc-600">
                    {{ __('Your account is active, but it has not been given access to any center or location. Please ask an administrator to set up your access.') }}
                </p>
            </div>
        @endif
    </div>
</x-layouts::app>
