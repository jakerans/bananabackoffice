{{--
    The signed-in person's weekly scorecards on Home (slice 27, step 27a;
    decision 187): each Location whose scorecard they own, its last week,
    and whether it is submitted, due or overdue.
--}}
@php
    $owned = \App\Support\Scorecards\ScorecardOwnership::locationsOf(auth()->user());
@endphp

@if ($owned->isNotEmpty())
    <section class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" aria-labelledby="scorecards-heading" data-test="home-scorecards">
        <h2 id="scorecards-heading" class="font-nav text-lg font-bold text-ink">{{ __('Your weekly scorecard') }}</h2>
        <ul class="flex flex-col gap-1">
            @foreach ($owned as $location)
                @php
                    $monday = \App\Support\Scorecards\ScorecardWeek::lastEnded($location);
                    $submitted = \App\Models\Scorecard::query()->where('location_id', $location->id)->whereDate('week_starts_on', $monday->toDateString())->exists();
                    $overdue = ! $submitted && \App\Support\Scorecards\ScorecardWeek::isOverdue($location, $monday);
                @endphp
                <li>
                    <a href="{{ route('scorecards.show', $location) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-2 rounded-md px-2 py-1 text-sm hover:bg-zinc-100" data-test="home-scorecard-{{ $location->id }}">
                        <span class="text-ink">{{ __(':location, :week', ['location' => $location->name, 'week' => \App\Support\Scorecards\ScorecardWeek::words($monday)]) }}</span>
                        @if ($submitted)
                            <flux:badge size="sm" color="green">{{ __('Submitted') }}</flux:badge>
                        @elseif ($overdue)
                            <flux:badge size="sm" color="rose">{{ __('Overdue') }}</flux:badge>
                        @else
                            <flux:badge size="sm" color="amber">{{ __('Due by the end of :day', ['day' => $monday->addWeek()->format('l j F')]) }}</flux:badge>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
