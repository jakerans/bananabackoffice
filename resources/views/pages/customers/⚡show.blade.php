<?php

use App\Enums\Permission;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Lead;
use App\Models\Property;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Customer')] class extends Component {
    public Account $account;

    /**
     * Mount the component.
     */
    public function mount(Account $account): void
    {
        $this->authorize('view', $account);

        $this->account = $account;
    }

    /**
     * The customer's people, the primary Contact first.
     *
     * @return Collection<int, AccountContact>
     */
    #[Computed]
    public function contactLinks(): Collection
    {
        return $this->account->contactLinks()
            ->notArchived()
            ->whereHas('contact', fn ($contact) => $contact->whereNull('archived_at'))
            ->with(['contact', 'roles'])
            ->get()
            ->sortBy(fn (AccountContact $link): array => [$link->is_primary ? 0 : 1, mb_strtolower((string) $link->contact->fullName()), $link->id])
            ->values();
    }

    /**
     * For each person here, the other customers they are a contact of, limited
     * to the customers this viewer may see. Whether they have any others is
     * never shown or hinted at.
     *
     * @return SupportCollection<int, Collection<int, AccountContact>>
     */
    #[Computed]
    public function otherCustomers(): SupportCollection
    {
        return AccountContact::query()
            ->notArchived()
            ->whereIn('contact_id', $this->contactLinks->pluck('contact_id'))
            ->where('account_id', '!=', $this->account->id)
            ->whereIn('account_id', Account::query()->visibleTo(Auth::user())->notArchived()->select('id'))
            ->with(['account', 'roles'])
            ->get()
            ->sortBy(fn (AccountContact $link): string => mb_strtolower($link->account->display_name))
            ->groupBy('contact_id');
    }

    /**
     * @return Collection<int, Property>
     */
    #[Computed]
    public function properties(): Collection
    {
        return $this->account->properties()->notArchived()->orderBy('id')->get();
    }

    /**
     * Whether this person can see Leads anywhere. Someone who cannot is not shown the Leads panel at all.
     */
    #[Computed]
    public function showsLeads(): bool
    {
        return Auth::user()->locationsWhere(Permission::ViewLeads)->isNotEmpty();
    }

    /**
     * The customer's Leads that this viewer may see, newest first.
     *
     * A customer can be shared between locations. Leads at a location the viewer
     * cannot work in are not listed, not counted and not hinted at.
     *
     * @return Collection<int, Lead>
     */
    #[Computed]
    public function leads(): Collection
    {
        return $this->account->leads()
            ->visibleTo(Auth::user())
            ->with(['location', 'assignee', 'services', 'projectSize'])
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->get();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:link :href="route('customers')" wire:navigate class="text-sm">&larr; {{ __('Customers') }}</flux:link>
    </div>

    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-3">
            <flux:heading size="xl" level="1">{{ $account->display_name }}</flux:heading>
            <x-account-type :type="$account->type" data-test="customer-type" />
        </div>

        <flux:subheading>
            @if ($account->isCommercial())
                {{ __('A business. Its people and properties are below.') }}
            @else
                {{ __('A household, named after its primary contact.') }}
            @endif
        </flux:subheading>
    </div>

    @if ($account->isArchived())
        <div role="status" class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-ink" data-test="customer-archived">
            <div class="font-semibold">{{ __('This customer is archived') }}</div>
            <div>
                {{ __('Archived') }} <x-local-time :at="$account->archived_at" />.
                {{ __('It is hidden from lists and choices unless archived customers are shown.') }}
            </div>
        </div>
    @endif

    <div class="grid gap-6 md:grid-cols-2">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-contacts">
            <div>
                <flux:heading size="lg" level="2">{{ __('Contacts') }}</flux:heading>
                <flux:subheading>{{ __('The people at this customer, and what each is to it.') }}</flux:subheading>
            </div>

            @if ($this->contactLinks->isEmpty())
                <flux:text data-test="no-contacts">{{ __('No contacts yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->contactLinks as $link)
                        @php
                            $contact = $link->contact;
                        @endphp

                        <li wire:key="contact-{{ $link->id }}" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0" data-test="customer-contact">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink">{{ $contact->fullName() ?? __('No name given') }}</span>
                                @if ($link->is_primary)
                                    <flux:badge size="sm" color="amber">{{ __('Primary') }}</flux:badge>
                                @endif
                            </div>

                            @if ($link->roleLabels() !== [])
                                <div class="text-sm text-zinc-600">{{ implode(', ', $link->roleLabels()) }}</div>
                            @endif

                            <div class="text-sm tabular-nums">
                                @if ($contact->phone !== null)
                                    <div><a href="tel:{{ $contact->phone_normalized ?? $contact->phone }}" class="text-wall-teal underline">{{ $contact->displayPhone() }}</a></div>
                                @endif
                                @if ($contact->alternate_phone !== null)
                                    <div>
                                        <a href="tel:{{ $contact->alternate_phone_normalized ?? $contact->alternate_phone }}" class="text-wall-teal underline">{{ $contact->displayAlternatePhone() }}</a>
                                        <span class="text-zinc-500">({{ __('alternate') }})</span>
                                    </div>
                                @endif
                                @if ($contact->email !== null)
                                    <div class="break-all"><a href="mailto:{{ $contact->email }}" class="text-wall-teal underline">{{ $contact->email }}</a></div>
                                @endif
                            </div>

                            @if ($this->otherCustomers->has($contact->id))
                                <div class="text-sm text-zinc-600" data-test="contact-other-customers">
                                    {{ __('Also a contact of:') }}
                                    @foreach ($this->otherCustomers->get($contact->id) as $other)
                                        <a href="{{ route('customers.show', $other->account) }}" wire:navigate class="underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">{{ $other->account->display_name }}</a>@if ($other->roleLabels() !== []) ({{ implode(', ', $other->roleLabels()) }})@endif{{ $loop->last ? '' : ', ' }}
                                    @endforeach
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-properties">
            <div>
                <flux:heading size="lg" level="2">{{ __('Properties') }}</flux:heading>
                <flux:subheading>{{ __('The places where work could be done for this customer.') }}</flux:subheading>
            </div>

            @if ($this->properties->isEmpty())
                <flux:text data-test="no-properties">{{ __('No properties yet.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->properties as $property)
                        <li wire:key="property-{{ $property->id }}" class="flex flex-col gap-1 py-4 first:pt-0 last:pb-0" data-test="customer-property">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-ink">{{ $property->label ?? __('Property') }}</span>
                                <flux:badge size="sm" color="zinc">{{ $property->type->label() }}</flux:badge>
                            </div>

                            <div class="text-ink">{{ $property->address }}</div>

                            <div class="text-sm text-zinc-500">
                                @if ($property->wasLookedUp())
                                    {{ __('Found by address lookup') }}
                                @else
                                    {{ __('Typed by hand, so it has no map position') }}
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($this->showsLeads)
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-leads">
            <div>
                <flux:heading size="lg" level="2">{{ __('Leads') }}</flux:heading>
                <flux:subheading>{{ __('Inquiries from this customer that you can open.') }}</flux:subheading>
            </div>

            @if ($this->leads->isEmpty())
                <flux:text data-test="no-leads">{{ __('No leads to show.') }}</flux:text>
            @else
                <ul class="flex flex-col divide-y divide-zinc-200">
                    @foreach ($this->leads as $lead)
                        <li wire:key="lead-{{ $lead->id }}" class="flex flex-wrap items-center justify-between gap-x-6 gap-y-1 py-3 first:pt-0 last:pb-0" data-test="customer-lead">
                            <div class="flex flex-col">
                                <a href="{{ route('leads.show', $lead) }}" wire:navigate class="font-semibold text-ink underline decoration-zinc-300 underline-offset-2 hover:decoration-ink">
                                    {{ $lead->primaryService()?->name ?? __('Lead') }}
                                    @if ($lead->services->count() > 1)
                                        <span class="text-sm font-normal text-zinc-500">+{{ $lead->services->count() - 1 }}</span>
                                    @endif
                                </a>
                                <span class="text-sm text-zinc-600">
                                    {{ __('Received') }} <x-local-time :at="$lead->received_at" />
                                    &middot;
                                    {{ $lead->projectSize?->name ?? __('Not classified') }}
                                </span>
                            </div>

                            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-zinc-600">
                                <span>{{ $lead->location?->name ?? __('Unrouted') }}</span>
                                <span>{{ $lead->assignee?->name ?? __('Unassigned') }}</span>
                                <x-lead-status :status="$lead->status" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</section>
