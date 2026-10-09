{{--
    The contact-attempt dialog, once, for the Lead page and the queue card.
    Its fields and rules live in App\Livewire\Concerns\RecordsContactAttempts.
    On the card ($phoneCallOnly) the channel and direction are fixed to an outbound phone call.
--}}
@php
    use App\Enums\ContactAttemptChannel;
    use App\Enums\ContactAttemptDirection;
    use App\Enums\ContactAttemptDisposition;
@endphp

<flux:modal name="record-contact-attempt" class="w-full max-w-xl">
    <form wire:submit="recordContactAttempt" class="flex flex-col gap-5">
        <div>
            <flux:heading size="lg">{{ __('Record contact attempt') }}</flux:heading>
            <flux:subheading>
                @if ($phoneCallOnly)
                    {{ __('This is a manual factual record of your phone call, not provider verification. It will not change status, assignment or follow-up; it uses up this attempt and schedules the next one.') }}
                @else
                    {{ __('This is a manual factual record, not provider verification. It will not change status, assignment or follow-up.') }}
                @endif
            </flux:subheading>
        </div>

        <flux:input wire:model="attemptOccurredAt" type="datetime-local" :label="__('When it actually happened')" />

        @if ($phoneCallOnly)
            <flux:text data-test="attempt-fixed-channel">{{ __('Outbound phone call') }}</flux:text>
        @else
            <div class="grid gap-5 sm:grid-cols-2">
                <flux:select wire:model.live="attemptChannel" :label="__('Channel')">
                    @foreach (ContactAttemptChannel::cases() as $channel)
                        <flux:select.option :value="$channel->value">{{ $channel->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="attemptDirection" :label="__('Direction')">
                    @foreach (ContactAttemptDirection::cases() as $direction)
                        <flux:select.option :value="$direction->value">{{ $direction->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="grid gap-5 sm:grid-cols-2">
            <flux:input wire:model="attemptNumber" type="tel" inputmode="tel" autocomplete="off" :label="__('Number used, when applicable')" />

            @if ($attemptChannel === ContactAttemptChannel::Phone->value)
                <flux:input wire:model="attemptDurationSeconds" type="number" min="0" max="86400" inputmode="numeric" :label="__('Call duration in seconds')" />
            @endif
        </div>

        <flux:select wire:model.live="attemptDisposition" :label="__('Disposition')">
            @foreach ((ContactAttemptChannel::tryFrom($attemptChannel)?->dispositions() ?? []) as $disposition)
                <flux:select.option :value="$disposition->value">{{ $disposition->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($attemptDisposition === ContactAttemptDisposition::Other->value)
            <fieldset class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-4">
                <legend class="px-1 text-sm font-medium text-ink">{{ __('What factually happened') }}</legend>
                <flux:checkbox wire:model="attemptAnswered" :label="__('The attempt was answered or received a response')" />
                <flux:checkbox wire:model="attemptConnected" :label="__('There was a real two-way connection')" />
            </fieldset>
        @else
            @php($selectedDisposition = ContactAttemptDisposition::tryFrom($attemptDisposition))
            <flux:text class="text-sm">
                {{ __('Answered: :answered · Real two-way connection: :connected', [
                    'answered' => $selectedDisposition?->answered() ? __('Yes') : __('No'),
                    'connected' => $selectedDisposition?->connected() ? __('Yes') : __('No'),
                ]) }}
            </flux:text>
        @endif

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button type="submit" variant="primary" data-test="save-contact-attempt">{{ __('Record attempt') }}</flux:button>
        </div>
    </form>
</flux:modal>
