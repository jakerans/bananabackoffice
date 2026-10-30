{{-- Move a price book list's item one place up or down (slice 35, step 35a). New estimates list them in this order. --}}
<flux:button size="sm" variant="ghost" icon="arrow-up" wire:click="move('{{ $list }}', {{ $item->id }}, 'up')" :disabled="$first" :aria-label="__('Move :name up', ['name' => $item->name])" data-test="move-up-{{ $item->id }}" />
<flux:button size="sm" variant="ghost" icon="arrow-down" wire:click="move('{{ $list }}', {{ $item->id }}, 'down')" :disabled="$last" :aria-label="__('Move :name down', ['name' => $item->name])" data-test="move-down-{{ $item->id }}" />
