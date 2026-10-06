<?php

use App\Enums\AccountType;
use App\Enums\Permission;
use App\Models\Account;
use App\Models\Location;
use App\Support\Access\CurrentLocation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Customers')] class extends Component {
    use WithPagination;

    /** The location filter value that means "do not narrow by location". */
    public const string ANY_LOCATION = 'any';

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    /** Empty means "start from the location being worked in". */
    #[Url]
    public string $location = '';

    #[Url]
    public bool $archived = false;

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize(Permission::ViewAccounts);

        // With nothing asked for, start from the location being worked in, when the list can be narrowed to it.
        if ($this->location !== self::ANY_LOCATION && ! ctype_digit($this->location)) {
            $working = app(CurrentLocation::class)->context(Auth::user())?->location;

            $this->location = $working !== null && $this->locations->contains($working)
                ? (string) $working->id
                : self::ANY_LOCATION;
        }
    }

    /**
     * The locations the list can be narrowed to: the ones where this person can
     * see Leads, since "a customer at a location" means "with a Lead there".
     *
     * @return Collection<int, Location>
     */
    #[Computed]
    public function locations(): Collection
    {
        $user = Auth::user();

        return $user->hasPermissionAcrossAllLocations(Permission::ViewAccounts)
            ? $user->locationsWhere(Permission::ViewLeads)
            : $user->authorizedLocations()->only(Account::locationsGivingSightTo($user))->values();
    }

    /**
     * The location the list is narrowed to, or null for none.
     *
     * A customer has no location, so the location selected in the header only
     * sets where this filter starts. It never decides what the person may see.
     */
    #[Computed]
    public function filteredLocation(): ?Location
    {
        // Only a location this person can see Leads at is accepted. Anything else narrows nothing.
        return ctype_digit($this->location) ? $this->locations->find((int) $this->location) : null;
    }

    /**
     * Whether this person can see Leads anywhere. Someone who cannot is not shown a count of them.
     */
    #[Computed]
    public function showsLeads(): bool
    {
        return $this->locations->isNotEmpty();
    }

    /**
     * @return LengthAwarePaginator<int, Account>
     */
    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        $user = Auth::user();

        // Always start from what the person is allowed to see, before any search or filter.
        $query = Account::query()
            ->visibleTo($user)
            ->with([
                'contactLinks' => fn ($links) => $links->notArchived()->where('is_primary', true)->with('contact'),
                'properties' => fn ($properties) => $properties->notArchived()->orderBy('id'),
            ])
            // Only the Leads this person may see are ever counted.
            ->withCount(['leads as open_leads_count' => fn (Builder $leads) => $leads->visibleTo($user)->active()]);

        if (! $this->archived) {
            $query->notArchived();
        }

        if (AccountType::tryFrom($this->type) !== null) {
            $query->where('type', $this->type);
        }

        if ($this->filteredLocation !== null) {
            $locationId = $this->filteredLocation->id;

            $query->whereHas('leads', fn (Builder $leads) => $leads->where('location_id', $locationId));
        }

        $this->applySearch($query);

        return $query->orderBy('display_name')->orderBy('id')->paginate(25);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'type', 'location', 'archived'], true)) {
            $this->resetPage();
        }
    }

    /**
     * Match the search against the customer's name, its people, and its properties.
     *
     * @param  Builder<Account>  $query
     */
    private function applySearch(Builder $query): void
    {
        $term = trim($this->search);

        if ($term === '') {
            return;
        }

        $like = fn (string $value): string => '%'.addcslashes(mb_strtolower($value), '%_\\').'%';
        $whole = $like($term);
        $words = array_slice(preg_split('/\s+/', $term, flags: PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $digits = (string) preg_replace('/\D+/', '', $term);

        $query->where(function (Builder $query) use ($like, $whole, $words, $digits): void {
            $query
                ->whereRaw('lower(display_name) like ?', [$whole])
                ->orWhereRaw('lower(business_name) like ?', [$whole])
                ->orWhereHas('contactLinks', fn (Builder $links) => $links
                    ->whereNull('account_contacts.archived_at')
                    ->whereHas('contact', function (Builder $contact) use ($like, $whole, $words, $digits): void {
                        $contact->whereNull('archived_at')->where(function (Builder $contact) use ($like, $whole, $words, $digits): void {
                            // Every word typed must be part of the person's first or last name.
                            $contact->where(function (Builder $name) use ($like, $words): void {
                                foreach ($words as $word) {
                                    $name->where(fn (Builder $part) => $part
                                        ->whereRaw('lower(first_name) like ?', [$like($word)])
                                        ->orWhereRaw('lower(last_name) like ?', [$like($word)]));
                                }
                            })->orWhere('email_normalized', 'like', $whole);

                            if (strlen($digits) >= 3) {
                                $contact
                                    ->orWhere('phone_normalized', 'like', '%'.$digits.'%')
                                    ->orWhere('alternate_phone_normalized', 'like', '%'.$digits.'%');
                            }
                        });
                    }))
                ->orWhereHas('properties', fn (Builder $properties) => $properties
                    ->whereNull('archived_at')
                    ->where(fn (Builder $address) => $address
                        ->whereRaw('lower(address) like ?', [$whole])
                        ->orWhere('address_postal_code', 'like', $whole)));
        });
    }
}; ?>

<section class="mx-auto flex w-full max-w-6xl flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Customers') }}</flux:heading>
            <flux:subheading data-test="customers-scope">
                @if ($this->filteredLocation !== null)
                    {{ __('Customers with a lead at :location.', ['location' => $this->filteredLocation->name]) }}
                @else
                    {{ __('Every customer you can see.') }}
                @endif
            </flux:subheading>
        </div>

        {{-- A customer with no lead is seen by company-wide staff alone, so only they may create one here. --}}
        @can('create', Account::class)
            <flux:button variant="primary" icon="plus" :href="route('customers.create')" wire:navigate data-test="new-customer-button">
                {{ __('New customer') }}
            </flux:button>
        @endcan
    </div>

    <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
        <div class="flex-1">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                icon="magnifying-glass"
                :label="__('Search')"
                :placeholder="__('Name, business, phone, email or address')"
                autocomplete="off"
            />
        </div>

        <div class="lg:w-44">
            <flux:select wire:model.live="type" :label="__('Type')">
                <flux:select.option value="">{{ __('Any type') }}</flux:select.option>
                @foreach (AccountType::cases() as $option)
                    <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->locations->isNotEmpty())
            <div class="lg:w-52">
                <flux:select wire:model.live="location" :label="__('With a lead at')" data-test="customers-location-filter">
                    <flux:select.option :value="$this::ANY_LOCATION">{{ __('Any location') }}</flux:select.option>
                    @foreach ($this->locations as $option)
                        <flux:select.option :value="$option->id" wire:key="location-{{ $option->id }}">{{ $option->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        @endif

        <div class="lg:pb-2">
            <flux:checkbox wire:model.live="archived" :label="__('Show archived')" />
        </div>
    </div>

    @if ($this->customers->isEmpty())
        <div class="flex flex-col items-center gap-3 rounded-xl border border-dashed border-zinc-300 bg-card px-6 py-12 text-center" data-test="no-customers">
            <x-app-logo-icon class="h-12 w-auto" />
            <flux:heading size="lg">{{ __('No customers here') }}</flux:heading>
            <flux:text class="max-w-md">
                @if (trim($search) !== '' || $type !== '')
                    {{ __('Nothing matches that search. Try fewer words, or clear the filters.') }}
                @elseif ($this->filteredLocation !== null)
                    {{ __('No customer you can see has a lead at :location. Choose "Any location" to look further.', ['location' => $this->filteredLocation->name]) }}
                @else
                    {{ __('A customer appears here once a lead you can see has been linked to them.') }}
                @endif
            </flux:text>
        </div>
    @else
        {{-- Wide screens: a table. --}}
        <div class="hidden overflow-hidden rounded-xl border border-zinc-200 bg-card px-4 md:block">
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Customer') }}</flux:table.column>
                    <flux:table.column>{{ __('Primary contact') }}</flux:table.column>
                    <flux:table.column>{{ __('Phone and email') }}</flux:table.column>
                    <flux:table.column>{{ __('Properties') }}</flux:table.column>
                    @if ($this->showsLeads)
                        <flux:table.column>{{ __('Open leads') }}</flux:table.column>
                    @endif
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($this->customers as $customer)
                        @php
                            $primary = $customer->primaryContact();
                            $firstProperty = $customer->properties->first();
                        @endphp

                        <flux:table.row :key="'row-'.$customer->id">
                            <flux:table.cell variant="strong">
                                <a href="{{ route('customers.show', $customer) }}" wire:navigate class="underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">
                                    {{ $customer->display_name }}
                                </a>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    <x-account-type :type="$customer->type" />
                                    @if ($customer->isArchived())
                                        <flux:badge size="sm" color="amber">{{ __('Archived') }}</flux:badge>
                                    @endif
                                </div>
                            </flux:table.cell>

                            <flux:table.cell>{{ $primary?->fullName() ?? '—' }}</flux:table.cell>

                            <flux:table.cell class="tabular-nums">
                                <div>{{ $primary?->displayPhone() ?? '—' }}</div>
                                <div class="text-sm text-zinc-500">{{ $primary?->email }}</div>
                            </flux:table.cell>

                            <flux:table.cell>
                                @if ($firstProperty)
                                    {{-- The label and street are enough to tell properties apart. The city and ZIP code are on the customer's page. --}}
                                    <div class="max-w-56 truncate" title="{{ $firstProperty->address }}" data-test="customer-property-summary">{{ $firstProperty->summary() }}</div>
                                    @if ($customer->properties->count() > 1)
                                        <div class="text-sm text-zinc-500">+{{ $customer->properties->count() - 1 }} {{ __('more') }}</div>
                                    @endif
                                @else
                                    —
                                @endif
                            </flux:table.cell>

                            @if ($this->showsLeads)
                                <flux:table.cell class="tabular-nums"><span data-test="open-leads">{{ $customer->getAttribute('open_leads_count') }}</span></flux:table.cell>
                            @endif
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </div>

        {{-- Narrow screens: one card per customer, no sideways scrolling. --}}
        <ul class="flex flex-col gap-3 md:hidden">
            @foreach ($this->customers as $customer)
                @php
                    $primary = $customer->primaryContact();
                @endphp

                <li wire:key="card-{{ $customer->id }}" class="rounded-xl border border-zinc-200 bg-card p-4">
                    <div class="flex items-start justify-between gap-3">
                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2">
                            {{ $customer->display_name }}
                        </a>
                        <x-account-type :type="$customer->type" />
                    </div>

                    <dl class="mt-2 flex flex-col gap-1 text-sm text-zinc-600">
                        @if ($customer->isCommercial() && $primary?->fullName())
                            <div>{{ $primary->fullName() }}</div>
                        @endif
                        @if ($primary?->phone !== null)
                            <div><a href="tel:{{ $primary->phone_normalized ?? $primary->phone }}" class="tabular-nums text-wall-teal underline">{{ $primary->displayPhone() }}</a></div>
                        @endif
                        @if ($primary?->email !== null)
                            <div class="break-all">{{ $primary->email }}</div>
                        @endif
                        @if ($customer->properties->isNotEmpty())
                            <div>
                                {{ $customer->properties->first()->summary() }}
                                @if ($customer->properties->count() > 1)
                                    <span class="text-zinc-500">+{{ $customer->properties->count() - 1 }} {{ __('more') }}</span>
                                @endif
                            </div>
                        @endif
                        @if ($customer->isArchived())
                            <div>{{ __('Archived') }}</div>
                        @endif
                    </dl>
                </li>
            @endforeach
        </ul>

        <flux:pagination :paginator="$this->customers" />
    @endif
</section>
