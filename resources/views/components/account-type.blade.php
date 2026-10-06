@props([
    'type',
])

@php
    use App\Enums\AccountType;

    $color = match ($type) {
        AccountType::Residential => 'zinc',
        AccountType::Commercial => 'sky',
    };
@endphp

{{-- The word carries the meaning; the color only reinforces it. --}}
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $type->label() }}</flux:badge>
