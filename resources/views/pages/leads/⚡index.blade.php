<?php

use App\Enums\LeadStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Lead;
use App\Support\Access\CurrentLocation;
use App\Support\Access\LocationContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Leads')] class extends Component {
    use WithPagination;

    /** The Leads this person has to act on now, at every location they can see. */
    public const string MINE = 'mine';

    /** Leads in Nurture, by the date they are to be followed up. */
    public const string FOLLOW_UPS = 'follow-ups';

    /** Leads at the current location that nobody is responsible for yet. */
    public const string UNASSIGNED = 'unassigned';

    /** Every Lead at the current location. */
    public const string ALL = 'all';

    /** Leads received centrally and not yet sent to a location. */
    public const string UNROUTED = 'unrouted';

    /** Empty means "the view this person normally starts on". */
    #[Url]
    public string $view = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize(Permission::ViewLeads);
    }

    /**
     * The location the staff member is working in. It chooses which Leads are
     * listed; it never decides which Leads they are allowed to see.
     */
    #[Computed]
    public function context(): ?LocationContext
    {
        return app(CurrentLocation::class)->context(Auth::user());
    }

    #[Computed]
    public function canSeeUnrouted(): bool
    {
        return Auth::user()->hasPermissionAcrossAllLocations(Permission::ViewUnroutedLeads);
    }

    /**
     * Whether this person hands out Leads in the current location. Only they get
     * the Unassigned queue: it is for distributing work, not for picking it up.
     */
    #[Computed]
    public function canDistribute(): bool
    {
        return $this->context !== null && Auth::user()->hasPermissionIn(Permission::AssignLeads, $this->context);
    }

    /**
     * The views this person may use, in the order they are shown.
     *
     * @return list<string>
     */
    #[Computed]
    public function views(): array
    {
        return array_values(array_filter([
            self::MINE,
            self::FOLLOW_UPS,
            $this->canDistribute ? self::UNASSIGNED : null,
            self::ALL,
            $this->canSeeUnrouted ? self::UNROUTED : null,
        ]));
    }

    /**
     * The view being shown. Asking for one this person may not use shows the
     * one they normally start on, which depends on the job they do.
     */
    #[Computed]
    public function currentView(): string
    {
        if (in_array($this->view, $this->views, true)) {
            return $this->view;
        }

        $user = Auth::user();

        return match (true) {
            $user->holdsRole(Role::Owner), $user->holdsRole(Role::Leadership) => self::ALL,
            $this->canSeeUnrouted => self::UNROUTED,
            $this->canDistribute => self::UNASSIGNED,
            default => self::MINE,
        };
    }

    /**
     * How many Leads wait in each queue. "All leads" is not a queue and has no count.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        $user = Auth::user();

        return [
            self::MINE => Lead::query()->visibleTo($user)->where('assigned_user_id', $user->id)->actionable()->count(),
            // Only the follow-ups whose date has arrived are waiting on anyone.
            self::FOLLOW_UPS => $this->followUps(Lead::query()->visibleTo($user))->whereDate('follow_up_on', '<=', Lead::today())->count(),
            self::UNASSIGNED => $this->canDistribute ? $this->atCurrentLocation(Lead::query()->visibleTo($user))->unassigned()->active()->count() : 0,
            self::UNROUTED => $this->canSeeUnrouted ? Lead::query()->visibleTo($user)->unrouted()->active()->count() : 0,
        ];
    }

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        $user = Auth::user();

        // Always start from what the person is allowed to see, whatever is selected.
        $query = Lead::query()->visibleTo($user)->with(['channel', 'leadSource', 'location', 'services', 'projectSize', 'assignee', 'disqualificationReason']);

        match ($this->currentView) {
            // Their own work wherever it is, whatever location is selected: oldest first.
            // A follow-up joins it once its date arrives, and not before.
            self::MINE => $query->where('assigned_user_id', $user->id)->actionable()->orderBy('received_at')->orderBy('id'),
            // Soonest, and so most overdue, first.
            self::FOLLOW_UPS => $this->followUps($query)->orderBy('follow_up_on')->orderBy('id'),
            // Longest at this location without anyone responsible first.
            self::UNASSIGNED => $this->atCurrentLocation($query)->unassigned()->active()->orderBy('routed_at')->orderBy('id'),
            self::UNROUTED => $query->unrouted()->orderBy('received_at')->orderBy('id'),
            default => $this->atCurrentLocation($query)->orderByDesc('received_at')->orderByDesc('id'),
        };

        if ($this->currentView !== self::FOLLOW_UPS) {
            // Disqualified Leads are closed. They are listed only when asked for by status.
            LeadStatus::tryFrom($this->status) !== null
                ? $query->where('status', $this->status)
                : $query->active();
        }

        $this->applySearch($query);

        return $query->paginate(25);
    }

    public function show(string $view): void
    {
        $this->view = in_array($view, $this->views, true) ? $view : '';

        unset($this->currentView, $this->leads);

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function viewLabel(string $view): string
    {
        return match ($view) {
            self::MINE => __('Assigned to me'),
            self::FOLLOW_UPS => __('Follow-ups'),
            self::UNASSIGNED => __('Unassigned'),
            self::UNROUTED => __('Unrouted'),
            default => __('All leads'),
        };
    }

    /**
     * Limit a query to the Leads in Nurture this person follows: their own
     * wherever they are; for someone who hands out Leads in the location being
     * worked in, every one there, with or without an assignee; and for someone
     * who may see unrouted Leads, those too.
     *
     * A move or a return to central clears the assignee, so a Nurture Lead can
     * have nobody on it. It must stay in front of the people who can hand it out.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function followUps(Builder $query): Builder
    {
        return $query
            ->where('status', LeadStatus::Nurture)
            ->where(function (Builder $query): void {
                $query->where('assigned_user_id', Auth::id());

                if ($this->canDistribute) {
                    $query->orWhere(fn (Builder $here) => $this->atCurrentLocation($here));
                }

                if ($this->canSeeUnrouted) {
                    $query->orWhereNull('location_id');
                }
            });
    }

    /**
     * Limit a query to the routed Leads of the location being worked in.
     *
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function atCurrentLocation(Builder $query): Builder
    {
        $context = $this->context;

        return match (true) {
            $context?->location !== null => $query->where('location_id', $context->location->id),
            // "All Locations" shows only what a company-wide grant allows.
            $context?->isAllLocations === true && Auth::user()->hasPermissionAcrossAllLocations(Permission::ViewLeads) => $query->whereNotNull('location_id'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Match the search against the name, business, email and phone that were given.
     *
     * @param  Builder<Lead>  $query
     */
    private function applySearch(Builder $query): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $query) use ($like, $digits): void {
            $query
                ->whereRaw('lower(inquiry_name) like ?', [$like])
                ->orWhereRaw('lower(inquiry_business_name) like ?', [$like])
                ->orWhere('inquiry_email_normalized', 'like', $like);

            if (strlen((string) $digits) >= 3) {
                $query->orWhere('inquiry_phone_normalized', 'like', '%'.$digits.'%');
            }
        });
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Leads') }}</flux:heading>
            <flux:subheading data-test="leads-scope">
                @switch($this->currentView)
                    @case($this::MINE)
                        {{ __('Leads waiting on you, at every location you work in. Oldest first. A follow-up appears here once its date arrives.') }}
                        @break
                    @case($this::FOLLOW_UPS)
                        @if ($this->canDistribute && $this->canSeeUnrouted)
                            {{ __('Every lead with a deferred follow-up for :location, assigned or not, those not yet sent to a location, and your own anywhere. Soonest follow-up first.', ['location' => $this->context?->label()]) }}
                        @elseif ($this->canDistribute)
                            {{ __('Every lead with a deferred follow-up for :location, assigned or not, and your own anywhere. Soonest follow-up first.', ['location' => $this->context?->label()]) }}
                        @else
                            {{ __('Your leads with a deferred follow-up. Soonest follow-up first.') }}
                        @endif
                        @break
                    @case($this::UNASSIGNED)
                        {{ __('Leads for :location that nobody is responsible for yet. Longest waiting first.', ['location' => $this->context?->label()]) }}
                        @break
                    @case($this::UNROUTED)
                        {{ __('Received centrally and not yet sent to a location.') }}
                        @break
                    @default
                        @if ($this->context !== null)
                            {{ __('Inquiries for :location.', ['location' => $this->context->label()]) }}
                        @endif
                @endswitch
            </flux:subheading>
        </div>

        @can('create', Lead::class)
            <flux:button variant="primary" icon="plus" :href="route('leads.create')" wire:navigate data-test="new-lead-button">
                {{ __('New lead') }}
            </flux:button>
        @endcan
    </div>

    <nav aria-label="{{ __('Lead views') }}" class="flex flex-wrap gap-2">
        @foreach ($this->views as $option)
            <flux:button
                size="sm"
                :variant="$this->currentView === $option ? 'primary' : 'ghost'"
                wire:click="show('{{ $option }}')"
                wire:key="view-{{ $option }}"
                :aria-current="$this->currentView === $option ? 'page' : null"
                data-test="{{ $option }}-tab"
            >
                {{ $this->viewLabel($option) }}
                @if (array_key_exists($option, $this->counts))
                    <span class="ms-1 tabular-nums">({{ $this->counts[$option] }})</span>
                @endif
            </flux:button>
        @endforeach
    </nav>

    <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                icon="magnifying-glass"
                :label="__('Search')"
                :placeholder="__('Name, business, phone or email')"
                autocomplete="off"
            />
        </div>

        @if ($this->currentView !== $this::FOLLOW_UPS)
            <div class="sm:w-48">
                <flux:select wire:model.live="status" :label="__('Status')" data-test="status-filter">
                    <flux:select.option value="">{{ __('Open leads') }}</flux:select.option>
                    @foreach (LeadStatus::cases() as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif
    </div>

    @if ($this->leads->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="no-leads">
            <x-app-logo-icon class="h-12 w-auto" />
            <flux:heading size="lg">{{ __('No leads here') }}</flux:heading>
            <flux:text class="max-w-md">
                @if (trim($search) !== '' || $status !== '')
                    {{ __('Nothing matches that search. Try fewer words, or clear the status. Disqualified leads are listed only when that status is chosen.') }}
                @elseif ($this->currentView === $this::FOLLOW_UPS)
                    {{ __('No deferred follow-ups here.') }}
                @elseif ($this->currentView === $this::UNROUTED)
                    {{ __('Every lead received centrally has been sent to a location.') }}
                @elseif ($this->currentView === $this::MINE)
                    {{ __('Nothing is waiting on you right now.') }}
                @elseif ($this->currentView === $this::UNASSIGNED)
                    {{ __('Every lead here has someone responsible for it.') }}
                @else
                    {{ __('Inquiries appear here as soon as they are recorded.') }}
                @endif
            </flux:text>
        </div>
    @else
        {{-- Wide screens: a table. --}}
        <div class="hidden overflow-hidden rounded-xl border border-zinc-200 bg-card px-4 md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Received') }}</flux:table.column>
                    <flux:table.column>{{ __('Name') }}</flux:table.column>
                    <flux:table.column>{{ __('Contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Service and size') }}</flux:table.column>
                    <flux:table.column>{{ __('Channel and source') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
                    <flux:table.column>{{ __('Assigned to') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->leads as $lead)
                        <flux:table.row :key="'row-'.$lead->id">
                            <flux:table.cell class="whitespace-nowrap tabular-nums">
                                <x-local-time :at="$lead->received_at" relative />
                            </flux:table.cell>

                            <flux:table.cell variant="strong">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">
                                    {{ $lead->displayName() ?? __('No name given') }}
                                </a>
                                @if ($lead->inquiry_name !== null && $lead->inquiry_business_name !== null)
                                    <div class="text-sm font-normal text-zinc-500">{{ $lead->inquiry_business_name }}</div>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell class="tabular-nums">
                                <div>{{ $lead->displayPhone() }}</div>
                                <div class="text-sm text-zinc-500">{{ $lead->inquiry_email }}</div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div>
                                    {{ $lead->primaryService()?->name ?? '—' }}
                                    @if ($lead->services->count() > 1)
                                        <span class="text-sm text-zinc-500">+{{ $lead->services->count() - 1 }}</span>
                                    @endif
                                </div>
                                <div class="text-sm text-zinc-500">{{ $lead->projectSize?->name ?? __('Not classified') }}</div>
                            </flux:table.cell>

                            <flux:table.cell>
                                <div>{{ $lead->channel->name }}</div>
                                <div class="text-sm text-zinc-500">{{ $lead->leadSource->name }}</div>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($lead->location)
                                    {{ $lead->location->name }}
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Unrouted') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($lead->assignee)
                                    {{ $lead->assignee->name }}
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Unassigned') }}</flux:badge>
                                    @if ($lead->routed_at !== null)
                                        <div class="text-sm text-zinc-500">{{ __('here') }} <x-local-time :at="$lead->routed_at" relative /></div>
                                    @endif
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <x-lead-status :status="$lead->status" />
                                <x-lead-follow-up :lead="$lead" class="mt-1" />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- Narrow screens: one card per lead, no sideways scrolling. --}}
        <ul class="flex flex-col gap-3 md:hidden">
            @foreach ($this->leads as $lead)
                <li wire:key="card-{{ $lead->id }}" class="rounded-xl border border-zinc-200 bg-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2">
                            {{ $lead->displayName() ?? __('No name given') }}
                        </a>
                        <x-lead-status :status="$lead->status" />
                    </div>

                    <dl class="mt-2 flex flex-col gap-1 text-sm text-zinc-600">
                        @if ($lead->inquiry_phone !== null)
                            <div><a href="tel:{{ $lead->inquiry_phone_normalized ?? $lead->inquiry_phone }}" class="tabular-nums text-wall-teal underline">{{ $lead->displayPhone() }}</a></div>
                        @endif
                        @if ($lead->inquiry_email !== null)
                            <div class="break-all">{{ $lead->inquiry_email }}</div>
                        @endif
                        <div>
                            {{ $lead->primaryService()?->name ?? __('No service') }}
                            &middot;
                            {{ $lead->projectSize?->name ?? __('Not classified') }}
                        </div>
                        <div>
                            {{ $lead->channel->name }}
                            &middot;
                            {{ $lead->location?->name ?? __('Unrouted') }}
                            &middot;
                            <x-local-time :at="$lead->received_at" relative />
                        </div>
                        <div>{{ $lead->assignee?->name ?? __('Unassigned') }}</div>
                        <x-lead-follow-up :lead="$lead" />
                    </dl>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->leads" />
    @endif
</section>
