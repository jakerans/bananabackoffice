<?php

namespace App\Support\Queue;

/**
 * The United States timezones a Location can be set to, in one place.
 *
 * The list is fixed in code. The server validates against it, and the queue
 * engine reuses it. Daylight saving is left to PHP's own timezone data.
 */
final class LocationTimezones
{
    /** What a new Location gets, and what an unrouted Lead is judged by. */
    public const string DEFAULT = 'America/New_York';

    /**
     * @return array<string, string> IANA identifier => plain name
     */
    public static function options(): array
    {
        return [
            'America/New_York' => 'Eastern',
            'America/Chicago' => 'Central',
            'America/Denver' => 'Mountain',
            'America/Phoenix' => 'Arizona (no daylight saving)',
            'America/Los_Angeles' => 'Pacific',
            'America/Anchorage' => 'Alaska',
            'Pacific/Honolulu' => 'Hawaii',
        ];
    }

    /**
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return array_keys(self::options());
    }

    public static function isAllowed(string $identifier): bool
    {
        return array_key_exists($identifier, self::options());
    }

    public static function label(string $identifier): string
    {
        return self::options()[$identifier] ?? $identifier;
    }
}
