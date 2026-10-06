<?php

namespace App\Support\Addresses;

use App\Models\Lead;
use App\Models\Property;

/**
 * A property address in parts.
 *
 * It comes either from an address lookup, with coordinates and the provider's
 * identifier for the place, or from someone typing it by hand, with neither.
 * The display form on one line is kept beside the parts.
 */
final readonly class StructuredAddress
{
    public const string LOOKUP = 'lookup';

    public const string MANUAL = 'manual';

    public function __construct(
        public string $formatted,
        public ?string $line1 = null,
        public ?string $line2 = null,
        public ?string $city = null,
        public ?string $state = null,
        public ?string $postalCode = null,
        public ?string $country = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?string $accuracy = null,
        public ?string $provider = null,
        public ?string $providerPlaceId = null,
        public string $entry = self::MANUAL,
    ) {}

    /**
     * Build an address from what someone typed by hand. Returns null when nothing was typed.
     *
     * A hand-typed address has no coordinates and no provider: nothing is invented for it.
     */
    public static function manual(?string $line1, ?string $line2, ?string $city, ?string $state, ?string $postalCode): ?self
    {
        $clean = fn (?string $value): ?string => trim((string) $value) === '' ? null : trim((string) $value);

        [$line1, $line2, $city, $state, $postalCode] = [$clean($line1), $clean($line2), $clean($city), $clean($state), $clean($postalCode)];

        if ($line1 === null && $line2 === null && $city === null && $state === null && $postalCode === null) {
            return null;
        }

        $stateAndPostalCode = trim(implode(' ', array_filter([$state, $postalCode])));

        $formatted = implode(', ', array_filter([$line1, $line2, $city, $stateAndPostalCode === '' ? null : $stateAndPostalCode]));

        return new self(
            formatted: $formatted,
            line1: $line1,
            line2: $line2,
            city: $city,
            state: $state,
            postalCode: $postalCode,
            entry: self::MANUAL,
        );
    }

    /**
     * The address a Lead has now, or null when it has none.
     */
    public static function ofLead(Lead $lead): ?self
    {
        if ($lead->inquiry_address === null) {
            return null;
        }

        return new self(
            formatted: $lead->inquiry_address,
            line1: $lead->address_line1,
            line2: $lead->address_line2,
            city: $lead->address_city,
            state: $lead->address_state,
            postalCode: $lead->address_postal_code,
            country: $lead->address_country,
            latitude: $lead->address_latitude === null ? null : (float) $lead->address_latitude,
            longitude: $lead->address_longitude === null ? null : (float) $lead->address_longitude,
            accuracy: $lead->address_accuracy,
            provider: $lead->address_provider,
            providerPlaceId: $lead->address_provider_place_id,
            entry: $lead->address_entry ?? self::MANUAL,
        );
    }

    /**
     * Rebuild an address from the array form kept on a page between requests.
     *
     * @param  array<string, mixed>  $values
     */
    public static function fromArray(array $values): self
    {
        $text = fn (string $key): ?string => isset($values[$key]) && is_string($values[$key]) && $values[$key] !== '' ? $values[$key] : null;
        $number = fn (string $key): ?float => isset($values[$key]) && is_numeric($values[$key]) ? (float) $values[$key] : null;

        return new self(
            formatted: (string) ($values['formatted'] ?? ''),
            line1: $text('line1'),
            line2: $text('line2'),
            city: $text('city'),
            state: $text('state'),
            postalCode: $text('postalCode'),
            country: $text('country'),
            latitude: $number('latitude'),
            longitude: $number('longitude'),
            accuracy: $text('accuracy'),
            provider: $text('provider'),
            providerPlaceId: $text('providerPlaceId'),
            entry: $text('entry') === self::LOOKUP ? self::LOOKUP : self::MANUAL,
        );
    }

    /**
     * @return array<string, string|float|null>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * The values to store on a Lead for this address.
     *
     * @return array<string, string|float|null>
     */
    public function toLeadAttributes(): array
    {
        return [
            'inquiry_address' => $this->formatted,
            'address_line1' => $this->line1,
            'address_line2' => $this->line2,
            'address_city' => $this->city,
            'address_state' => $this->state,
            'address_postal_code' => $this->postalCode,
            'address_country' => $this->country,
            'address_latitude' => $this->latitude,
            'address_longitude' => $this->longitude,
            'address_accuracy' => $this->accuracy,
            'address_provider' => $this->provider,
            'address_provider_place_id' => $this->providerPlaceId,
            'address_entry' => $this->entry,
        ];
    }

    /**
     * The address a Property has.
     */
    public static function ofProperty(Property $property): self
    {
        return new self(
            formatted: $property->address,
            line1: $property->address_line1,
            line2: $property->address_line2,
            city: $property->address_city,
            state: $property->address_state,
            postalCode: $property->address_postal_code,
            country: $property->address_country,
            latitude: $property->address_latitude === null ? null : (float) $property->address_latitude,
            longitude: $property->address_longitude === null ? null : (float) $property->address_longitude,
            accuracy: $property->address_accuracy,
            provider: $property->address_provider,
            providerPlaceId: $property->address_provider_place_id,
            entry: $property->address_entry ?? self::MANUAL,
        );
    }

    /**
     * The values to store on a Property for this address, with the simplified
     * form used to notice the same address typed another way.
     *
     * @return array<string, string|float|null>
     */
    public function toPropertyAttributes(): array
    {
        return [
            'address' => $this->formatted,
            'address_line1' => $this->line1,
            'address_line2' => $this->line2,
            'address_city' => $this->city,
            'address_state' => $this->state,
            'address_postal_code' => $this->postalCode,
            'address_country' => $this->country,
            'address_latitude' => $this->latitude,
            'address_longitude' => $this->longitude,
            'address_accuracy' => $this->accuracy,
            'address_provider' => $this->provider,
            'address_provider_place_id' => $this->providerPlaceId,
            'address_entry' => $this->entry,
            'address_normalized' => AddressKey::for($this->line1 ?? $this->formatted, $this->line2, $this->postalCode),
        ];
    }

    /**
     * The values that clear a Lead's address.
     *
     * @return array<string, null>
     */
    public static function emptyLeadAttributes(): array
    {
        return array_fill_keys(array_keys((new self(''))->toLeadAttributes()), null);
    }

    public function wasLookedUp(): bool
    {
        return $this->entry === self::LOOKUP;
    }

    /**
     * Whether another address is the same place described the same way.
     */
    public function matches(?self $other): bool
    {
        return $other !== null && $this->toLeadAttributes() == $other->toLeadAttributes();
    }
}
