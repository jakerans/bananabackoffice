@inject('shell', \App\Support\Navigation\Shell::class)

@php
    $currentDestination = $shell->currentDestination();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cream text-ink antialiased">
        @include('partials.impersonation-banner')

        {{-- The Ink header is the only dark region, so Flux renders its controls for a dark surface here. --}}
        {{-- The menus are left off a printed page, such as a work order (slice 13, step 13c). --}}
        {{-- On a phone the center and location switchers move to a second row, so nothing passes the screen's edge. --}}
        {{-- That row is left out when the person has neither switcher. --}}
        <header class="dark bg-ink text-white print:hidden">
            <div class="flex flex-wrap items-stretch sm:h-14 sm:flex-nowrap">
                {{-- The logo has dark lettering, so it sits on a Cream block at the start of the Ink header. --}}
                <x-app-logo :href="route('dashboard')" wire:navigate class="h-14 bg-cream px-4 sm:h-auto lg:px-6" height="h-8 sm:h-9" />

                <div class="order-last flex w-full min-w-0 items-center justify-between gap-2 border-t border-white/10 px-4 py-2 *:min-w-0 max-sm:not-has-[[data-flux-dropdown],[data-test=location-label]]:hidden sm:order-none sm:w-auto sm:flex-1 sm:justify-start sm:gap-4 sm:border-0 sm:py-0 sm:pr-0 sm:pl-3 lg:pl-6">
                    <x-shell.center-switcher
                        :centers="$shell->centers()"
                        :external-surfaces="$shell->externalSurfaces()"
                        :current="$currentDestination"
                    />

                    <div class="hidden flex-1 sm:block"></div>

                    <x-shell.location-switcher :shell="$shell" />
                </div>

                <div class="ml-auto flex items-center gap-2 pr-4 sm:ml-0 sm:gap-4 sm:pr-3 sm:pl-4 lg:pr-6">
                    <livewire:pages::notifications.bell />

                    <x-desktop-user-menu />
                </div>
            </div>
        </header>

        @if ($currentDestination)
            <div class="print:hidden">
                <x-shell.center-nav :center="$currentDestination" :shell="$shell" />

                @if ($currentSection = $shell->currentSection($currentDestination))
                    <x-shell.section-nav :section="$currentSection" :shell="$shell" />
                @endif
            </div>
        @endif

        <livewire:pages::sales.close-out-prompt />
        <livewire:pages::sales.handoff-prompt />

        <main class="px-4 py-6 lg:px-8 lg:py-8 print:p-0">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
