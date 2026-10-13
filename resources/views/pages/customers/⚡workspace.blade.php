<?php

use App\Actions\Notes\AddNote;
use App\Actions\Notes\RemoveNote;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Lead;
use App\Models\Note;
use App\Models\Project;
use App\Support\Customers\CustomerActivity;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customer activity & notes')] class extends Component {
    use WithPagination;

    #[Locked]
    public Account $account;

    #[Url(as: 'from')]
    public string $returnContext = 'customer';

    #[Url(as: 'lead')]
    public ?int $returnLeadId = null;

    public string $noteBody = '';

    public string $noteLeadId = '';

    #[Locked]
    public ?int $pendingNoteId = null;

    public function mount(Account $account): void
    {
        $this->authorize('view', $account);

        $this->account = $account;

        if ($this->returnContext !== 'lead' || $this->returnLead() === null) {
            $this->returnContext = 'customer';
            $this->returnLeadId = null;
        }

        $this->noteLeadId = (string) ($this->writableLeads->first()?->id ?? '');
    }

    /**
     * Recheck the customer boundary on every request, not only the first page load.
     */
    public function hydrate(): void
    {
        $this->authorize('view', $this->account);
    }

    /**
     * The customer's Leads this person can see now, never all related Leads.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function visibleLeads(): Collection
    {
        return $this->account->leads()
            ->visibleTo(Auth::user())
            ->with(['location', 'services'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Only Lead contexts where the existing note policy permits a new note.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function writableLeads(): Collection
    {
        $user = Auth::user();

        return $this->visibleLeads
            ->filter(fn (Lead $lead): bool => $user->can('create', [Note::class, $lead]))
            ->values();
    }

    /**
     * Notes remain attached to their original Lead and are unified here only for reading.
     * Removed notes appear as tombstones so history remains visible without showing removed text.
     *
     * @return LengthAwarePaginator<int, Note>
     */
    #[Computed]
    public function notes(): LengthAwarePaginator
    {
        return Note::query()
            ->where('notable_type', (new Lead)->getMorphClass())
            ->whereIn('notable_id', $this->visibleLeadIds())
            ->with(['author', 'removedBy', 'notable.location', 'notable.services'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: 10, pageName: 'notesPage');
    }

    /**
     * Major business events only. Raw audit values and non-business audit events never leave the query.
     *
     * @return LengthAwarePaginator<int, AuditEvent>
     */
    #[Computed]
    public function activity(): LengthAwarePaginator
    {
        $user = Auth::user();
        $accountType = $this->account->getMorphClass();
        $leadType = (new Lead)->getMorphClass();
        $leadIds = $this->visibleLeadIds();
        $leadLocationIds = $user->locationsWhere(Permission::ViewLeads)->modelKeys();
        $seesCentralEvents = $user->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
        $projectType = (new Project)->getMorphClass();
        $projectIds = $this->visibleProjectIds();

        $events = AuditEvent::query()
            ->whereIn('action', CustomerActivity::actions())
            ->where(function (Builder $query) use ($accountType, $leadType, $leadIds, $leadLocationIds, $seesCentralEvents, $projectType, $projectIds): void {
                $query->where(function (Builder $account) use ($accountType): void {
                    $account->where('subject_type', $accountType)->where('subject_id', $this->account->id);
                })->orWhere(function (Builder $projects) use ($projectType, $projectIds): void {
                    $projects->where('subject_type', $projectType)->whereIn('subject_id', $projectIds);
                })->orWhere(function (Builder $leads) use ($leadType, $leadIds, $leadLocationIds, $seesCentralEvents): void {
                    $leads
                        ->where('subject_type', $leadType)
                        ->whereIn('subject_id', $leadIds)
                        ->where(function (Builder $eventLocation) use ($leadLocationIds, $seesCentralEvents): void {
                            $eventLocation->whereIn('location_id', $leadLocationIds);

                            if ($seesCentralEvents) {
                                $eventLocation->orWhereNull('location_id');
                            }
                        });
                });
            })
            ->with(['location', 'subject'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(perPage: 10, pageName: 'activityPage');

        $events->getCollection()->loadMorph('subject', [Lead::class => ['services'], Project::class => ['location']]);

        return $events;
    }

    public function addNote(AddNote $addNote): void
    {
        $this->authorize('view', $this->account);

        $this->validate(
            [
                'noteLeadId' => ['required', 'integer'],
                'noteBody' => ['required', 'string', 'max:'.AddNote::MAX_LENGTH],
            ],
            ['noteBody.required' => __('Write the note before adding it.')],
            ['noteLeadId' => 'context', 'noteBody' => 'note'],
        );

        $lead = $this->visibleLead((int) $this->noteLeadId);

        $this->authorize('create', [Note::class, $lead]);

        $addNote->handle($lead, Auth::user(), $this->noteBody);

        $this->noteBody = '';
        $this->resetPage(pageName: 'notesPage');
        unset($this->notes);

        Flux::toast(variant: 'success', text: __('Note added.'));
    }

    public function openRemoveNote(int $noteId): void
    {
        $note = $this->visibleNote($noteId);

        $this->authorize('remove', $note);

        $this->pendingNoteId = $note->id;

        Flux::modal('remove-customer-note-confirmation')->show();
    }

    public function confirmRemoveNote(RemoveNote $removeNote): void
    {
        $note = $this->visibleNote($this->pendingNoteId ?? abort(404));

        $this->authorize('remove', $note);

        $removeNote->handle($note, Auth::user());

        $this->reset('pendingNoteId');
        $this->resetPage(pageName: 'notesPage');
        unset($this->notes);

        Flux::modal('remove-customer-note-confirmation')->close();
        Flux::toast(variant: 'success', text: __('Note removed.'));
    }

    #[Computed]
    public function backUrl(): string
    {
        $lead = $this->returnLead();

        if ($this->returnContext === 'lead' && $lead !== null) {
            return route('leads.show', $lead);
        }

        return route('customers.show', $this->account);
    }

    public function activityLabel(AuditEvent $event): string
    {
        return CustomerActivity::label($event->action);
    }

    public function leadLabel(Lead $lead): string
    {
        $service = $lead->primaryService()?->name;

        return $service === null
            ? __('Lead #:id', ['id' => $lead->id])
            : __(':service lead', ['service' => $service]);
    }

    /** @return list<int> */
    private function visibleLeadIds(): array
    {
        return $this->account->leads()->visibleTo(Auth::user())->pluck('id')->all();
    }

    /**
     * The customer's Projects this person may see: at a location where they
     * see Projects, or from a Lead they may see.
     *
     * @return list<int>
     */
    private function visibleProjectIds(): array
    {
        $user = Auth::user();

        return $this->account->projects()
            ->where(function (Builder $visible) use ($user): void {
                $visible
                    ->whereIn('location_id', $user->locationsWhere(Permission::ViewProjects)->modelKeys())
                    ->orWhereIn('lead_id', $this->account->leads()->visibleTo($user)->select('leads.id'));
            })
            ->pluck('id')
            ->all();
    }

    private function visibleLead(int $leadId): Lead
    {
        return $this->account->leads()->visibleTo(Auth::user())->findOrFail($leadId);
    }

    private function visibleNote(int $noteId): Note
    {
        $note = Note::query()
            ->where('notable_type', (new Lead)->getMorphClass())
            ->whereIn('notable_id', $this->visibleLeadIds())
            ->findOrFail($noteId);

        return $note->setRelation('notable', $this->visibleLead($note->notable_id));
    }

    private function returnLead(): ?Lead
    {
        if ($this->returnLeadId === null) {
            return null;
        }

        return $this->account->leads()
            ->visibleTo(Auth::user())
            ->whereKey($this->returnLeadId)
            ->first();
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div>
        <flux:link :href="$this->backUrl" wire:navigate class="text-sm" data-test="workspace-back">&larr; {{ __('Back') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex flex-col gap-2">
            <flux:heading size="xl" level="1">{{ $account->display_name }}</flux:heading>
            <flux:subheading>{{ __('Customer activity and notes across the leads you can currently access.') }}</flux:subheading>
        </div>

        <flux:button size="sm" :href="route('customers.show', $account)" wire:navigate>{{ __('Customer details') }}</flux:button>
    </div>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-notes">
            <div>
                <flux:heading size="lg" level="2">{{ __('Notes') }}</flux:heading>
                <flux:subheading>{{ __('One history, with each note kept in the lead where it was written.') }}</flux:subheading>
            </div>

            @if ($this->writableLeads->isNotEmpty())
                <form wire:submit="addNote" class="flex flex-col gap-3">
                    <flux:select wire:model="noteLeadId" :label="__('Context')" data-test="note-context">
                        @foreach ($this->writableLeads as $lead)
                            <flux:select.option :value="$lead->id" wire:key="note-context-{{ $lead->id }}">
                                {{ $this->leadLabel($lead) }} · {{ $lead->location?->name ?? __('Unrouted') }}
                            </flux:select.option>
                        @endforeach
                    </flux:select>

                    <flux:textarea wire:model="noteBody" rows="3" :label="__('New note')" :placeholder="__('What should the team know?')" />

                    <div>
                        <flux:button type="submit" variant="primary" data-test="add-note">{{ __('Add note') }}</flux:button>
                    </div>
                </form>
            @endif

            @if ($this->notes->isEmpty())
                <flux:text data-test="no-customer-notes">{{ __('No notes yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->notes as $note)
                        <li wire:key="customer-note-{{ $note->id }}" class="flex flex-col gap-2 py-4 first:pt-0 last:pb-0" data-test="customer-note">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="text-sm">
                                    <span class="font-semibold text-ink">{{ $note->author?->name ?? __('Unknown') }}</span>
                                    <span class="text-zinc-500">&middot; <x-local-time :at="$note->created_at" /></span>
                                </div>

                                @if (! $note->isRemoved())
                                    @can('remove', $note)
                                        <flux:button size="xs" variant="ghost" wire:click="openRemoveNote({{ $note->id }})" data-test="remove-note">{{ __('Remove') }}</flux:button>
                                    @endcan
                                @endif
                            </div>

                            <div class="text-sm text-zinc-600">
                                <a href="{{ route('leads.show', $note->notable) }}" wire:navigate class="font-medium text-wall-teal underline decoration-zinc-300 underline-offset-2">
                                    {{ $this->leadLabel($note->notable) }}
                                </a>
                                &middot; {{ $note->notable->location?->name ?? __('Unrouted') }}
                            </div>

                            @if ($note->isRemoved())
                                <div class="rounded-lg bg-zinc-100 p-3 text-sm text-zinc-600" data-test="removed-note">
                                    {{ __('This note was removed from view') }}
                                    @if ($note->removed_at !== null)
                                        <x-local-time :at="$note->removed_at" />
                                    @endif
                                    @if ($note->removedBy !== null)
                                        {{ __('by :name', ['name' => $note->removedBy->name]) }}
                                    @endif
                                    .
                                </div>
                            @else
                                <div class="whitespace-pre-line text-ink">{{ $note->body }}</div>
                            @endif
                        </li>
                    @endforeach
                </ul>

                <flux:pagination :paginator="$this->notes" />
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-activity">
            <div>
                <flux:heading size="lg" level="2">{{ __('Activity') }}</flux:heading>
                <flux:subheading>{{ __('Major business events from the customer and the leads you can currently access.') }}</flux:subheading>
            </div>

            @if ($this->activity->isEmpty())
                <flux:text data-test="no-customer-activity">{{ __('No activity yet.') }}</flux:text>
            @else
                <ol class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->activity as $event)
                        <li wire:key="customer-activity-{{ $event->id }}" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0" data-test="customer-activity-event">
                            <div class="font-semibold text-ink">{{ $this->activityLabel($event) }}</div>

                            <div class="text-sm text-zinc-600">
                                @if ($event->subject instanceof Lead)
                                    <a href="{{ route('leads.show', $event->subject) }}" wire:navigate class="font-medium text-wall-teal underline decoration-zinc-300 underline-offset-2">
                                        {{ $this->leadLabel($event->subject) }}
                                    </a>
                                @elseif ($event->subject instanceof Project)
                                    @can('view', $event->subject)
                                        <a href="{{ route('projects.show', $event->subject) }}" wire:navigate class="font-medium text-wall-teal underline decoration-zinc-300 underline-offset-2">{{ $event->subject->title }}</a>
                                    @else
                                        {{ $event->subject->title }}
                                    @endcan
                                @else
                                    {{ __('Customer') }}
                                @endif
                                @if ($event->location !== null)
                                    &middot; {{ $event->location->name }}
                                @endif
                            </div>

                            <div class="text-sm text-zinc-500">
                                <x-local-time :at="$event->created_at" /> &middot; {{ __('Recorded by :name', ['name' => $event->madeBy()]) }}
                            </div>
                        </li>
                    @endforeach
                </ol>

                <flux:pagination :paginator="$this->activity" />
            @endif
        </div>
    </div>

    <x-confirmation-modal
        name="remove-customer-note-confirmation"
        :title="__('Remove this note?')"
        :description="__('It will no longer be shown, but its place in the customer history is kept.')"
        action="confirmRemoveNote"
        :confirm-label="__('Remove note')"
    />
</section>
