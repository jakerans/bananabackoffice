@props([
    'status',
])

@php
    use App\Enums\LeadStatus;

    $color = match ($status) {
        LeadStatus::New => 'amber',
        LeadStatus::Working => 'sky',
        LeadStatus::Qualified => 'green',
        LeadStatus::Nurture => 'purple',
        LeadStatus::Disqualified => 'zinc',
    };
@endphp

{{-- The word carries the meaning; the color only reinforces it. --}}
<flux:badge size="sm" :color="$color" {{ $attributes }}>{{ $status->label() }}</flux:badge>
