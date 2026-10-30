{{-- One paragraph or list of the agreement's wording in the customer's email copy, escaped. Its type size and colour come from the cell around it, to keep the email small. --}}
@if ($block['type'] === 'list')
<{{ $block['ordered'] ? 'ol' : 'ul' }} style="margin:0 0 10px;padding-left:22px;">
@foreach ($block['items'] as $item)
<li style="margin:0 0 4px;">{{ $item }}</li>
@endforeach
</{{ $block['ordered'] ? 'ol' : 'ul' }}>
@else
<p style="margin:0 0 10px;">{{ $block['text'] }}</p>
@endif
