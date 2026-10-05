<?php

use App\Enums\LeadStatus;
use App\Enums\Permission;
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

    public const string ROUTED = 'routed';

    public const string UNROUTED = 'unrouted';

    #[Url]
    public string $view = self::ROUTED;

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
     * Whether the unrouted queue is being shown. Asking for it without the permission shows the ordinary list.
     */
    #[Computed]
    public function showingUnrouted(): bool
    {
        return $this->view === self::UNROUTED && $this->canSeeUnrouted;
    }

    #[Computed]
    public function unroutedCount(): int
    {
        return $this->canSeeUnrouted ? Lead::query()->visibleTo(Auth::user())->unrouted()->count() : 0;
    }

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    #[Computed]
    public function leads(): LengthAwarePaginator
    {
        $user = Auth::user();

        // Always start from what the person is allowed to see, whatever is selected.
        $query = Lead::query()->visibleTo($user)->with(['channel', 'leadSource', 'location', 'services', 'projectSize']);

        if ($this->showingUnrouted) {
            $query->unrouted();
        } else {
            $context = $this->context;

            match (true) {
                $context?->location !== null => $query->where('location_id', $context->location->id),
                // "All Locations" shows only what a company-wide grant allows.
                $context?->isAllLocations === true && $user->hasPermissionAcrossAllLocations(Permission::ViewLeads) => $query->whereNotNull('location_id'),
                default => $query->whereRaw('1 = 0'),
            };
        }

        if (LeadStatus::tryFrom($this->status) !== null) {
            $query->where('status', $this->status);
        }

        $this->applySearch($query);

        return $query->orderByDesc('received_at')->orderByDesc('id')->paginate(25);
    }

    public function show(string $view): void
    {
        $this->view = $view === self::UNROUTED ? self::UNROUTED : self::ROUTED;

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
                @if ($this->showingUnrouted)
                    {{ __('Received centrally and not yet sent to a location.') }}
                @elseif ($this->context !== null)
                    {{ __('Inquiries for :location.', ['location' => $this->context->label()]) }}
                @endif
            </flux:subheading>
        </div>

        @can('create', Lead::class)
            <flux:button variant="primary" icon="plus" :href="route('leads.create')" wire:navigate data-test="new-lead-button">
                {{ __('New lead') }}
            </flux:button>
        @endcan
    </div>

    @if ($this->canSeeUnrouted)
        <nav aria-label="{{ __('Lead views') }}" class="flex flex-wrap gap-2">
            <flux:button
                size="sm"
                :variant="$this->showingUnrouted ? 'ghost' : 'primary'"
                wire:click="show('routed')"
                :aria-current="$this->showingUnrouted ? null : 'page'"
            >
                {{ $this->context?->label() ?? __('Routed') }}
            </flux:button>

            <flux:button
                size="sm"
                :variant="$this->showingUnrouted ? 'primary' : 'ghost'"
                wire:click="show('unrouted')"
                :aria-current="$this->showingUnrouted ? 'page' : null"
                data-test="unrouted-tab"
            >
                {{ __('Unrouted') }}
                <span class="ms-1 tabular-nums">({{ $this->unroutedCount }})</span>
            </flux:button>
        </nav>
    @endif

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

        <div class="sm:w-48">
            <flux:select wire:model.live="status" :label="__('Status')">
                <flux:select.option value="">{{ __('Any status') }}</flux:select.option>
                @foreach (LeadStatus::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>
    </div>

    @if ($this->leads->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center">
            <x-app-logo-icon class="h-12 w-auto" />
            <flux:heading size="lg">{{ __('No leads here') }}</flux:heading>
            <flux:text class="max-w-md">
                @if (trim($search) !== '' || $status !== '')
                    {{ __('Nothing matches that search. Try fewer words, or clear the status.') }}
                @elseif ($this->showingUnrouted)
                    {{ __('Every lead received centrally has been sent to a location.') }}
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
                    <flux:table.column>{{ __('Channel') }}</flux:table.column>
                    <flux:table.column>{{ __('Lead source') }}</flux:table.column>
                    <flux:table.column>{{ __('Location') }}</flux:table.column>
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

                            <flux:table.cell>{{ $lead->channel->name }}</flux:table.cell>
                            <flux:table.cell>{{ $lead->leadSource->name }}</flux:table.cell>

                            <flux:table.cell>
                                @if ($lead->location)
                                    {{ $lead->location->name }}
                                @else
                                    <flux:badge size="sm" color="amber">{{ __('Unrouted') }}</flux:badge>
                                @endif
                            </flux:table.cell>

                            <flux:table.cell>
                                <x-lead-status :status="$lead->status" />
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
                    </dl>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->leads" />
    @endif
</section>
