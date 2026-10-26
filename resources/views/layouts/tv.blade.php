{{--
    TV Mode (slice 27, step 27c; decision 187, question 8;
    product-architecture.md, 17.2): a board on a shop or office screen.
    Large type, no menus, stronger colors; the page refreshes itself. The
    screen stays signed in as a staff member who may see the board.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-ink text-white antialiased">
        @include('partials.impersonation-banner')

        <main class="px-6 py-6 lg:px-10 lg:py-8">
            {{ $slot }}
        </main>

        @fluxScripts
    </body>
</html>
