@props([
    'sizes',
])

{{-- One tap, and required. The page it sits on holds "projectSizeId". --}}
<fieldset class="flex flex-col gap-2" data-test="lead-project-size">
    <legend class="text-sm font-medium text-ink">
        {{ __('Project size') }}
        <span class="font-normal text-zinc-500">&middot; {{ __('required') }}</span>
    </legend>

    <flux:text class="text-sm">{{ __('Your best judgment from the conversation. It is not an estimate.') }}</flux:text>

    <div class="grid gap-2 sm:grid-cols-3">
        @foreach ($sizes as $size)
            <label wire:key="size-{{ $size->id }}" class="cursor-pointer">
                <input type="radio" wire:model="projectSizeId" name="projectSizeId" value="{{ $size->id }}" class="peer sr-only" />
                <span class="flex min-h-14 flex-col justify-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                    <span class="font-nav text-sm font-bold">{{ $size->name }}</span>
                    <span class="text-xs tabular-nums text-zinc-700">{{ $size->rangeLabel() }}</span>
                </span>
            </label>
        @endforeach
    </div>

    <flux:error name="projectSizeId" />
</fieldset>
