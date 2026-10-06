<?php

namespace App\Support\Addresses;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A Livewire page that captures a property address: by looking it up, or by hand.
 *
 * Looking an address up is a convenience and never a requirement. Whenever the
 * lookup has nothing to offer, is not set up, or fails, the same page accepts the
 * address typed by hand, so an address can never stop a record being saved.
 *
 * A page extends this, calls startAddressCapture() when it mounts, and shows the
 * controls with the "lead.address-picker" view component.
 */
abstract class AddressCapturePage extends Component
{
    public const string ADDRESS_SEARCH = 'search';

    public const string ADDRESS_SELECTED = 'selected';

    public const string ADDRESS_MANUAL = 'manual';

    /** What is being typed into the lookup box. */
    public string $addressSearch = '';

    /**
     * Addresses offered for what is being typed, each with an "id" and a "label".
     * Shown, then discarded. The browser sends this back, so its shape is checked
     * before it is used.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $addressSuggestions = [];

    public string $addressMode = self::ADDRESS_SEARCH;

    /**
     * The address that was looked up and selected. Locked, so its coordinates and
     * identifier can only ever be what the provider returned to the server.
     *
     * @var array<string, mixed>|null
     */
    #[Locked]
    public ?array $selectedAddress = null;

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $addressCity = '';

    public string $addressState = '';

    public string $addressPostalCode = '';

    /** Set when a selected address could not be confirmed, to explain the switch to typing it. */
    public bool $addressLookupFailed = false;

    /**
     * Start on manual entry when no lookup is set up.
     */
    protected function startAddressCapture(): void
    {
        if (! $this->addressLookupIsAvailable()) {
            $this->addressMode = self::ADDRESS_MANUAL;
        }
    }

    public function addressLookupIsAvailable(): bool
    {
        return app(AddressLookup::class)->isAvailable();
    }

    /**
     * Offer addresses for what has been typed. Asks the provider nothing until
     * enough has been typed, and no more than a sensible number of times a minute.
     */
    public function updatedAddressSearch(): void
    {
        $this->addressSuggestions = [];

        if (mb_strlen(trim($this->addressSearch)) < MapboxAddressLookup::MINIMUM_CHARACTERS) {
            return;
        }

        $limiterKey = 'address-lookup:'.Auth::id();

        if (RateLimiter::tooManyAttempts($limiterKey, 60)) {
            return;
        }

        RateLimiter::hit($limiterKey, 60);

        $this->addressSuggestions = array_map(
            fn (AddressSuggestion $suggestion): array => $suggestion->toArray(),
            app(AddressLookup::class)->suggest($this->addressSearch),
        );
    }

    /**
     * Use one of the offered addresses. It is looked up again in the way that
     * allows it to be kept; if that fails, the person types the address instead.
     */
    public function selectAddress(int $index): void
    {
        $chosen = $this->addressSuggestions[$index] ?? null;

        $id = $chosen['id'] ?? null;
        $label = $chosen['label'] ?? null;

        if (! is_string($id) || ! is_string($label) || trim($label) === '') {
            return;
        }

        $address = app(AddressLookup::class)->resolve(new AddressSuggestion($id, $label));

        $this->addressSuggestions = [];

        if ($address === null) {
            $this->addressLookupFailed = true;
            $this->addressLine1 = $label;
            $this->addressMode = self::ADDRESS_MANUAL;
            $this->selectedAddress = null;
        } else {
            $this->addressLookupFailed = false;
            $this->selectedAddress = $address->toArray();
            $this->addressMode = self::ADDRESS_SELECTED;
        }

        $this->propertyAddressChanged();
    }

    /**
     * Type the address by hand, starting from whatever was typed into the lookup box.
     */
    public function enterAddressManually(): void
    {
        if ($this->addressLine1 === '') {
            $this->addressLine1 = trim($this->addressSearch);
        }

        $this->selectedAddress = null;
        $this->addressSuggestions = [];
        $this->addressMode = self::ADDRESS_MANUAL;

        $this->propertyAddressChanged();
    }

    /**
     * Go back to looking the address up, discarding what was selected or typed.
     */
    public function searchForAddress(): void
    {
        $this->reset('addressSearch', 'addressSuggestions', 'selectedAddress', 'addressLine1', 'addressLine2', 'addressCity', 'addressState', 'addressPostalCode', 'addressLookupFailed');

        $this->addressMode = $this->addressLookupIsAvailable() ? self::ADDRESS_SEARCH : self::ADDRESS_MANUAL;

        $this->propertyAddressChanged();
    }

    /**
     * A postal code typed by hand can identify a service area just as a looked-up one can.
     */
    public function updatedAddressPostalCode(): void
    {
        $this->propertyAddressChanged();
    }

    /**
     * The address as it stands on the page, or null when there is none.
     */
    protected function structuredAddress(): ?StructuredAddress
    {
        if ($this->addressMode === self::ADDRESS_SELECTED && $this->selectedAddress !== null) {
            return StructuredAddress::fromArray($this->selectedAddress);
        }

        if ($this->addressMode === self::ADDRESS_MANUAL) {
            return StructuredAddress::manual($this->addressLine1, $this->addressLine2, $this->addressCity, $this->addressState, $this->addressPostalCode);
        }

        return null;
    }

    /**
     * Show an existing address on the page, ready to be kept or changed.
     */
    protected function fillAddress(?StructuredAddress $address): void
    {
        $this->reset('addressSearch', 'addressSuggestions', 'selectedAddress', 'addressLine1', 'addressLine2', 'addressCity', 'addressState', 'addressPostalCode', 'addressLookupFailed');

        if ($address === null) {
            $this->addressMode = $this->addressLookupIsAvailable() ? self::ADDRESS_SEARCH : self::ADDRESS_MANUAL;

            return;
        }

        if ($address->wasLookedUp()) {
            $this->selectedAddress = $address->toArray();
            $this->addressMode = self::ADDRESS_SELECTED;

            return;
        }

        $this->addressLine1 = (string) ($address->line1 ?? $address->formatted);
        $this->addressLine2 = (string) $address->line2;
        $this->addressCity = (string) $address->city;
        $this->addressState = (string) $address->state;
        $this->addressPostalCode = (string) $address->postalCode;
        $this->addressMode = self::ADDRESS_MANUAL;
    }

    /**
     * Validation for the address parts typed by hand. All are optional.
     *
     * @return array<string, array<int, string>>
     */
    protected function addressRules(): array
    {
        return [
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'addressCity' => ['nullable', 'string', 'max:255'],
            'addressState' => ['nullable', 'string', 'max:100'],
            'addressPostalCode' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Called whenever the address changes. A page overrides it to react, for
     * example by suggesting the location that serves the address.
     */
    protected function propertyAddressChanged(): void
    {
        //
    }
}
