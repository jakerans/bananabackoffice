<?php

namespace App\Support\Access;

use App\Models\Location;

/**
 * The location a staff member is currently working in: either one named
 * location or, explicitly, all locations. There is no "unspecified" context.
 */
final readonly class LocationContext
{
    private function __construct(
        public bool $isAllLocations,
        public ?Location $location,
    ) {}

    public static function forLocation(Location $location): self
    {
        return new self(isAllLocations: false, location: $location);
    }

    public static function allLocations(): self
    {
        return new self(isAllLocations: true, location: null);
    }

    public function label(): string
    {
        return $this->location === null ? __('All Locations') : $this->location->name;
    }

    /**
     * Determine whether this context is the given location.
     */
    public function is(Location $location): bool
    {
        return $this->location !== null && $this->location->is($location);
    }
}
