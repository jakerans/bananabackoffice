{{--
    One entry of a person's variable-compensation ledger (slice 20): what it
    is, the job it belongs to, how it was worked out, and its amount. The
    job is a link for those who may open it.
--}}
@props([
    'entry',
])

<li wire:key="pay-entry-{{ $entry->id }}" class="flex flex-wrap items-start justify-between gap-3 py-2" data-test="pay-entry">
    <div class="flex min-w-0 flex-1 flex-col text-sm">
        <span class="font-semibold text-ink">
            {{ __($entry->kindLabel()) }}@if ($entry->project !== null)
                &middot;
                @can('view', $entry->project)
                    <flux:link :href="route('projects.show', $entry->project)" wire:navigate>{{ $entry->project->title }}</flux:link>
                @else
                    {{ $entry->project->title }}
                @endcan
            @endif
        </span>
        <span class="text-zinc-700">{{ $entry->explanation }}</span>
        @if ($entry->reason !== null)
            <span class="text-zinc-700">{{ __('Why: :reason', ['reason' => $entry->reason]) }}</span>
        @endif
        <span class="text-xs text-zinc-500"><x-local-time :at="$entry->created_at" /></span>
    </div>
    <span class="font-nav font-bold tabular-nums {{ $entry->amount_cents < 0 ? 'text-rose-700' : 'text-ink' }}" data-test="pay-entry-amount">{{ $entry->amount() }}</span>
</li>
