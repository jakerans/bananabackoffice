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
        <header class="dark bg-ink text-white print:hidden">
            <div class="flex h-14 items-stretch">
                {{-- The logo has dark lettering, so it sits on a Cream block at the start of the Ink header. --}}
                <x-app-logo :href="route('dashboard')" wire:navigate class="bg-cream px-4 lg:px-6" height="h-8 sm:h-9" />

                <div class="flex min-w-0 flex-1 items-center gap-2 px-3 sm:gap-4 lg:px-6">
                    <x-shell.center-switcher
                        :centers="$shell->centers()"
                        :external-surfaces="$shell->externalSurfaces()"
                        :current="$currentDestination"
                    />

                    <div class="flex-1"></div>

                    <x-shell.location-switcher :shell="$shell" />

                    <x-shell.attention-link />

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
