@props([
    'at',
    'timezone',
])

@php
    // The server renders the Location's own time and the ISO timestamp. The browser adds the
    // device time with Intl.DateTimeFormat; the device timezone is display only and is never
    // sent to the server. Company time elsewhere in the app is not touched (decision 68).
    $local = $at->setTimezone($timezone);
@endphp

<time
    datetime="{{ $at->toIso8601String() }}"
    x-data="{ device: '' }"
    x-init="device = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short', timeZoneName: 'short' }).format(new Date($el.getAttribute('datetime')))"
    data-test="queue-time"
    {{ $attributes }}
><span x-show="device" x-cloak x-text="device" data-test="queue-time-device"></span><span class="text-zinc-500" data-test="queue-time-location"><span x-show="device" x-cloak>&middot; </span>{{ __('Location time') }} {{ $local->format('M j, g:i A T') }}</span></time>
