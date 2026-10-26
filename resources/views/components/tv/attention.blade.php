{{--
    The five most serious Needs Attention items the signed-in person may see,
    for a board in TV Mode (slice 27, step 27c; decision 187, question 8).
--}}
@php
    $user = auth()->user();
    $rows = \App\Support\Attention\AttentionList::rows($user, \App\Support\Attention\AttentionList::widestScopeFor($user))->take(5);
    $colors = [\App\Models\AttentionItem::URGENT => 'bg-rose-600', \App\Models\AttentionItem::ATTENTION => 'bg-amber-500 text-ink', \App\Models\AttentionItem::FYI => 'bg-zinc-600'];
@endphp

<section class="flex flex-col gap-4 rounded-2xl bg-white/5 p-6" data-test="tv-attention">
    <h2 class="font-nav text-2xl font-bold">{{ __('Needs Attention') }}</h2>
    @if ($rows->isEmpty())
        <p class="text-xl text-white/70">{{ __('Nothing needs attention.') }}</p>
    @else
        <ul class="flex flex-col gap-3">
            @foreach ($rows as $row)
                <li class="flex items-start gap-3 text-xl leading-snug">
                    <span class="mt-1 shrink-0 rounded px-2 py-0.5 text-sm font-bold uppercase {{ $colors[$row['item']->severity] ?? 'bg-zinc-600' }}">{{ __($row['item']->severityLabel()) }}</span>
                    <span>{{ $row['item']->title }}</span>
                </li>
            @endforeach
        </ul>
    @endif
</section>
