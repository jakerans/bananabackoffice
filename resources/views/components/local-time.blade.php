@props([
    'at',
    'relative' => false,
])

@php
    // Times are stored in UTC and shown in the company's timezone.
    $local = $at->setTimezone(config('app.display_timezone'));
    $full = $local->format('M j, Y g:i A T');
@endphp

<time datetime="{{ $at->toIso8601String() }}" title="{{ $full }}" {{ $attributes }}>{{ $relative ? $at->diffForHumans() : $full }}</time>
