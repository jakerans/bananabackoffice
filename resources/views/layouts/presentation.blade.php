{{--
    The presentation (docs/slice-31-estimating-plan.md, step 31d; decision
    207): an estimate shown to the homeowner on the salesperson's tablet. No
    menus, so the screen can be turned toward the customer; the page itself
    has the only way back.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-cream text-ink antialiased">
        @include('partials.impersonation-banner')

        {{ $slot }}

        @fluxScripts
    </body>
</html>
