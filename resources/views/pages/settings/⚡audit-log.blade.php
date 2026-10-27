<?php

use App\Enums\AuditAction;
use App\Enums\Permission;
use App\Models\AuditEvent;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit\AuditLine;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Settings > Audit Log (docs/slice-29-audit-log-plan.md, steps 29a and 29b;
 * decision 191): every important change Backoffice has recorded, newest
 * first, for the Owner, narrowed by area, Location, person, dates or one
 * record. It only reads; a recorded change can never be altered or removed.
 */
new #[Title('Audit Log')] class extends Component {
    use WithPagination;

    /** How many changes are shown to a page. */
    public const int PER_PAGE = 50;

    /** One of AuditAction::AREAS, or empty for every area. */
    #[Url(except: '')]
    public string $area = '';

    /** A Location's id, or empty for every Location. */
    #[Url(except: '')]
    public string $location = '';

    /** The id of the person who made the change, or empty for anyone. */
    #[Url(except: '')]
    public string $person = '';

    /** The first and last day shown, "2026-10-01", in the display timezone. */
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    /** One record's history, "project:12", from a link on its page. */
    #[Url(except: '')]
    public string $record = '';

    public function mount(): void
    {
        $this->authorize(Permission::ViewAuditLog);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['area', 'location', 'person', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function showEverything(): void
    {
        $this->reset('area', 'location', 'person', 'from', 'to', 'record');
        $this->resetPage();
    }

    /**
     * Every Location, active or not, since changes were made at both.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Location::query()->orderBy('name')->get();
    }

    /**
     * Everyone who has made a recorded change.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function people(): Collection
    {
        return User::query()
            ->whereIn('id', AuditEvent::query()->whereNotNull('actor_id')->select('actor_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * The record whose history is shown, when a link asked for one Backoffice knows.
     *
     * @return array{type: string, id: int, label: string, url: string|null}|null
     */
    #[Computed]
    public function shownRecord(): ?array
    {
        if (preg_match('/^([a-z_]+):(\d+)$/', $this->record, $parts) !== 1 || Relation::getMorphedModel($parts[1]) === null) {
            return null;
        }

        $event = AuditEvent::query()->where('subject_type', $parts[1])->where('subject_id', (int) $parts[2])->with('subject')->first()
            ?? (new AuditEvent)->forceFill(['subject_type' => $parts[1], 'subject_id' => (int) $parts[2]]);

        return ['type' => $parts[1], 'id' => (int) $parts[2], ...AuditLine::record($event)];
    }

    /**
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    #[Computed]
    public function events(): LengthAwarePaginator
    {
        $shown = $this->shownRecord;
        $from = $this->day($this->from);
        $to = $this->day($this->to);

        return AuditEvent::query()
            ->with(['subject', 'location'])
            ->when(array_key_exists($this->area, AuditAction::AREAS), fn (Builder $query) => $query->whereIn('action', AuditAction::inArea($this->area)))
            ->when(ctype_digit($this->location), fn (Builder $query) => $query->where('location_id', (int) $this->location))
            ->when(ctype_digit($this->person), fn (Builder $query) => $query->where('actor_id', (int) $this->person))
            ->when($from !== null, fn (Builder $query) => $query->where('created_at', '>=', $from?->utc()))
            ->when($to !== null, fn (Builder $query) => $query->where('created_at', '<', $to?->addDay()->utc()))
            ->when($shown !== null, fn (Builder $query) => $query->where('subject_type', $shown['type'] ?? '')->where('subject_id', $shown['id'] ?? 0))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * The start of a day typed into a date filter, in the display timezone, or null when it is not a day.
     */
    private function day(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, (string) config('app.display_timezone'));

        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="audit-log">
    <div>
        <flux:heading size="xl" level="1">{{ __('Audit Log') }}</flux:heading>
        <flux:subheading>{{ __('Every important change, who made it and when, newest first. Nothing here can be changed or removed.') }}</flux:subheading>
    </div>

    @php
        $filtered = $this->area !== '' || $this->location !== '' || $this->person !== '' || $this->from !== '' || $this->to !== '' || $this->record !== '';
    @endphp

    @if ($this->shownRecord !== null)
        <flux:callout icon="clock" color="zinc" data-test="audit-record">
            <flux:callout.text>
                {{ __('The history of') }}
                @if ($this->shownRecord['url'] !== null)
                    <a href="{{ $this->shownRecord['url'] }}" wire:navigate class="font-semibold underline underline-offset-2">{{ $this->shownRecord['label'] }}</a>
                @else
                    <span class="font-semibold">{{ $this->shownRecord['label'] }}</span>
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5" data-test="audit-filters">
        <flux:select wire:model.live="area" :label="__('Area')" data-test="audit-area">
            <flux:select.option value="">{{ __('Every area') }}</flux:select.option>
            @foreach (AuditAction::AREAS as $key => $label)
                <flux:select.option :value="$key" wire:key="area-{{ $key }}">{{ __($label) }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="location" :label="__('Location')" data-test="audit-location">
            <flux:select.option value="">{{ __('Every Location') }}</flux:select.option>
            @foreach ($this->locations as $option)
                <flux:select.option :value="$option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="person" :label="__('Made by')" data-test="audit-person">
            <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
            @foreach ($this->people as $option)
                <flux:select.option :value="$option->id" wire:key="person-{{ $option->id }}">{{ $option->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="from" type="date" :label="__('From')" data-test="audit-from" />
        <flux:input wire:model.live="to" type="date" :label="__('To')" data-test="audit-to" />
    </div>

    @if ($filtered)
        <div>
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="showEverything" data-test="audit-show-everything">{{ __('Show everything') }}</flux:button>
        </div>
    @endif

    @if ($this->events->isEmpty())
        <flux:text data-test="audit-log-empty">{{ $filtered ? __('No recorded change matches.') : __('Nothing has been recorded yet.') }}</flux:text>
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
                        @if ($this->shownRecord === null)
                            <a href="{{ route('settings.audit_log', ['record' => $event->subject_type.':'.$event->subject_id]) }}" wire:navigate class="text-sm text-zinc-500 underline underline-offset-2 hover:text-ink" data-test="audit-history-link">{{ __('History') }}</a>
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
