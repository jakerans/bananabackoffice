@inject('shell', \App\Support\Navigation\Shell::class)

@php
    $currentCenter = $shell->currentCenter();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cream text-ink antialiased">
        {{-- The Ink header is the only dark region, so Flux renders its controls for a dark surface here. --}}
        <header class="dark bg-ink text-white">
            <div class="flex h-14 items-center gap-2 px-4 sm:gap-4 lg:px-8">
                <x-app-logo :href="route('dashboard')" wire:navigate />

                <x-shell.center-switcher :centers="$shell->centers()" :current="$currentCenter" />

                <div class="flex-1"></div>

                <x-shell.location-switcher :shell="$shell" />

                <x-desktop-user-menu />
            </div>
        </header>

        @if ($currentCenter)
            <x-shell.center-nav :center="$currentCenter" :shell="$shell" />
        @endif

        <main class="px-4 py-6 lg:px-8 lg:py-8">
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
