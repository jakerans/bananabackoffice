<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cream text-ink antialiased">
        <div class="mx-auto flex min-h-svh w-full max-w-2xl flex-col gap-6 px-4 py-10 md:py-16">
            <div class="flex justify-center">
                <x-app-logo :href="route('home')" height="h-16" />
            </div>

            <article class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-6 leading-relaxed shadow-xs sm:p-8">
                {{ $slot }}
            </article>
        </div>

        @fluxScripts
    </body>
</html>
