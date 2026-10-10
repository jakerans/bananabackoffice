@props([
    'lead',
    'goesBack' => false,
])

@php
    use App\Actions\Appointments\StopCountingLeadAsIssued;
@endphp

{{--
    Asked when a Lead that is with Sales is moved, returned to central or
    disqualified (decision 110): does it still count as issued? Only a manager
    may say it should never have been booked. The page holds the answer in
    its `issuedCount` property.
--}}
@if ($lead->isWithSales())
    <div class="flex flex-col gap-3" data-test="leaves-sales">
        @if ($goesBack)
            <flux:callout icon="information-circle">
                <flux:callout.text>{{ __('This lead is with Sales. Moving it takes it from its salesperson and sends it back to the Contact Center: it shows Qualified again and goes back in the call queue.') }}</flux:callout.text>
            </flux:callout>
        @endif

        @can('manageAppointment', $lead)
            <flux:select wire:model="issuedCount" :label="__('Should this lead ever have been booked?')" data-test="issued-count">
                <flux:select.option value="">{{ __('Yes. It still counts as issued') }}</flux:select.option>
                <flux:select.option :value="StopCountingLeadAsIssued::NEVER_SHOULD_HAVE_BEEN_BOOKED">{{ __('No. It should never have been booked. Do not count it as issued') }}</flux:select.option>
            </flux:select>
        @else
            <flux:text class="text-sm" data-test="issued-count-stays">{{ __('This lead still counts as issued. Only a manager can say it should never have been booked.') }}</flux:text>
        @endcan
    </div>
@endif
