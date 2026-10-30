{{-- One paragraph or list of the agreement's wording, escaped. --}}
@if ($block['type'] === 'list')
    @if ($block['ordered'])
        <ol class="ms-6 list-decimal space-y-1 text-sm leading-relaxed text-zinc-800">
            @foreach ($block['items'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ol>
    @else
        <ul class="ms-6 list-disc space-y-1 text-sm leading-relaxed text-zinc-800">
            @foreach ($block['items'] as $item)
                <li>{{ $item }}</li>
            @endforeach
        </ul>
    @endif
@else
    <p class="max-w-prose text-sm leading-relaxed text-zinc-800">{{ $block['text'] }}</p>
@endif
