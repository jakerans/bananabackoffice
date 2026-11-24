@props(['path'])

{{-- A job's way through Production, in order (slice 39; decision 237): what is done, and what is next. --}}
<nav class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-card p-5" aria-label="{{ __('Steps of this job') }}" data-test="project-path">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="lg" level="2">{{ __('Where this job is') }}</flux:heading>
            <flux:subheading data-test="project-path-count">{{ __(':done of :all steps done, in the order the work goes. Each is ticked by what is recorded.', ['done' => $path->done(), 'all' => $path->needed()]) }}</flux:subheading>
        </div>

        @if ($path->next() !== null)
            <flux:button size="sm" variant="primary" icon:trailing="arrow-down" :href="'#'.$path->next()['anchor']" data-test="project-path-next">{{ __('Next: :step', ['step' => $path->next()['label']]) }}</flux:button>
        @else
            <flux:badge color="emerald" data-test="project-path-finished">{{ __('Every step is done') }}</flux:badge>
        @endif
    </div>

    <ol class="flex flex-wrap gap-x-1 gap-y-2 text-sm">
        @foreach ($path->steps as $step)
            <li class="flex items-center" data-test="project-path-step" data-step="{{ $step['key'] }}" data-done="{{ $step['done'] ? 'yes' : 'no' }}">
                <a href="#{{ $step['anchor'] }}" @class([
                    'inline-flex items-center gap-1 rounded-full border px-2.5 py-1 no-underline',
                    'border-emerald-200 bg-emerald-50 text-emerald-800' => $step['done'],
                    'border-amber-300 bg-amber-50 font-semibold text-ink' => ! $step['done'] && $path->next() !== null && $path->next()['key'] === $step['key'],
                    'border-zinc-200 text-zinc-600' => ! $step['done'] && ($path->next() === null || $path->next()['key'] !== $step['key']),
                ])>
                    @if ($step['done'])
                        <flux:icon.check variant="micro" />
                        <span class="sr-only">{{ __('Done:') }}</span>
                    @endif
                    {{ $loop->iteration }}. {{ $step['label'] }}@if (! $step['needed'] && ! $step['done']) <span class="text-zinc-500">({{ __('any time') }})</span>@endif
                </a>
            </li>
        @endforeach
    </ol>
</nav>
