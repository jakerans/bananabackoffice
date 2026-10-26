{{--
    The morning briefing on Home (slice 26, step 26c; decision 185, question
    7): a short summary for the signed-in person's role, counting from this
    morning. Worked out each time Home opens; nothing is emailed.
--}}
@php
    $briefing = \App\Support\Attention\MorningBriefing::for(auth()->user());
@endphp

<section class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" aria-labelledby="briefing-heading" data-test="morning-briefing">
    <div>
        <h2 id="briefing-heading" class="font-nav text-lg font-bold text-ink">{{ __('Your briefing for :day', ['day' => $briefing['day']]) }}</h2>
        <p class="text-sm text-zinc-600" data-test="briefing-since">
            {{ trans_choice('{0} Nothing new for you since this morning|{1} One new item for you since this morning|[2,*] :count new items for you since this morning', $briefing['since']['new']) }}, {{ trans_choice('{0} none cleared.|{1} one cleared.|[2,*] :count cleared.', $briefing['since']['cleared']) }}
        </p>
    </div>

    @if ($briefing['sections'] !== [])
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($briefing['sections'] as $section)
                <div class="flex flex-col gap-2" data-test="briefing-{{ \Illuminate\Support\Str::slug($section['title']) }}">
                    <h3 class="font-nav text-sm font-bold tracking-wide text-zinc-500 uppercase">{{ __($section['title']) }}</h3>
                    <ul class="flex flex-col gap-1">
                        @foreach ($section['lines'] as $line)
                            <li>
                                <a href="{{ $line['url'] }}" wire:navigate class="flex items-baseline justify-between gap-3 rounded-md px-2 py-1 text-sm hover:bg-zinc-100">
                                    <span @class(['text-ink' => $line['count'] > 0, 'text-zinc-500' => $line['count'] === 0])>{{ __($line['label']) }}</span>
                                    <span @class(['tabular-nums font-semibold', 'text-ink' => $line['count'] > 0, 'text-zinc-400' => $line['count'] === 0])>{{ $line['count'] }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    @endif
</section>
