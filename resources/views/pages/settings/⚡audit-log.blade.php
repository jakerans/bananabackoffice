<?php

use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Support\Audit\AuditLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Settings > Audit Log (docs/slice-29-audit-log-plan.md, step 29a; decision
 * 191): every important change Backoffice has recorded, newest first, for the
 * Owner. It only reads; a recorded change can never be altered or removed.
 */
new #[Title('Audit Log')] class extends Component {
    use WithPagination;

    /** How many changes are shown to a page. */
    public const int PER_PAGE = 50;

    public function mount(): void
    {
        $this->authorize(Permission::ViewAuditLog);
    }

    /**
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        return AuditEvent::query()
            ->with(['subject', 'location'])
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="audit-log">
    <div>
        <flux:heading size="xl" level="1">{{ __('Audit Log') }}</flux:heading>
        <flux:subheading>{{ __('Every important change, who made it and when, newest first. Nothing here can be changed or removed.') }}</flux:subheading>
    </div>

    @if ($this->events->isEmpty())
        <flux:text data-test="audit-log-empty">{{ __('Nothing has been recorded yet.') }}</flux:text>
    @else
        <ol class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 bg-card">
            @foreach ($this->events as $event)
                @php
                    $record = AuditLine::record($event);
                    $changes = AuditLine::changes($event);
                @endphp
                <li wire:key="audit-{{ $event->id }}" class="flex flex-col gap-1 p-4" data-test="audit-event-{{ $event->id }}">
                    <div class="flex flex-col gap-x-3 sm:flex-row sm:flex-wrap sm:items-baseline">
                        <span class="font-semibold text-ink">{{ $event->action->label() }}</span>
                        @if ($record['url'] !== null)
                            <a href="{{ $record['url'] }}" wire:navigate class="text-sm text-ink underline underline-offset-2 hover:text-zinc-600">{{ $record['label'] }}</a>
                        @else
                            <span class="text-sm text-zinc-600">{{ $record['label'] }}</span>
                        @endif
                    </div>
                    <p class="text-sm text-zinc-500">
                        <x-local-time :at="$event->created_at" />
                        &middot; {{ $event->madeBy() }}
                        @if ($event->location !== null)
                            &middot; {{ $event->location->name }}
                        @endif
                    </p>
                    @if (filled($event->reason))
                        <p class="text-sm text-zinc-700">{{ __('Why: :reason', ['reason' => $event->reason]) }}</p>
                    @endif
                    @if ($changes !== [])
                        <details class="text-sm" data-test="audit-changes">
                            <summary class="cursor-pointer text-zinc-600">{{ __('What changed') }}</summary>
                            <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-[minmax(8rem,auto)_1fr]">
                                @foreach ($changes as $change)
                                    <dt class="font-medium text-zinc-700">{{ $change['field'] }}</dt>
                                    <dd class="break-words text-zinc-600">
                                        @if ($change['before'] === '—')
                                            {{ $change['after'] }}
                                        @elseif ($change['after'] === '—')
                                            {{ $change['before'] }} &rarr; —
                                        @else
                                            {{ $change['before'] }} &rarr; {{ $change['after'] }}
                                        @endif
                                    </dd>
                                @endforeach
                            </dl>
                        </details>
                    @endif
                </li>
            @endforeach
        </ol>

        <flux:pagination :paginator="$this->events" />
    @endif
</section>
