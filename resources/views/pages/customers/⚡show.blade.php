<?php

use App\Actions\Customers\AddContactToAccount;
use App\Actions\Customers\ArchiveProperty;
use App\Actions\Customers\LinkContactToAccount;
use App\Actions\Customers\MakePrimaryContact;
use App\Actions\Customers\RemoveContactFromAccount;
use App\Actions\Customers\RestoreContactToAccount;
use App\Actions\Customers\SaveProperty;
use App\Actions\Customers\UpdateAccountContact;
use App\Enums\ContactRole;
use App\Enums\Permission;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Property;
use App\Support\Addresses\AddressCapturePage;
use App\Support\Addresses\StructuredAddress;
use App\Support\Customers\ContactDetails;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;

new #[Title('Customer')] class extends AddressCapturePage {
    /** Labels offered with one tap. Any other label can be typed. */
    public const array PROPERTY_LABELS = ['Home', 'Rental', 'Office', 'Shop', 'Other'];

    public Account $account;

    /** The connection being edited, or null while adding a new person. */
    #[Locked]
    public ?int $editingLinkId = null;

    public string $contactFirstName = '';

    public string $contactLastName = '';

    public string $contactPhone = '';

    public string $contactAlternatePhone = '';

    public string $contactEmail = '';

    /** @var list<string> */
    public array $contactRoles = [];

    public bool $contactPrimary = false;

    public string $contactSearch = '';

    /** The existing person chosen to be connected to this customer. */
    #[Locked]
    public ?int $linkingContactId = null;

    /** The property being edited, or null while adding one. */
    #[Locked]
    public ?int $editingPropertyId = null;

    public string $propertyLabel = '';

    public string $propertyType = '';

    /** Set by the server once the person has been asked about a second property at the same address. */
    #[Locked]
    public bool $sameAddressQuestioned = false;

    public bool $showRemoved = false;

    /**
     * Mount the component.
     */
    public function mount(Account $account): void
    {
        $this->authorize('view', $account);

        $this->account = $account;

        $this->startAddressCapture();
    }

    /*
    |--------------------------------------------------------------------------
    | Contacts
    |--------------------------------------------------------------------------
    */

    /**
     * Open the form for adding a new person to this customer.
     */
    public function addContact(): void
    {
        $this->authorize('update', $this->account);

        $this->resetContactForm();

        // The first person is usually the obvious one. After that, nothing is assumed.
        if ($this->contactLinks->isEmpty()) {
            $this->contactRoles = [($this->account->isCommercial() ? ContactRole::BusinessContact : ContactRole::Owner)->value];
        }

        Flux::modal('contact-form')->show();
    }

    /**
     * Open the form for correcting a person's details and what they are to this customer.
     */
    public function editContact(int $linkId): void
    {
        $this->authorize('update', $this->account);

        $link = $this->currentLink($linkId);

        $this->resetContactForm();

        $this->editingLinkId = $link->id;
        $this->contactFirstName = (string) $link->contact->first_name;
        $this->contactLastName = (string) $link->contact->last_name;
        $this->contactPhone = (string) $link->contact->phone;
        $this->contactAlternatePhone = (string) $link->contact->alternate_phone;
        $this->contactEmail = (string) $link->contact->email;
        $this->contactRoles = $link->roles->map(fn ($held): string => $held->role->value)->values()->all();

        Flux::modal('contact-form')->show();
    }

    /**
     * Save the person being added or corrected.
     */
    public function saveContact(AddContactToAccount $addContactToAccount, UpdateAccountContact $updateAccountContact): void
    {
        $this->authorize('update', $this->account);

        $this->validate([
            'contactFirstName' => ['nullable', 'string', 'max:255'],
            'contactLastName' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'contactAlternatePhone' => ['nullable', 'string', 'max:50'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'contactRoles' => ['array'],
            'contactRoles.*' => [Rule::enum(ContactRole::class)],
        ], attributes: [
            'contactFirstName' => 'first name',
            'contactLastName' => 'last name',
            'contactPhone' => 'phone',
            'contactAlternatePhone' => 'alternate phone',
            'contactEmail' => 'email',
        ]);

        $details = ContactDetails::fromInput([
            'first_name' => $this->contactFirstName,
            'last_name' => $this->contactLastName,
            'phone' => $this->contactPhone,
            'alternate_phone' => $this->contactAlternatePhone,
            'email' => $this->contactEmail,
        ]);

        if ($this->editingLinkId === null) {
            $addContactToAccount->handle($this->account, $details, $this->chosenRoles(), $this->contactPrimary);
        } else {
            $updateAccountContact->handle($this->currentLink($this->editingLinkId), $details, $this->chosenRoles());
        }

        $this->afterContactsChanged();

        Flux::modal('contact-form')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * Open the dialog for connecting someone who is already a contact of another customer.
     */
    public function openLinkContact(): void
    {
        $this->authorize('update', $this->account);

        $this->resetContactForm();

        Flux::modal('link-contact')->show();
    }

    /**
     * Choose one of the people found. Only someone this viewer already knows of can be chosen.
     */
    public function chooseContact(int $contactId): void
    {
        $this->authorize('update', $this->account);

        $this->linkingContactId = $this->linkableContacts()->findOrFail($contactId)->id;

        $this->resetValidation();
    }

    public function chooseAnotherContact(): void
    {
        $this->linkingContactId = null;
    }

    /**
     * Connect the chosen person to this customer. They remain one person, with
     * the same details, wherever they are a contact.
     */
    public function linkContact(LinkContactToAccount $linkContactToAccount): void
    {
        $this->authorize('update', $this->account);

        $this->validate(['contactRoles' => ['array'], 'contactRoles.*' => [Rule::enum(ContactRole::class)]]);

        // Checked again here: the browser only ever names someone this viewer may know of.
        $contact = $this->linkableContacts()->findOrFail((int) $this->linkingContactId);

        $linkContactToAccount->handle($this->account, $contact, $this->chosenRoles(), $this->contactPrimary);

        $this->afterContactsChanged();

        Flux::modal('link-contact')->close();
        Flux::toast(variant: 'success', text: __(':name is now a contact of this customer.', ['name' => $contact->fullName() ?? __('This person')]));
    }

    /**
     * Make one of the customer's people its primary contact.
     */
    public function makePrimary(int $linkId, MakePrimaryContact $makePrimaryContact): void
    {
        $this->authorize('update', $this->account);

        $makePrimaryContact->handle($this->currentLink($linkId));

        $this->afterContactsChanged();

        Flux::toast(variant: 'success', text: __('Primary contact changed.'));
    }

    /**
     * Remove a person from this customer. A company-wide management action.
     */
    public function removeContact(int $linkId, RemoveContactFromAccount $removeContactFromAccount): void
    {
        $this->authorize('archive', $this->account);

        $removeContactFromAccount->handle($this->currentLink($linkId), Auth::user());

        $this->afterContactsChanged();

        Flux::toast(variant: 'success', text: __('Removed from this customer.'));
    }

    /**
     * Bring back a person who was removed from this customer. A company-wide management action.
     */
    public function restoreContact(int $linkId, RestoreContactToAccount $restoreContactToAccount): void
    {
        $this->authorize('archive', $this->account);

        $link = $this->account->contactLinks()->whereNotNull('archived_at')->with(['contact', 'roles'])->findOrFail($linkId);

        $restoreContactToAccount->handle($link->setRelation('account', $this->account));

        $this->afterContactsChanged();

        Flux::toast(variant: 'success', text: __('Restored to this customer.'));
    }

    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    /**
     * Open the form for adding a property to this customer.
     */
    public function addProperty(): void
    {
        $this->authorize('update', $this->account);

        $this->resetPropertyForm();

        $this->propertyType = ($this->account->isCommercial() ? PropertyType::Commercial : PropertyType::Residential)->value;
        $this->fillAddress(null);

        Flux::modal('property-form')->show();
    }

    /**
     * Open the form for correcting a property.
     */
    public function editProperty(int $propertyId): void
    {
        $this->authorize('update', $this->account);

        $property = $this->currentProperty($propertyId);

        $this->resetPropertyForm();

        $this->editingPropertyId = $property->id;
        $this->propertyLabel = (string) $property->label;
        $this->propertyType = $property->type->value;
        $this->fillAddress(StructuredAddress::ofProperty($property));

        Flux::modal('property-form')->show();
    }

    public function saveProperty(SaveProperty $saveProperty): void
    {
        $this->storeProperty($saveProperty, acceptSameAddress: false);
    }

    /**
     * Save a property after being told the customer already has one at that
     * address. Only counts once the server has actually asked.
     */
    public function savePropertyAnyway(SaveProperty $saveProperty): void
    {
        $this->storeProperty($saveProperty, acceptSameAddress: $this->sameAddressQuestioned);
    }

    /**
     * Archive a property. A company-wide management action.
     */
    public function archiveProperty(int $propertyId, ArchiveProperty $archiveProperty): void
    {
        $this->authorize('archive', $this->account);

        $archiveProperty->archive($this->currentProperty($propertyId), Auth::user());

        unset($this->properties, $this->archivedProperties);

        Flux::toast(variant: 'success', text: __('Property archived.'));
    }

    /**
     * Restore an archived property. A company-wide management action.
     */
    public function restoreProperty(int $propertyId, ArchiveProperty $archiveProperty): void
    {
        $this->authorize('archive', $this->account);

        $property = $this->account->properties()->whereNotNull('archived_at')->findOrFail($propertyId);

        $archiveProperty->restore($property->setRelation('account', $this->account));

        unset($this->properties, $this->archivedProperties);

        Flux::toast(variant: 'success', text: __('Property restored.'));
    }

    /*
    |--------------------------------------------------------------------------
    | What the page shows
    |--------------------------------------------------------------------------
    */

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
     * People who were removed from this customer, for whoever may restore them.
     *
     * @return Collection<int, AccountContact>
     */
    #[Computed]
    public function removedLinks(): Collection
    {
        if (! $this->showsRemoved) {
            return new Collection;
        }

        return $this->account->contactLinks()->whereNotNull('archived_at')->with(['contact', 'roles'])->orderBy('id')->get();
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
     * People this viewer already knows of, who match what was typed and are not
     * yet a contact of this customer.
     *
     * Only contacts of customers the viewer can see are searched, so this can
     * never be used to find out who else exists.
     *
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function contactMatches(): Collection
    {
        $term = trim($this->contactSearch);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = fn (string $value): string => '%'.addcslashes(mb_strtolower($value), '%_\\').'%';
        $words = array_slice(preg_split('/\s+/', $term, flags: PREG_SPLIT_NO_EMPTY) ?: [], 0, 4);
        $digits = (string) preg_replace('/\D+/', '', $term);

        return $this->linkableContacts()
            ->where(function (Builder $contact) use ($like, $term, $words, $digits): void {
                $contact->where(function (Builder $name) use ($like, $words): void {
                    foreach ($words as $word) {
                        $name->where(fn (Builder $part) => $part
                            ->whereRaw('lower(first_name) like ?', [$like($word)])
                            ->orWhereRaw('lower(last_name) like ?', [$like($word)]));
                    }
                })->orWhere('email_normalized', 'like', $like($term));

                if (strlen($digits) >= 3) {
                    $contact
                        ->orWhere('phone_normalized', 'like', '%'.$digits.'%')
                        ->orWhere('alternate_phone_normalized', 'like', '%'.$digits.'%');
                }
            })
            ->with(['accountLinks' => fn ($links) => $links
                ->whereNull('archived_at')
                ->whereIn('account_id', $this->visibleAccountIds())
                ->with('account')])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(8)
            ->get();
    }

    #[Computed]
    public function linkingContact(): ?Contact
    {
        return $this->linkingContactId === null ? null : $this->linkableContacts()->find($this->linkingContactId);
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
     * @return Collection<int, Property>
     */
    #[Computed]
    public function archivedProperties(): Collection
    {
        if (! $this->showsRemoved) {
            return new Collection;
        }

        return $this->account->properties()->whereNotNull('archived_at')->orderBy('id')->get();
    }

    /**
     * Whether removed people and archived properties are being shown. Only
     * someone who may restore them can ask for this.
     */
    #[Computed]
    public function showsRemoved(): bool
    {
        return $this->showRemoved && Auth::user()->can('archive', $this->account);
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

    /**
     * Asking about the same address applies to the address it was asked about.
     */
    protected function propertyAddressChanged(): void
    {
        $this->sameAddressQuestioned = false;

        $this->resetValidation('sameAddress');
    }

    private function storeProperty(SaveProperty $saveProperty, bool $acceptSameAddress): void
    {
        $this->authorize('update', $this->account);

        $this->validate([
            'propertyLabel' => ['nullable', 'string', 'max:100'],
            'propertyType' => ['required', Rule::enum(PropertyType::class)],
            ...$this->addressRules(),
        ], attributes: [
            'propertyLabel' => 'label',
            'propertyType' => 'type',
            'addressLine1' => 'street address',
            'addressLine2' => 'unit',
            'addressCity' => 'city',
            'addressState' => 'state',
            'addressPostalCode' => 'ZIP code',
        ]);

        $type = PropertyType::from($this->propertyType);

        try {
            if ($this->editingPropertyId === null) {
                $saveProperty->add($this->account, $this->propertyLabel, $type, $this->structuredAddress(), $acceptSameAddress);
            } else {
                $saveProperty->update($this->currentProperty($this->editingPropertyId), $this->propertyLabel, $type, $this->structuredAddress(), $acceptSameAddress);
            }
        } catch (ValidationException $exception) {
            // Remember that the question was put, so answering "save anyway" is believed.
            $this->sameAddressQuestioned = array_key_exists('sameAddress', $exception->errors());

            throw $exception;
        }

        $this->sameAddressQuestioned = false;

        unset($this->properties, $this->archivedProperties);

        Flux::modal('property-form')->close();
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    /**
     * A current connection of this customer, and no other customer's.
     */
    private function currentLink(int $linkId): AccountContact
    {
        return $this->account->contactLinks()
            ->notArchived()
            ->with(['contact', 'roles'])
            ->findOrFail($linkId)
            ->setRelation('account', $this->account);
    }

    /**
     * A current property of this customer, and no other customer's.
     */
    private function currentProperty(int $propertyId): Property
    {
        return $this->account->properties()->notArchived()->findOrFail($propertyId)->setRelation('account', $this->account);
    }

    /**
     * The contacts of customers this viewer can see, who are not already a contact here.
     *
     * @return Builder<Contact>
     */
    private function linkableContacts(): Builder
    {
        return Contact::query()
            ->whereNull('archived_at')
            ->whereHas('accountLinks', fn (Builder $links) => $links->whereNull('archived_at')->whereIn('account_id', $this->visibleAccountIds()))
            ->whereDoesntHave('accountLinks', fn (Builder $links) => $links->whereNull('archived_at')->where('account_id', $this->account->id));
    }

    /**
     * @return Builder<Account>
     */
    private function visibleAccountIds(): Builder
    {
        return Account::query()->visibleTo(Auth::user())->notArchived()->select('id');
    }

    /**
     * @return list<ContactRole>
     */
    private function chosenRoles(): array
    {
        $roles = [];

        foreach ($this->contactRoles as $value) {
            $role = is_string($value) ? ContactRole::tryFrom($value) : null;

            if ($role !== null) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    private function resetContactForm(): void
    {
        $this->reset('editingLinkId', 'contactFirstName', 'contactLastName', 'contactPhone', 'contactAlternatePhone', 'contactEmail', 'contactRoles', 'contactPrimary', 'contactSearch', 'linkingContactId');

        $this->resetValidation();
    }

    private function resetPropertyForm(): void
    {
        $this->reset('editingPropertyId', 'propertyLabel', 'propertyType', 'sameAddressQuestioned');

        $this->resetValidation();
    }

    private function afterContactsChanged(): void
    {
        $this->account->refresh();

        unset($this->contactLinks, $this->removedLinks, $this->otherCustomers, $this->contactMatches, $this->linkingContact);

        $this->resetValidation();
    }
}; ?>

<section class="mx-auto flex w-full max-w-5xl flex-col gap-6">
    <div>
        <flux:link :href="route('customers')" wire:navigate class="text-sm">&larr; {{ __('Customers') }}</flux:link>
    </div>

    <div class="flex flex-wrap items-start justify-between gap-4">
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

        @can('archive', $account)
            <flux:checkbox wire:model.live="showRemoved" :label="__('Show removed contacts and archived properties')" data-test="show-removed" />
        @endcan
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
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Contacts') }}</flux:heading>
                    <flux:subheading>{{ __('The people at this customer, and what each is to it.') }}</flux:subheading>
                </div>

                @can('update', $account)
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" icon="plus" wire:click="addContact" data-test="add-contact">{{ __('Add contact') }}</flux:button>
                        <flux:button size="sm" wire:click="openLinkContact" data-test="link-contact">{{ __('Link existing') }}</flux:button>
                    </div>
                @endcan
            </div>

            @error('contact')
                <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-ink" data-test="contact-refused">{{ $message }}</div>
            @enderror

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

                            @can('update', $account)
                                <div class="mt-1 flex flex-wrap gap-2">
                                    <flux:button size="xs" wire:click="editContact({{ $link->id }})" data-test="edit-contact">{{ __('Edit') }}</flux:button>

                                    @unless ($link->is_primary)
                                        <flux:button size="xs" variant="ghost" wire:click="makePrimary({{ $link->id }})" data-test="make-primary">{{ __('Make primary') }}</flux:button>
                                    @endunless

                                    @can('archive', $account)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="removeContact({{ $link->id }})"
                                            wire:confirm="{{ __('Remove :name from this customer? They stay a contact of any other customer.', ['name' => $contact->fullName() ?? __('this person')]) }}"
                                            data-test="remove-contact"
                                        >
                                            {{ __('Remove') }}
                                        </flux:button>
                                    @endcan
                                </div>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->removedLinks->isNotEmpty())
                <div class="rounded-lg border border-dashed border-zinc-300 p-3" data-test="removed-contacts">
                    <div class="text-sm font-semibold text-ink">{{ __('Removed from this customer') }}</div>

                    <ul class="mt-2 flex flex-col gap-2">
                        @foreach ($this->removedLinks as $link)
                            <li wire:key="removed-{{ $link->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>
                                    {{ $link->contact->fullName() ?? __('No name given') }}
                                    @if ($link->roleLabels() !== [])
                                        <span class="text-zinc-500">({{ implode(', ', $link->roleLabels()) }})</span>
                                    @endif
                                </span>
                                <flux:button size="xs" wire:click="restoreContact({{ $link->id }})" data-test="restore-contact">{{ __('Restore') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="customer-properties">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <flux:heading size="lg" level="2">{{ __('Properties') }}</flux:heading>
                    <flux:subheading>{{ __('The places where work could be done for this customer.') }}</flux:subheading>
                </div>

                @can('update', $account)
                    <flux:button size="sm" variant="primary" icon="plus" wire:click="addProperty" data-test="add-property">{{ __('Add property') }}</flux:button>
                @endcan
            </div>

            @error('property')
                <div role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-ink" data-test="property-refused">{{ $message }}</div>
            @enderror

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

                            @can('update', $account)
                                <div class="mt-1 flex flex-wrap gap-2">
                                    <flux:button size="xs" wire:click="editProperty({{ $property->id }})" data-test="edit-property">{{ __('Edit') }}</flux:button>

                                    @can('archive', $account)
                                        <flux:button
                                            size="xs"
                                            variant="ghost"
                                            wire:click="archiveProperty({{ $property->id }})"
                                            wire:confirm="{{ __('Archive this property? It can be restored later.') }}"
                                            data-test="archive-property"
                                        >
                                            {{ __('Archive') }}
                                        </flux:button>
                                    @endcan
                                </div>
                            @endcan
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($this->archivedProperties->isNotEmpty())
                <div class="rounded-lg border border-dashed border-zinc-300 p-3" data-test="archived-properties">
                    <div class="text-sm font-semibold text-ink">{{ __('Archived properties') }}</div>

                    <ul class="mt-2 flex flex-col gap-2">
                        @foreach ($this->archivedProperties as $property)
                            <li wire:key="archived-property-{{ $property->id }}" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                                <span>{{ $property->summary() }}</span>
                                <flux:button size="xs" wire:click="restoreProperty({{ $property->id }})" data-test="restore-property">{{ __('Restore') }}</flux:button>
                            </li>
                        @endforeach
                    </ul>
                </div>
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

    @can('update', $account)
        <flux:modal name="contact-form" class="w-full max-w-xl">
            <form wire:submit="saveContact" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $editingLinkId === null ? __('Add contact') : __('Edit contact') }}</flux:heading>
                    <flux:subheading>
                        {{ __('These details belong to the person. If they are a contact of another customer, a change here applies there too.') }}
                    </flux:subheading>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="contactFirstName" :label="__('First name')" type="text" autocomplete="off" />
                    <flux:input wire:model="contactLastName" :label="__('Last name')" type="text" autocomplete="off" />
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input wire:model="contactPhone" :label="__('Phone')" type="tel" autocomplete="off" />
                    <flux:input wire:model="contactAlternatePhone" :label="__('Alternate phone')" type="tel" autocomplete="off" />
                </div>

                <flux:input wire:model="contactEmail" :label="__('Email')" type="email" autocomplete="off" />

                <fieldset class="flex flex-col gap-2" data-test="contact-roles">
                    <legend class="text-sm font-medium text-ink">
                        {{ __('What they are to this customer') }}
                        <span class="font-normal text-zinc-500">&middot; {{ __('choose every one that is true') }}</span>
                    </legend>

                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach (ContactRole::cases() as $role)
                            <flux:checkbox wire:model="contactRoles" :value="$role->value" :label="$role->label()" wire:key="role-{{ $role->value }}" />
                        @endforeach
                    </div>

                    <flux:error name="contactRoles" />
                </fieldset>

                @if ($editingLinkId === null && $this->contactLinks->isNotEmpty())
                    <flux:checkbox wire:model="contactPrimary" :label="__('Make this person the primary contact')" />
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-contact">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="link-contact" class="w-full max-w-xl">
            <form wire:submit="linkContact" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ __('Link an existing contact') }}</flux:heading>
                    <flux:subheading>
                        {{ __('For someone who is already a contact of another customer, such as a property manager. They stay one person, with one set of details.') }}
                    </flux:subheading>
                </div>

                @if ($this->linkingContact === null)
                    <flux:input
                        wire:model.live.debounce.300ms="contactSearch"
                        type="search"
                        icon="magnifying-glass"
                        :label="__('Find the person')"
                        :placeholder="__('Name, phone or email')"
                        autocomplete="off"
                        data-test="contact-search"
                    />

                    @if (mb_strlen(trim($contactSearch)) >= 2)
                        @if ($this->contactMatches->isEmpty())
                            <flux:text data-test="no-contact-matches">{{ __('Nobody you can see matches that. If they are new, use "Add contact" instead.') }}</flux:text>
                        @else
                            <ul class="overflow-hidden rounded-lg border border-zinc-300 bg-white" data-test="contact-matches">
                                @foreach ($this->contactMatches as $match)
                                    <li wire:key="match-{{ $match->id }}" class="border-b border-zinc-200 last:border-0">
                                        <button
                                            type="button"
                                            wire:click="chooseContact({{ $match->id }})"
                                            class="flex w-full flex-col items-start px-4 py-2 text-left text-sm hover:bg-cream focus-visible:bg-cream focus-visible:outline-none"
                                        >
                                            <span class="font-semibold text-ink">{{ $match->fullName() ?? __('No name given') }}</span>
                                            <span class="tabular-nums text-zinc-600">{{ implode(' · ', array_filter([$match->displayPhone(), $match->email])) }}</span>
                                            <span class="text-zinc-500">{{ __('Contact of:') }} {{ $match->accountLinks->map(fn ($held) => $held->account->display_name)->implode(', ') }}</span>
                                        </button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                @else
                    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-zinc-300 bg-white p-4" data-test="contact-chosen">
                        <div>
                            <div class="font-semibold text-ink">{{ $this->linkingContact->fullName() ?? __('No name given') }}</div>
                            <div class="text-sm tabular-nums text-zinc-600">{{ implode(' · ', array_filter([$this->linkingContact->displayPhone(), $this->linkingContact->email])) }}</div>
                        </div>

                        <flux:button size="sm" type="button" wire:click="chooseAnotherContact">{{ __('Change') }}</flux:button>
                    </div>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium text-ink">
                            {{ __('What they are to this customer') }}
                            <span class="font-normal text-zinc-500">&middot; {{ __('choose every one that is true') }}</span>
                        </legend>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (ContactRole::cases() as $role)
                                <flux:checkbox wire:model="contactRoles" :value="$role->value" :label="$role->label()" wire:key="link-role-{{ $role->value }}" />
                            @endforeach
                        </div>

                        <flux:error name="contactRoles" />
                    </fieldset>

                    @if ($this->contactLinks->isNotEmpty())
                        <flux:checkbox wire:model="contactPrimary" :label="__('Make this person the primary contact')" />
                    @endif
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    @if ($this->linkingContact !== null)
                        <flux:button type="submit" variant="primary" data-test="link-contact-submit">{{ __('Link to this customer') }}</flux:button>
                    @endif
                </div>
            </form>
        </flux:modal>

        <flux:modal name="property-form" class="w-full max-w-xl">
            <form wire:submit="saveProperty" class="flex flex-col gap-5">
                <div>
                    <flux:heading size="lg">{{ $editingPropertyId === null ? __('Add property') : __('Edit property') }}</flux:heading>
                    <flux:subheading>{{ __('A place where work could be done. Where it is does not decide which location handles it.') }}</flux:subheading>
                </div>

                <div class="flex flex-col gap-2">
                    <flux:input wire:model="propertyLabel" :label="__('Label')" :placeholder="__('What staff call it')" type="text" autocomplete="off" />

                    <div class="flex flex-wrap gap-2" data-test="property-labels">
                        @foreach ($this::PROPERTY_LABELS as $label)
                            <flux:button size="xs" type="button" wire:click="$set('propertyLabel', '{{ $label }}')" wire:key="label-{{ $label }}">{{ $label }}</flux:button>
                        @endforeach
                    </div>
                </div>

                <flux:select wire:model="propertyType" :label="__('Type')">
                    @foreach (PropertyType::cases() as $type)
                        <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                <x-lead.address-picker
                    :mode="$addressMode"
                    :suggestions="$addressSuggestions"
                    :selected="$selectedAddress"
                    :available="$this->addressLookupIsAvailable()"
                    :failed="$addressLookupFailed"
                    subject="property"
                />

                @error('sameAddress')
                    <div role="alert" class="flex flex-col gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-ink" data-test="same-address">
                        <div>{{ $message }}</div>
                        <div>
                            <flux:button size="sm" type="button" wire:click="savePropertyAnyway" data-test="save-property-anyway">{{ __('Save as a separate property') }}</flux:button>
                        </div>
                    </div>
                @enderror

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>

                    <flux:button type="submit" variant="primary" data-test="save-property">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan
</section>
