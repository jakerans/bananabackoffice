@props([
    'height' => 'h-9',
])

{{-- The supplied Banana Backoffice horizontal logo, used as it is. Its lettering is dark, so it belongs on a light surface. --}}
<a {{ $attributes->class('inline-flex shrink-0 items-center') }}>
    <img
        src="{{ asset('images/backoffice-logo.png') }}"
        alt="{{ config('app.name') }}"
        width="764"
        height="259"
        class="{{ $height }} w-auto"
    />
</a>
