@props([
    'name',
    'title',
    'description',
    'action',
    'confirmLabel',
    'error' => null,
    'confirmVariant' => 'danger',
])

<flux:modal :name="$name" class="w-full max-w-lg">
    <form wire:submit="{{ $action }}" class="flex flex-col gap-5">
        @foreach ((array) $error as $errorName)
            <flux:error :name="$errorName" />
        @endforeach

        <div>
            <flux:heading size="lg">{{ $title }}</flux:heading>
            <flux:subheading>{{ $description }}</flux:subheading>
        </div>

        {{ $slot }}

        <div class="flex justify-end gap-2">
            <flux:modal.close>
                <flux:button type="button" variant="ghost" autofocus>{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button type="submit" :variant="$confirmVariant" data-test="confirmation-submit">{{ $confirmLabel }}</flux:button>
        </div>
    </form>
</flux:modal>
