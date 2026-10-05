<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cream text-ink antialiased">
        @include('partials.impersonation-banner')

        <div class="flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-sm flex-col gap-6">
                <div class="flex justify-center">
                    <x-app-logo :href="route('home')" height="h-20" />
                </div>

                <div class="flex flex-col gap-6 rounded-xl border border-zinc-200 bg-card p-6 shadow-xs sm:p-8">
                    {{ $slot }}
                </div>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
