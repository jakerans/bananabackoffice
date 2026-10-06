<?php

use App\Actions\Customers\SaveProperty;
use App\Actions\Leads\ResolveLeadCustomer;
use App\Enums\AccountType;
use App\Enums\ContactRole;
use App\Enums\PropertyType;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Contact;
use App\Models\Lead;
use App\Models\Property;
use App\Support\Addresses\StructuredAddress;
use App\Support\Customers\ContactDetails;
use App\Support\Customers\CustomerMatch;
use App\Support\Customers\CustomerResolution;
use App\Support\Customers\InquiryName;
use App\Support\Customers\LeadCustomerMatcher;
use App\Support\Customers\MaskedCustomerMatch;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Resolve customer')] class extends Component {
    public const string NEW = 'new';

    public const string MASKED = 'masked';

    public const string NONE = 'none';

    public Lead $lead;

    /** What the Lead was linked to when this page was opened, so an out-of-date page saves nothing. */
    #[Locked]
    public ?int $knownAccountId = null;

    #[Locked]
    public ?int $knownContactId = null;

    #[Locked]
    public ?int $knownPropertyId = null;

    /** "account:12", "masked", "new", or empty while nothing is chosen. */
    public string $customerChoice = '';

    public string $customerSearch = '';

    public string $newType = '';

    public string $newBusinessName = '';

    /** "contact:5" (already at the customer), "link:5" (the same person, known elsewhere), "new" or "none". */
    public string $contactChoice = '';

    public string $contactFirstName = '';

    public string $contactLastName = '';

    public string $contactPhone = '';

    public string $contactEmail = '';

    /** @var list<string> */
    public array $contactRoles = [];

    /** "property:9", "new" (from the Lead's address) or "none". */
    public string $propertyChoice = '';

    public string $propertyLabel = '';

    public string $propertyType = '';

    /**
     * Mount the component.
     */
    public function mount(Lead $lead): void
    {
        $this->authorize('resolveCustomer', $lead);

        $this->lead = $lead;

        $this->knownAccountId = $lead->account_id;
        $this->knownContactId = $lead->contact_id;
        $this->knownPropertyId = $lead->property_id;

        $this->prefillFromInquiry();

        // Start from the current link, or from the one obvious match. Anything less clear is left for the person.
        if ($lead->account_id !== null && $this->chosenAccount($lead->account_id) !== null) {
            $this->customerChoice = 'account:'.$lead->account_id;
            $this->contactChoice = $lead->contact_id === null ? self::NONE : 'contact:'.$lead->contact_id;
            $this->propertyChoice = $lead->property_id === null ? self::NONE : 'property:'.$lead->property_id;
        } elseif (count($this->matches) === 1 && $this->maskedMatch === null) {
            $this->chooseCustomer('account:'.$this->matches[0]->account->id);
        } elseif ($this->matches === [] && $this->maskedMatch === null) {
            $this->chooseCustomer(self::NEW);
        }
    }

    /**
     * Customers this person can see that the Lead may be from.
     *
     * @return list<CustomerMatch>
     */
    #[Computed]
    public function matches(): array
    {
        return app(LeadCustomerMatcher::class)->visible($this->lead, Auth::user());
    }

    /**
     * A customer outside this person's sight whose contact has exactly the
     * Lead's phone or email. Only its kind and a masked value are ever shown.
     */
    #[Computed]
    public function maskedMatch(): ?MaskedCustomerMatch
    {
        return app(LeadCustomerMatcher::class)->masked($this->lead, Auth::user());
    }

    /**
     * Customers found by searching. Only customers this person can already see are searched.
     *
     * @return Collection<int, Account>
     */
    #[Computed]
    public function searchResults(): Collection
    {
        $term = trim($this->customerSearch);

        if (mb_strlen($term) < 2) {
            return new Collection;
        }

        $like = '%'.addcslashes(mb_strtolower($term), '%_\\').'%';
        $digits = (string) preg_replace('/\D+/', '', $term);

        return Account::query()
            ->visibleTo(Auth::user())
            ->notArchived()
            ->where(function (Builder $query) use ($like, $digits): void {
                $query
                    ->whereRaw('lower(display_name) like ?', [$like])
                    ->orWhereRaw('lower(business_name) like ?', [$like])
                    ->orWhereHas('contactLinks', fn (Builder $links) => $links
                        ->whereNull('account_contacts.archived_at')
                        ->whereHas('contact', function (Builder $contact) use ($like, $digits): void {
                            $contact->whereNull('archived_at')->where(function (Builder $contact) use ($like, $digits): void {
                                $contact
                                    ->whereRaw('lower(first_name) like ?', [$like])
                                    ->orWhereRaw('lower(last_name) like ?', [$like])
                                    ->orWhere('email_normalized', 'like', $like);

                                if (strlen($digits) >= 3) {
                                    $contact->orWhere('phone_normalized', 'like', '%'.$digits.'%');
                                }
                            });
                        }));
            })
            ->orderBy('display_name')
            ->limit(6)
            ->get();
    }

    /**
     * The existing customer that is chosen, if one is.
     */
    #[Computed]
    public function account(): ?Account
    {
        return str_starts_with($this->customerChoice, 'account:')
            ? $this->chosenAccount((int) substr($this->customerChoice, 8))
            : null;
    }

    /**
     * The chosen customer's current people, the one whose phone or email matched first.
     *
     * @return Collection<int, AccountContact>
     */
    #[Computed]
    public function accountContacts(): Collection
    {
        if ($this->account === null) {
            return new Collection;
        }

        $matched = $this->matchedContactIds;

        return $this->account->contactLinks()
            ->notArchived()
            ->whereHas('contact', fn ($contact) => $contact->whereNull('archived_at'))
            ->with(['contact', 'roles'])
            ->get()
            ->sortBy(fn (AccountContact $link): array => [in_array($link->contact_id, $matched, true) ? 0 : 1, $link->is_primary ? 0 : 1, $link->id])
            ->values();
    }

    /**
     * People this person already knows of, with exactly the Lead's phone or
     * email, who are not yet a contact of the chosen customer. Offered so the
     * same person is connected rather than entered a second time.
     *
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function samePeople(): Collection
    {
        if ($this->customerChoice === '' || $this->customerChoice === self::MASKED) {
            return new Collection;
        }

        $alreadyThere = $this->accountContacts->pluck('contact_id')->all();
        $visibleAccounts = Account::query()->visibleTo(Auth::user())->notArchived()->select('id');

        return app(LeadCustomerMatcher::class)
            ->visibleContacts($this->lead, Auth::user())
            ->reject(fn (Contact $contact): bool => in_array($contact->id, $alreadyThere, true))
            ->each(fn (Contact $contact) => $contact->setRelation('accountLinks', $contact->accountLinks()->whereNull('archived_at')->whereIn('account_id', $visibleAccounts)->with('account')->get()))
            ->values();
    }

    /**
     * @return list<int>
     */
    #[Computed]
    public function matchedContactIds(): array
    {
        return app(LeadCustomerMatcher::class)->visibleContacts($this->lead, Auth::user())->modelKeys();
    }

    /**
     * The chosen customer's current properties.
     *
     * @return Collection<int, Property>
     */
    #[Computed]
    public function accountProperties(): Collection
    {
        return $this->account === null
            ? new Collection
            : $this->account->properties()->notArchived()->orderBy('id')->get();
    }

    /**
     * The chosen customer's property at the Lead's address, if it has one.
     */
    #[Computed]
    public function matchedProperty(): ?Property
    {
        $address = StructuredAddress::ofLead($this->lead);

        if ($this->account === null || $address === null) {
            return null;
        }

        $match = SaveProperty::sameAddressAt($this->account, $address);

        return $match !== null && ! $match->isArchived() ? $match : null;
    }

    #[Computed]
    public function leadHasAddress(): bool
    {
        return $this->lead->inquiry_address !== null;
    }

    /**
     * Whether a new person or property would be added to an existing customer,
     * which needs permission to change that customer.
     */
    #[Computed]
    public function canAddToAccount(): bool
    {
        return $this->account !== null && Auth::user()->can('update', $this->account);
    }

    /**
     * Choose the customer, and start the person and the place from what fits it best.
     */
    public function chooseCustomer(string $choice): void
    {
        $this->customerChoice = $choice;

        unset($this->account, $this->accountContacts, $this->accountProperties, $this->matchedProperty, $this->samePeople, $this->canAddToAccount);

        $this->resetValidation();
        $this->prefillFromInquiry();

        if ($choice === self::MASKED) {
            $this->contactChoice = '';
            $this->propertyChoice = self::NONE;

            return;
        }

        if ($choice === self::NEW) {
            $this->newType = (trim((string) $this->lead->inquiry_business_name) === '' ? AccountType::Residential : AccountType::Commercial)->value;
            $this->newBusinessName = (string) $this->lead->inquiry_business_name;
            $this->updatedNewType();
            $this->contactChoice = $this->samePeople->count() === 1 ? 'link:'.$this->samePeople[0]->id : self::NEW;
            $this->propertyChoice = $this->leadHasAddress ? self::NEW : self::NONE;

            return;
        }

        if ($this->account === null) {
            $this->customerChoice = '';
            $this->contactChoice = '';
            $this->propertyChoice = '';

            return;
        }

        $matchedHere = $this->accountContacts->first(fn (AccountContact $link): bool => in_array($link->contact_id, $this->matchedContactIds, true));

        $this->contactChoice = match (true) {
            $matchedHere !== null => 'contact:'.$matchedHere->contact_id,
            $this->accountContacts->count() === 1 => 'contact:'.$this->accountContacts[0]->contact_id,
            default => '',
        };
        $this->contactRoles = [($this->account->isCommercial() ? ContactRole::BusinessContact : ContactRole::Owner)->value];
        $this->propertyType = ($this->account->isCommercial() ? PropertyType::Commercial : PropertyType::Residential)->value;
        $this->propertyLabel = $this->account->isCommercial() ? 'Office' : 'Home';

        $this->propertyChoice = match (true) {
            $this->matchedProperty !== null => 'property:'.$this->matchedProperty->id,
            ! $this->leadHasAddress && $this->accountProperties->count() === 1 => 'property:'.$this->accountProperties[0]->id,
            default => '',
        };
    }

    /**
     * Start the new customer's first person and property from the usual choices for its kind.
     */
    public function updatedNewType(): void
    {
        $commercial = $this->newType === AccountType::Commercial->value;

        $this->contactRoles = [($commercial ? ContactRole::BusinessContact : ContactRole::Owner)->value];
        $this->propertyType = ($commercial ? PropertyType::Commercial : PropertyType::Residential)->value;
        $this->propertyLabel = $commercial ? 'Office' : 'Home';
    }

    /**
     * Save the decision. Everything is made and linked together, or nothing is.
     */
    public function save(ResolveLeadCustomer $resolveLeadCustomer): void
    {
        $this->authorize('resolveCustomer', $this->lead);

        if ($this->lead->account_id !== $this->knownAccountId || $this->lead->contact_id !== $this->knownContactId || $this->lead->property_id !== $this->knownPropertyId) {
            $this->addError('customerChoice', __('This lead\'s customer was just changed by someone else, so nothing was saved. Go back to the lead and check it.'));

            return;
        }

        $this->validate([
            'customerChoice' => ['required', 'string'],
            'newType' => [Rule::requiredIf($this->customerChoice === self::NEW), 'nullable', Rule::enum(AccountType::class)],
            'newBusinessName' => ['nullable', 'string', 'max:255'],
            'contactFirstName' => ['nullable', 'string', 'max:255'],
            'contactLastName' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'contactRoles' => ['array'],
            'contactRoles.*' => [Rule::enum(ContactRole::class)],
            'propertyLabel' => ['nullable', 'string', 'max:100'],
            'propertyType' => ['nullable', Rule::enum(PropertyType::class)],
        ], [
            'customerChoice.required' => __('Choose the customer this lead is from, or choose "New customer".'),
        ], [
            'newBusinessName' => 'business name',
            'contactFirstName' => 'first name',
            'contactLastName' => 'last name',
            'contactPhone' => 'phone',
            'contactEmail' => 'email',
            'propertyLabel' => 'label',
        ]);

        $resolveLeadCustomer->handle($this->lead, $this->resolution());

        Flux::toast(variant: 'success', text: __('Linked to :customer.', ['customer' => $this->lead->account?->display_name]));

        $this->redirectRoute('leads.show', $this->lead, navigate: true);
    }

    /**
     * Turn what is chosen on the page into a decision, checking every choice
     * against what this person may actually use.
     */
    private function resolution(): CustomerResolution
    {
        // A customer outside this person's sight is never named by the browser: it is worked out again here.
        if ($this->customerChoice === self::MASKED) {
            $masked = $this->maskedMatch ?? abort(403);

            return new CustomerResolution(account: $masked->account, contact: $masked->contact, outsideView: true);
        }

        $roles = array_values(array_filter(array_map(fn (mixed $role) => is_string($role) ? ContactRole::tryFrom($role) : null, $this->contactRoles)));
        $newContact = ContactDetails::fromInput([
            'first_name' => $this->contactFirstName,
            'last_name' => $this->contactLastName,
            'phone' => $this->contactPhone,
            'email' => $this->contactEmail,
        ]);
        $propertyType = PropertyType::tryFrom($this->propertyType);

        // An existing customer must be one this person can see. Anything else is refused as if it did not exist.
        if ($this->customerChoice !== self::NEW && $this->account === null) {
            abort(404);
        }

        if ($this->contactChoice === '') {
            $this->failWith('contactChoice', __('Choose the person this lead is from.'));
        }

        $isBusiness = $this->customerChoice === self::NEW ? $this->newType === AccountType::Commercial->value : (bool) $this->account?->isCommercial();

        // Only a business may be linked with nobody named yet.
        if ($this->contactChoice === self::NONE && ! $isBusiness) {
            $this->failWith('contactChoice', __('A household needs a contact. Choose or add the person this lead is from.'));
        }

        if ($this->propertyChoice === '') {
            $this->failWith('propertyChoice', __('Choose the property this lead is about, or "No property yet".'));
        }

        // Someone known elsewhere with exactly the Lead's phone or email, to be connected rather than re-entered.
        $samePerson = str_starts_with($this->contactChoice, 'link:')
            ? ($this->samePeople->firstWhere('id', (int) substr($this->contactChoice, 5)) ?? abort(404))
            : null;

        if ($this->customerChoice === self::NEW) {
            return new CustomerResolution(
                newType: AccountType::from($this->newType),
                newBusinessName: $this->newBusinessName,
                contact: $samePerson,
                newContact: $this->contactChoice === self::NEW ? $newContact : null,
                roles: $roles,
                createProperty: $this->propertyChoice === self::NEW,
                propertyLabel: $this->propertyLabel,
                propertyType: $propertyType,
            );
        }

        $account = $this->account ?? abort(404);

        $existingContact = str_starts_with($this->contactChoice, 'contact:')
            ? ($this->accountContacts->firstWhere('contact_id', (int) substr($this->contactChoice, 8))?->contact ?? abort(404))
            : null;

        $existingProperty = str_starts_with($this->propertyChoice, 'property:')
            ? ($this->accountProperties->firstWhere('id', (int) substr($this->propertyChoice, 9)) ?? abort(404))
            : null;

        // Adding a person or a property to a customer needs permission to change that customer.
        if ($samePerson !== null || $this->contactChoice === self::NEW || $this->propertyChoice === self::NEW) {
            $this->authorize('update', $account);
        }

        return new CustomerResolution(
            account: $account,
            contact: $existingContact ?? $samePerson,
            newContact: $this->contactChoice === self::NEW ? $newContact : null,
            roles: $roles,
            property: $existingProperty,
            createProperty: $this->propertyChoice === self::NEW,
            propertyLabel: $this->propertyLabel,
            propertyType: $propertyType,
        );
    }

    /**
     * An existing customer may be chosen only if this person can see it and it is not archived.
     */
    private function chosenAccount(int $accountId): ?Account
    {
        return Account::query()->visibleTo(Auth::user())->notArchived()->find($accountId);
    }

    /**
     * Start a new person from what the inquiry already says, so nothing is typed twice.
     */
    private function prefillFromInquiry(): void
    {
        $name = InquiryName::split($this->lead->inquiry_name);

        $this->contactFirstName = (string) $name['first_name'];
        $this->contactLastName = (string) $name['last_name'];
        $this->contactPhone = (string) $this->lead->inquiry_phone;
        $this->contactEmail = (string) $this->lead->inquiry_email;
    }

    private function failWith(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}; ?>

