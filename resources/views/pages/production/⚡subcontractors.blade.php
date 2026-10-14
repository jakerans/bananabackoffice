<?php

use App\Actions\Subcontractors\ChangeSubcontractor;
use App\Enums\Permission;
use App\Enums\SubcontractorStanding;
use App\Models\ComplianceRequirement;
use App\Models\Location;
use App\Models\Service;
use App\Models\Subcontractor;
use App\Support\Access\CurrentLocation;
use App\Support\Subcontractors\Compliance;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Production > Subcontractors (docs/slice-12-subcontractors-plan.md, step
 * 12a; product-architecture.md, 7, 7.1 and 7.3; decision 148): the
 * subcontractors who work at one Location, on the roster, in recruiting, and
 * past.
 *
 * A person sees the Locations where they may see subcontractors. What the
 * browser asks for is checked against that, and adding one checks again.
 */
new #[Title('Subcontractors')] class extends Component {
    /** The most subcontractors shown in one list. More than this is said, not hidden. */
    public const int LIMIT = 200;

    #[Url(as: 'location')]
    public ?int $locationId = null;

    #[Url(as: 'search')]
    public string $search = '';

    /** @var array<string, mixed> The subcontractor being added. */
    public array $details = [];

    public function mount(): void
    {
        $this->authorize(Permission::ViewSubcontractors);

        $this->locationId = $this->location?->id;
    }

    /**
     * A field that was refused and has since been changed no longer shows its
     * refusal.
     */
    public function updated(string $property): void
    {
        $this->resetValidation($property);
    }

    /**
     * The Locations whose subcontractors this person may see.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ViewSubcontractors)->sortBy('name')->values();
    }

    /**
     * The Location shown: the one asked for if this person may see it,
     * otherwise the one they are working in, otherwise the first they may see.
     */
    #[Computed]
    public function location(): ?Location
    {
        $working = app(CurrentLocation::class)->context(Auth::user())?->location;

        return $this->locations->firstWhere('id', $this->locationId)
            ?? ($working === null ? null : $this->locations->firstWhere('id', $working->id))
            ?? $this->locations->first();
    }

    /**
     * The Locations where this person may add and change subcontractors.
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function managedLocations(): Collection
    {
        return Auth::user()->locationsWhere(Permission::ManageSubcontractors)->sortBy('name')->values();
    }

    /**
     * Whether the signed-in person adds subcontractors at the Location shown.
     */
    #[Computed]
    public function mayAdd(): bool
    {
        return $this->location !== null && $this->managedLocations->contains('id', $this->location->id);
    }

    /**
     * @return Collection<int, Service>
     */
    #[Computed]
    public function services(): Collection
    {
        return Service::query()->active()->ordered()->get();
    }

    /**
     * The Location's subcontractors that match the search, by name.
     *
     * @return Collection<int, Subcontractor>
     */
    #[Computed]
    public function subcontractors(): Collection
    {
        $search = trim($this->search);

        return Subcontractor::query()
            ->whereHas('locations', fn ($locations) => $locations->whereKey($this->location?->id ?? 0))
            ->when($search !== '', fn ($query) => $query->where(fn ($match) => $match
                ->where('business_name', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')))
            ->with(['services', 'locations', 'complianceRecords'])
            ->orderBy('business_name')
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * Every compliance requirement in force, read once for the whole list
     * (slice 12, step 12b).
     *
     * @return Collection<int, ComplianceRequirement>
     */
    #[Computed]
    public function requirements(): Collection
    {
        return ComplianceRequirement::query()->active()->ordered()->get();
    }

    public function updatedLocationId(): void
    {
        unset($this->location, $this->mayAdd, $this->subcontractors);
    }

    public function updatedSearch(): void
    {
        unset($this->subcontractors);
    }

    public function openAdd(): void
    {
        abort_unless($this->mayAdd, 403);

        $this->details = [
            'businessName' => '', 'contactName' => '', 'phone' => '', 'email' => '',
            'crewSize' => '', 'homeTown' => '', 'homeZip' => '',
            'locationIds' => [(string) $this->location?->id],
            'serviceIds' => [],
        ];
        $this->resetValidation();

        Flux::modal('add-subcontractor')->show();
    }

    public function add(ChangeSubcontractor $changeSubcontractor): void
    {
        abort_unless($this->managedLocations->isNotEmpty(), 403);
        $this->resetValidation();

        $subcontractor = $changeSubcontractor->add($this->details, Auth::user());

        Flux::modal('add-subcontractor')->close();
        Flux::toast(variant: 'success', text: __(':name was added as a prospect.', ['name' => $subcontractor->business_name]));

        $this->redirectRoute('production.subcontractors.show', $subcontractor, navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6" data-test="production-subcontractors">
    @php
        $location = $this->location;
        $grouped = $this->subcontractors->groupBy(fn (Subcontractor $subcontractor): string => $subcontractor->standing->group());
        $groups = [
            'roster' => [__('On the roster'), __('Active and benched. A benched subcontractor gets no new work and keeps their history.'), __('Nobody is on the roster here yet.')],
            'recruiting' => [__('Recruiting'), __('From prospect to onboarding sent.'), __('Nobody is being recruited here.')],
            'past' => [__('Past'), __('Offboarded, or not hired. Kept with their history.'), __('Nobody here has left or was not hired.')],
        ];
    @endphp

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Subcontractors') }}</flux:heading>
            <flux:subheading data-test="subcontractors-scope">
                {{ $location === null ? __('The crews who do the work.') : __('The crews who work at :location: on the roster, being recruited, and past.', ['location' => $location->name]) }}
            </flux:subheading>
        </div>

        @if ($this->mayAdd)
            <flux:button variant="primary" icon="plus" wire:click="openAdd" data-test="add-subcontractor">{{ __('Add a subcontractor') }}</flux:button>
        @endif
    </div>

    @if ($location === null)
        <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractors-none">
            <flux:text>{{ __('There are no subcontractors for you to see.') }}</flux:text>
        </div>
    @else
        <div class="flex flex-wrap items-end gap-4">
            @if ($this->locations->count() > 1)
                <flux:select wire:model.live="locationId" :label="__('Location')" class="max-w-xs" data-test="subcontractors-location">
                    @foreach ($this->locations as $option)
                        <flux:select.option :value="$option->id" wire:key="subcontractors-location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :label="__('Search by name')" class="max-w-xs" data-test="subcontractors-search" />
        </div>

        @if ($this->subcontractors->count() >= $this::LIMIT)
            <flux:callout icon="information-circle" data-test="subcontractors-limit">
                <flux:callout.text>{{ __('Only the first :count are shown. Search by name to find the rest.', ['count' => $this::LIMIT]) }}</flux:callout.text>
            </flux:callout>
        @endif

        @foreach ($groups as $key => [$heading, $about, $none])
            @php
                $members = $grouped->get($key, collect());
            @endphp

            <div wire:key="subcontractors-{{ $key }}" class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="subcontractors-{{ $key }}">
                <div>
                    <flux:heading size="lg" level="2">{{ $heading }} <span class="text-zinc-500">({{ $members->count() }})</span></flux:heading>
                    <flux:subheading>{{ $about }}</flux:subheading>
                </div>

                @if ($members->isEmpty())
                    <flux:text data-test="subcontractors-{{ $key }}-none">{{ trim($this->search) === '' ? $none : __('No one here matches that name.') }}</flux:text>
                @else
                    <ul class="flex flex-col divide-y divide-zinc-200">
                        @foreach ($members as $subcontractor)
                            <li wire:key="subcontractor-{{ $subcontractor->id }}" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0" data-test="subcontractor-row">
                                <div class="flex min-w-0 flex-1 flex-col">
                                    <a href="{{ route('production.subcontractors.show', $subcontractor) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $subcontractor->business_name }}</a>
                                    <span class="text-sm text-zinc-700">
                                        {{ $subcontractor->contact_name }}@if ($subcontractor->phoneForDisplay() !== null) &middot; {{ $subcontractor->phoneForDisplay() }}@endif
                                    </span>
                                    @php
                                        $facts = array_filter([
                                            $subcontractor->crew_size === null ? null : trans_choice('crew of :count|crew of :count', $subcontractor->crew_size),
                                            $subcontractor->services->pluck('name')->join(', ') ?: null,
                                            $subcontractor->homeBase(),
                                        ]);
                                    @endphp
                                    @if ($facts !== [])
                                        <span class="text-sm text-zinc-500">{{ implode(' · ', $facts) }}</span>
                                    @endif
                                </div>

                                <div class="flex shrink-0 flex-wrap gap-1">
                                    {{-- On the roster, whether they are compliant: worked out, never set (slice 12, step 12b). --}}
                                    @if ($key === 'roster')
                                        @php
                                            $compliance = Compliance::for($subcontractor, $this->requirements);
                                        @endphp
                                        @if (! $compliance->isCompliant())
                                            <flux:badge size="sm" color="rose" data-test="subcontractor-pending-compliance">{{ __('Pending compliance') }}</flux:badge>
                                        @elseif ($compliance->expiringSoon() !== [])
                                            <flux:badge size="sm" color="amber" data-test="subcontractor-expiring">{{ __('Expires soon') }}</flux:badge>
                                        @endif
                                    @endif
                                    @if ($subcontractor->is_vip)
                                        <flux:badge size="sm" color="yellow" data-test="subcontractor-vip">{{ __('VIP') }}</flux:badge>
                                    @endif
                                    <flux:badge size="sm" :color="$subcontractor->standing->color()" data-test="subcontractor-standing">{{ __($subcontractor->standing->label()) }}</flux:badge>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach

        @if ($this->managedLocations->isNotEmpty())
            <flux:modal name="add-subcontractor" class="w-full max-w-xl">
                <form wire:submit="add" class="flex flex-col gap-5">
                    <div>
                        <flux:heading size="lg">{{ __('Add a subcontractor') }}</flux:heading>
                        <flux:subheading>{{ __('They start as a prospect. Nothing is sent to them.') }}</flux:subheading>
                    </div>

                    <x-subcontractor.details-form :locations="$this->managedLocations" :services="$this->services" />

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>

                        <flux:button type="submit" variant="primary" data-test="save-subcontractor">{{ __('Add') }}</flux:button>
                    </div>
                </form>
            </flux:modal>
        @endif
    @endif
</section>
