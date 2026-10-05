<?php

namespace App\Support\Addresses;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Address lookup through Mapbox's Geocoding API.
 *
 * Suggestions while typing are "temporary" results: they are shown and never
 * kept. The one address a person selects is then requested again as a
 * "permanent" result, which is the kind Mapbox allows to be stored indefinitely.
 *
 * Every request is made from the server, so the access token never reaches a
 * browser. Only the address text is sent; nothing else about a Lead leaves
 * Backoffice.
 */
final class MapboxAddressLookup implements AddressLookup
{
    public const string PROVIDER = 'mapbox';

    /**
     * Typing fewer characters than this asks Mapbox nothing.
     */
    public const int MINIMUM_CHARACTERS = 5;

    private const string ENDPOINT = 'https://api.mapbox.com/search/geocode/v6/forward';

    public function __construct(private string $accessToken) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function suggest(string $typed): array
    {
        $typed = trim($typed);

        if (mb_strlen($typed) < self::MINIMUM_CHARACTERS) {
            return [];
        }

        $suggestions = [];

        foreach ($this->search($typed, autocomplete: true, permanent: false, limit: 5) as $feature) {
            $id = $feature['properties']['mapbox_id'] ?? null;
            $label = $feature['properties']['full_address'] ?? null;

            if (is_string($id) && is_string($label) && $label !== '') {
                $suggestions[] = new AddressSuggestion($id, $label);
            }
        }

        return $suggestions;
    }

    public function resolve(AddressSuggestion $suggestion): ?StructuredAddress
    {
        $feature = $this->search($suggestion->label, autocomplete: false, permanent: true, limit: 1)[0] ?? null;

        if ($feature === null) {
            return null;
        }

        $properties = $feature['properties'] ?? [];
        $context = $properties['context'] ?? [];
        $formatted = $properties['full_address'] ?? null;

        if (! is_string($formatted) || $formatted === '') {
            return null;
        }

        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $number = fn (mixed $value): ?float => is_numeric($value) ? (float) $value : null;

        return new StructuredAddress(
            formatted: $formatted,
            line1: $text($context['address']['name'] ?? null) ?? $text($properties['name'] ?? null),
            line2: null,
            city: $text($context['place']['name'] ?? null) ?? $text($context['locality']['name'] ?? null),
            state: $text($context['region']['region_code'] ?? null) ?? $text($context['region']['name'] ?? null),
            postalCode: $text($context['postcode']['name'] ?? null),
            country: $text($context['country']['country_code'] ?? null),
            latitude: $number($properties['coordinates']['latitude'] ?? null),
            longitude: $number($properties['coordinates']['longitude'] ?? null),
            accuracy: $text($properties['coordinates']['accuracy'] ?? null),
            provider: self::PROVIDER,
            providerPlaceId: $text($properties['mapbox_id'] ?? null),
            entry: StructuredAddress::LOOKUP,
        );
    }

    /**
     * Ask Mapbox for United States street addresses matching the text.
     *
     * Any failure, of any kind, is reported and answered with nothing, so that
     * staff fall back to typing the address and a Lead can always be saved.
     *
     * @return list<array<string, mixed>>
     */
    private function search(string $text, bool $autocomplete, bool $permanent, int $limit): array
    {
        try {
            $response = $this->request()->get(self::ENDPOINT, [
                'q' => mb_substr($text, 0, 256),
                'access_token' => $this->accessToken,
                'autocomplete' => $autocomplete ? 'true' : 'false',
                'permanent' => $permanent ? 'true' : 'false',
                'country' => 'us',
                'types' => 'address',
                'language' => 'en',
                'limit' => $limit,
            ]);

            if (! $response->successful()) {
                report(new MapboxLookupFailed("Mapbox answered an address lookup with HTTP {$response->status()}."));

                return [];
            }

            $features = $response->json('features');

            if (! is_array($features)) {
                report(new MapboxLookupFailed('Mapbox answered an address lookup with something other than a list of addresses.'));

                return [];
            }

            return array_values(array_filter($features, is_array(...)));
        } catch (Throwable $exception) {
            // The message of a connection error can include the request address, and
            // with it the access token, so only the kind of error is reported.
            report(new MapboxLookupFailed('Mapbox could not be reached for an address lookup: '.$exception::class));

            return [];
        }
    }

    private function request(): PendingRequest
    {
        return Http::connectTimeout(2)->timeout(4)->acceptJson();
    }
}
