@props([
    'lead',
])

@php
    use App\Support\Appointments\SalesReturn;

    $return = SalesReturn::of($lead);
@endphp

{{-- Tells the call center they are working a Lead that came back from Sales (decision 114). --}}
@if ($return !== null)
    <flux:callout variant="warning" icon="arrow-uturn-left" {{ $attributes->merge(['data-test' => 'sales-return']) }}>
        <flux:callout.heading data-test="sales-return-headline">{{ $return->headline }}</flux:callout.heading>
        <flux:callout.text>
            @if ($return->appointment !== null)
                <span class="block" data-test="sales-return-appointment">{{ $return->appointment }}</span>
            @endif

            <span class="block" data-test="sales-return-when">
                {{ __('Came back') }} <x-local-time :at="$return->at" />@if ($return->returnedBy !== null) &middot; {{ $return->returnedBy }}@endif
            </span>

            {{-- Rehash: what was pitched, so the setter knows the bid before dialling (slice 9, step 9b). --}}
            @if ($return->pitched !== [])
                <span class="mt-1 block font-medium" data-test="sales-return-pitched-heading">{{ __('What was pitched:') }}</span>
                @foreach ($return->pitched as $pitch)
                    <span class="block" data-test="sales-return-pitched">{{ $pitch }}</span>
                @endforeach
            @endif

            @if ($return->noDemoReason !== null)
                <span class="mt-1 block" data-test="sales-return-no-demo">{{ __('Why nothing was presented:') }} &ldquo;{{ $return->noDemoReason }}&rdquo;</span>
            @endif

            @if ($return->note !== null)
                <span class="mt-1 block whitespace-pre-line" data-test="sales-return-note">&ldquo;{{ $return->note }}&rdquo;</span>
            @endif
        </flux:callout.text>
    </flux:callout>
@endif
