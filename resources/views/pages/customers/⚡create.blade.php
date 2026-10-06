<?php

use App\Actions\Customers\CreateAccount;
use App\Enums\AccountType;
use App\Enums\ContactRole;
use App\Models\Account;
use App\Support\Customers\ContactDetails;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('New customer')] class extends Component {
    public string $type = AccountType::Residential->value;

    public string $businessName = '';

    public string $contactFirstName = '';

    public string $contactLastName = '';

    public string $contactPhone = '';

    public string $contactAlternatePhone = '';

    public string $contactEmail = '';

    /** @var list<string> */
    public array $contactRoles = [ContactRole::Owner->value];

    /** Identifies this filling-in of the form, so pressing Save twice makes one customer. */
    #[Locked]
    public string $submissionKey = '';

    /**
     * Mount the component.
     */
    public function mount(): void
    {
        $this->authorize('create', Account::class);

        $this->submissionKey = (string) Str::uuid();
    }

    /**
     * Start from the usual role for the kind of customer, unless roles were already chosen by hand.
     */
    public function updatedType(): void
    {
        $usual = [AccountType::Residential->value => ContactRole::Owner->value, AccountType::Commercial->value => ContactRole::BusinessContact->value];

        if (count($this->contactRoles) <= 1 && in_array($this->contactRoles[0] ?? null, [null, ...array_values($usual)], true)) {
            $this->contactRoles = [$usual[$this->type] ?? ContactRole::Owner->value];
        }

        $this->resetValidation();
    }

    /**
     * Create the customer. Only company-wide staff may create one that has no lead.
     */
    public function save(CreateAccount $createAccount): void
    {
        $this->authorize('create', Account::class);

        $this->validate([
            'type' => ['required', Rule::enum(AccountType::class)],
            'businessName' => ['nullable', 'string', 'max:255'],
            'contactFirstName' => ['nullable', 'string', 'max:255'],
            'contactLastName' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:50'],
            'contactAlternatePhone' => ['nullable', 'string', 'max:50'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'contactRoles' => ['array'],
            'contactRoles.*' => [Rule::enum(ContactRole::class)],
        ], attributes: [
            'businessName' => 'business name',
            'contactFirstName' => 'first name',
            'contactLastName' => 'last name',
            'contactPhone' => 'phone',
            'contactAlternatePhone' => 'alternate phone',
            'contactEmail' => 'email',
        ]);

        // A second press of Save for the same filling-in of the form does nothing.
        if (! Cache::add('customer-create:'.$this->submissionKey, true, now()->addMinutes(10))) {
            return;
        }

        try {
            $account = $createAccount->handle(
                AccountType::from($this->type),
                $this->businessName,
                ContactDetails::fromInput([
                    'first_name' => $this->contactFirstName,
                    'last_name' => $this->contactLastName,
                    'phone' => $this->contactPhone,
                    'alternate_phone' => $this->contactAlternatePhone,
                    'email' => $this->contactEmail,
                ]),
                array_values(array_filter(array_map(fn (mixed $role) => is_string($role) ? ContactRole::tryFrom($role) : null, $this->contactRoles))),
            );
        } catch (Throwable $exception) {
            // Nothing was created, so the form may be submitted again.
            Cache::forget('customer-create:'.$this->submissionKey);

            throw $exception;
        }

        $this->redirectRoute('customers.show', $account, navigate: true);
    }
}; ?>

<section class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    <div>
        <flux:link :href="route('customers')" wire:navigate class="text-sm">&larr; {{ __('Customers') }}</flux:link>
    </div>

    <div>
        <flux:heading size="xl" level="1">{{ __('New customer') }}</flux:heading>
        <flux:subheading>
            {{ __('For a customer who has no lead. When someone has inquired, record the lead instead and link the customer from it.') }}
        </flux:subheading>
    </div>

    <form wire:submit="save" class="flex flex-col gap-6">
        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <fieldset class="flex flex-col gap-2" data-test="customer-type-choice">
                <legend class="text-sm font-medium text-ink">{{ __('Kind of customer') }}</legend>

                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach (AccountType::cases() as $option)
                        <label wire:key="type-{{ $option->value }}" class="cursor-pointer">
                            <input type="radio" wire:model.live="type" name="type" value="{{ $option->value }}" class="peer sr-only" />
                            <span class="flex min-h-14 flex-col justify-center rounded-lg border border-zinc-300 bg-white px-4 py-2 text-ink peer-checked:border-ink peer-checked:bg-banana peer-focus-visible:ring-2 peer-focus-visible:ring-wall-teal">
                                <span class="font-nav text-sm font-bold">{{ $option->label() }}</span>
                                <span class="text-xs text-zinc-700">
                                    {{ $option === AccountType::Residential ? __('A household, named after its primary contact') : __('A business, named by its business name') }}
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <flux:error name="type" />
            </fieldset>

            @if ($type === AccountType::Commercial->value)
                <flux:input wire:model="businessName" :label="__('Business name')" type="text" autocomplete="off" data-test="business-name" />
            @endif
        </div>

        <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-card p-5">
            <div>
                <flux:heading size="lg" level="2">
                    {{ $type === AccountType::Commercial->value ? __('First contact') : __('Primary contact') }}
                </flux:heading>
                <flux:subheading>
                    @if ($type === AccountType::Commercial->value)
                        {{ __('Optional. Leave every field empty to add the people later.') }}
                    @else
                        {{ __('Required. One person: add a spouse or anyone else afterwards.') }}
                    @endif
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
        </div>

        <div class="flex justify-end gap-2">
            <flux:button :href="route('customers')" wire:navigate variant="ghost">{{ __('Cancel') }}</flux:button>
            <flux:button type="submit" variant="primary" data-test="save-customer">{{ __('Create customer') }}</flux:button>
        </div>
    </form>
</section>