<section class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    <div>
        <flux:link :href="route('leads.show', $lead)" wire:navigate class="text-sm">&larr; {{ $lead->displayName() ?? __('Lead') }}</flux:link>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ __('Who is this lead from?') }}</flux:heading>
        <flux:subheading>
            {{ __('Match it to a customer you already have, or make one from what the lead says. The inquiry itself is never changed.') }}
        </flux:subheading>
    </div>

    <div class="rounded-xl border border-zinc-200 bg-card p-5" data-test="inquiry-summary">
        <div class="text-sm font-medium text-zinc-500">{{ __('The inquiry') }}</div>
        <div class="mt-1 flex flex-wrap gap-x-6 gap-y-1 text-ink">
            <span class="font-semibold">{{ $lead->inquiry_name ?? __('No name given') }}</span>
            @if ($lead->inquiry_business_name)
                <span>{{ $lead->inquiry_business_name }}</span>
            @endif
            @if ($lead->inquiry_phone)
                <span class="tabular-nums">{{ $lead->displayPhone() }}</span>
            @endif
            @if ($lead->inquiry_email)
                <span>{{ $lead->inquiry_email }}</span>
            @endif
        </div>
        @if ($lead->inquiry_address)
            <div class="mt-1 text-sm text-zinc-600">{{ $lead->inquiry_address }}</div>
        @endif
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        {{-- 1. The customer --}}
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="resolve-customer">
            <div>
                <flux:heading size="lg" level="2">{{ __('1. Customer') }}</flux:heading>
                <flux:subheading>{{ __('A match is a suggestion. You decide whether it is the same customer.') }}</flux:subheading>
            </div>

            <div class="flex flex-col gap-2">
                @foreach ($this->matches as $match)
                    <label wire:key="match-{{ $match->account->id }}" class="cursor-pointer" data-test="customer-match">
                        <input type="radio" name="customerChoice" value="account:{{ $match->account->id }}" class="peer sr-only" wire:click="chooseCustomer('account:{{ $match->account->id }}')" @checked($customerChoice === 'account:'.$match->account->id) />
                        <span class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                            <span class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold">{{ $match->account->display_name }}</span>
                                <x-account-type :type="$match->account->type" />
                            </span>
                            <span class="text-sm text-zinc-700">{{ $match->reasonLabel() }}</span>
                        </span>
                    </label>
                @endforeach

                @if ($this->maskedMatch !== null)
                    {{-- A customer this person cannot see. Only its kind, which detail matched and a masked value are ever shown. --}}
                    <label class="cursor-pointer" data-test="masked-match">
                        <input type="radio" name="customerChoice" value="masked" class="peer sr-only" wire:click="chooseCustomer('masked')" @checked($customerChoice === $this::MASKED) />
                        <span class="flex flex-col gap-1 rounded-lg border border-dashed border-zinc-400 bg-white px-4 py-3 text-ink peer-checked:border-solid peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                            <span class="font-semibold">{{ $this->maskedMatch->description() }}</span>
                            <span class="text-sm text-zinc-700">{{ __('It is not one of the customers you can see, so nothing more is shown. Link this lead to it only if you are confident it is the same customer.') }}</span>
                        </span>
                    </label>
                @endif

                @if ($this->account !== null && ! collect($this->matches)->contains(fn ($match) => $match->account->is($this->account)))
                    <label class="cursor-pointer" data-test="customer-chosen">
                        <input type="radio" name="customerChoice" value="{{ $customerChoice }}" class="peer sr-only" checked />
                        <span class="flex flex-wrap items-center gap-2 rounded-lg border border-ink bg-banana px-4 py-3 text-ink">
                            <span class="font-semibold">{{ $this->account->display_name }}</span>
                            <x-account-type :type="$this->account->type" />
                        </span>
                    </label>
                @endif

                <label class="cursor-pointer" data-test="customer-new">
                    <input type="radio" name="customerChoice" value="new" class="peer sr-only" wire:click="chooseCustomer('new')" @checked($customerChoice === $this::NEW) />
                    <span class="flex flex-col rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                        <span class="font-semibold">{{ __('New customer') }}</span>
                        <span class="text-sm text-zinc-700">{{ __('Made from what this lead already says.') }}</span>
                    </span>
                </label>
            </div>

            <flux:error name="customerChoice" />

            <div class="flex flex-col gap-2">
                <flux:input
                    wire:model.live.debounce.300ms="customerSearch"
                    type="search"
                    icon="magnifying-glass"
                    :label="__('Or find another customer')"
                    :placeholder="__('Name, business, phone or email')"
                    autocomplete="off"
                    data-test="customer-search"
                />

                @if (mb_strlen(trim($customerSearch)) >= 2)
                    @if ($this->searchResults->isEmpty())
                        <flux:text data-test="no-customer-results">{{ __('No customer you can see matches that.') }}</flux:text>
                    @else
                        <ul class="overflow-hidden rounded-lg border border-zinc-300 bg-white" data-test="customer-results">
                            @foreach ($this->searchResults as $result)
                                <li wire:key="result-{{ $result->id }}" class="border-b border-zinc-200 last:border-0">
                                    <button type="button" wire:click="chooseCustomer('account:{{ $result->id }}')" class="flex w-full flex-wrap items-center gap-2 px-4 py-2 text-left text-sm hover:bg-cream focus-visible:bg-cream focus-visible:outline-none">
                                        <span class="font-semibold text-ink">{{ $result->display_name }}</span>
                                        <x-account-type :type="$result->type" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </div>

            @if ($customerChoice === $this::NEW)
                <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4" data-test="new-customer-fields">
                    <fieldset class="flex flex-col gap-2">
                        <legend class="text-sm font-medium text-ink">{{ __('Kind of customer') }}</legend>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach (AccountType::cases() as $option)
                                <label wire:key="new-type-{{ $option->value }}" class="cursor-pointer">
                                    <input type="radio" wire:model.live="newType" name="newType" value="{{ $option->value }}" class="peer sr-only" />
                                    <span class="flex min-h-11 items-center rounded-lg border border-zinc-300 bg-white px-4 py-2 font-nav text-sm font-bold text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                        {{ $option->label() }}
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <flux:error name="newType" />
                    </fieldset>

                    @if ($newType === AccountType::Commercial->value)
                        <flux:input wire:model="newBusinessName" :label="__('Business name')" type="text" autocomplete="off" />
                        <flux:error name="businessName" />
                    @endif
                </div>
            @endif
        </div>

        {{-- 2. The person --}}
        @if ($customerChoice !== '' && $customerChoice !== $this::MASKED)
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="resolve-contact">
                <div>
                    <flux:heading size="lg" level="2">{{ __('2. Contact') }}</flux:heading>
                    <flux:subheading>{{ __('The person this lead is from.') }}</flux:subheading>
                </div>

                <div class="flex flex-col gap-2">
                    @foreach ($this->accountContacts as $link)
                        <label wire:key="contact-{{ $link->contact_id }}" class="cursor-pointer" data-test="contact-option">
                            <input type="radio" wire:model.live="contactChoice" name="contactChoice" value="contact:{{ $link->contact_id }}" class="peer sr-only" />
                            <span class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span>
                                    <span class="font-semibold">{{ $link->contact->fullName() ?? __('No name given') }}</span>
                                    <span class="text-sm text-zinc-700">{{ implode(', ', $link->roleLabels()) }}</span>
                                </span>
                                <span class="text-sm tabular-nums text-zinc-700">
                                    {{ implode(' · ', array_filter([$link->contact->displayPhone(), $link->contact->email])) }}
                                    @if (in_array($link->contact_id, $this->matchedContactIds, true))
                                        <span class="font-semibold">&middot; {{ __('matches the inquiry') }}</span>
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach

                    @foreach ($this->samePeople as $person)
                        @if ($customerChoice === $this::NEW || $this->canAddToAccount)
                            <label wire:key="same-{{ $person->id }}" class="cursor-pointer" data-test="same-person-option">
                                <input type="radio" wire:model.live="contactChoice" name="contactChoice" value="link:{{ $person->id }}" class="peer sr-only" />
                                <span class="flex flex-col gap-1 rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                    <span>
                                        <span class="font-semibold">{{ $person->fullName() ?? __('No name given') }}</span>
                                        <span class="text-sm text-zinc-700">&middot; {{ __('the same phone or email as the inquiry') }}</span>
                                    </span>
                                    <span class="text-sm text-zinc-700">
                                        {{ __('Already a contact of:') }} {{ $person->accountLinks->map(fn ($held) => $held->account->display_name)->implode(', ') }}.
                                        {{ __('Choose this to connect the same person here instead of entering them again.') }}
                                    </span>
                                </span>
                            </label>
                        @endif
                    @endforeach

                    @if ($customerChoice === $this::NEW || $this->canAddToAccount)
                        <label class="cursor-pointer" data-test="contact-new">
                            <input type="radio" wire:model.live="contactChoice" name="contactChoice" value="new" class="peer sr-only" />
                            <span class="flex flex-col rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-semibold">{{ __('New contact') }}</span>
                                <span class="text-sm text-zinc-700">{{ __('Started from the name, phone and email on the inquiry.') }}</span>
                            </span>
                        </label>
                    @endif

                    @if (($customerChoice === $this::NEW && $newType === AccountType::Commercial->value) || $this->account?->isCommercial())
                        <label class="cursor-pointer" data-test="contact-none">
                            <input type="radio" wire:model.live="contactChoice" name="contactChoice" value="none" class="peer sr-only" />
                            <span class="flex rounded-lg border border-zinc-300 bg-white px-4 py-3 font-semibold text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                {{ __('No contact yet') }}
                            </span>
                        </label>
                    @endif
                </div>

                <flux:error name="contactChoice" />
                <flux:error name="contact" />

                @if ($contactChoice === $this::NEW)
                    <div class="flex flex-col gap-3 border-t border-zinc-200 pt-4" data-test="new-contact-fields">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="contactFirstName" :label="__('First name')" type="text" autocomplete="off" />
                            <flux:input wire:model="contactLastName" :label="__('Last name')" type="text" autocomplete="off" />
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <flux:input wire:model="contactPhone" :label="__('Phone')" type="tel" autocomplete="off" />
                            <flux:input wire:model="contactEmail" :label="__('Email')" type="email" autocomplete="off" />
                        </div>
                    </div>
                @endif

                @if ($contactChoice === $this::NEW || str_starts_with($contactChoice, 'link:'))
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
                @endif
            </div>

            {{-- 3. The place --}}
            <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5" data-test="resolve-property">
                <div>
                    <flux:heading size="lg" level="2">{{ __('3. Property') }}</flux:heading>
                    <flux:subheading>{{ __('The place this lead is about. It can be left for later.') }}</flux:subheading>
                </div>

                <div class="flex flex-col gap-2">
                    @foreach ($this->accountProperties as $property)
                        <label wire:key="property-{{ $property->id }}" class="cursor-pointer" data-test="property-option">
                            <input type="radio" wire:model.live="propertyChoice" name="propertyChoice" value="property:{{ $property->id }}" class="peer sr-only" />
                            <span class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span>
                                    <span class="font-semibold">{{ $property->label ?? __('Property') }}</span>
                                    <span class="text-sm text-zinc-700">{{ $property->address }}</span>
                                </span>
                                @if ($this->matchedProperty?->is($property))
                                    <span class="text-sm font-semibold text-zinc-700">{{ __('the address on the inquiry') }}</span>
                                @endif
                            </span>
                        </label>
                    @endforeach

                    @if ($this->leadHasAddress && $this->matchedProperty === null && ($customerChoice === $this::NEW || $this->canAddToAccount))
                        <label class="cursor-pointer" data-test="property-new">
                            <input type="radio" wire:model.live="propertyChoice" name="propertyChoice" value="new" class="peer sr-only" />
                            <span class="flex flex-col rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-semibold">{{ __('New property at the address on the inquiry') }}</span>
                                <span class="text-sm text-zinc-700">{{ $lead->inquiry_address }}</span>
                            </span>
                        </label>
                    @endif

                    <label class="cursor-pointer" data-test="property-none">
                        <input type="radio" wire:model.live="propertyChoice" name="propertyChoice" value="none" class="peer sr-only" />
                        <span class="flex flex-col rounded-lg border border-zinc-300 bg-white px-4 py-3 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                            <span class="font-semibold">{{ __('No property yet') }}</span>
                            @unless ($this->leadHasAddress)
                                <span class="text-sm text-zinc-700">{{ __('This lead has no address. A property can be added on the customer\'s page.') }}</span>
                            @endunless
                        </span>
                    </label>
                </div>

                <flux:error name="propertyChoice" />

                @if ($propertyChoice === $this::NEW)
                    <div class="grid gap-3 border-t border-zinc-200 pt-4 sm:grid-cols-2" data-test="new-property-fields">
                        <flux:input wire:model="propertyLabel" :label="__('Label')" :placeholder="__('What staff call it')" type="text" autocomplete="off" />

                        <flux:select wire:model="propertyType" :label="__('Type')">
                            @foreach (PropertyType::cases() as $type)
                                <flux:select.option :value="$type->value">{{ $type->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endif
            </div>
        @elseif ($customerChoice === $this::MASKED)
            <div class="rounded-xl border border-zinc-200 bg-card p-5 text-sm text-ink" data-test="masked-explanation">
                {{ __('The lead will be linked to that customer and to the person whose details matched. Once it is linked you will see the customer, and can choose the property from the lead.') }}
            </div>
        @endif

        <flux:error name="account" />

        <div class="flex justify-end gap-2">
            <flux:button :href="route('leads.show', $lead)" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="save-resolution">{{ __('Link this lead') }}</flux:button>
        </div>
    </form>
</section>
