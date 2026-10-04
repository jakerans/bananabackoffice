<?php

namespace App\Support\Navigation;

use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Access\LocationContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Everything the application shell needs to draw itself for the signed-in staff member.
 */
class Shell
{
    public function __construct(
        private Request $request,
        private Navigation $navigation,
        private CurrentLocation $currentLocation,
    ) {}

    public function user(): User
    {
        return $this->request->user();
    }

    /**
     * Get the centers the staff member may open in their current location.
     *
     * @return list<NavigationItem>
     */
    public function centers(): array
    {
        return $this->navigation->centersFor($this->user());
    }

    /**
     * Get the center the current page belongs to.
     */
    public function currentCenter(): ?NavigationItem
    {
        return $this->navigation->centerForRoute($this->request->route()?->getName());
    }

    public function isCurrent(NavigationItem $item): bool
    {
        $routeName = $this->request->route()?->getName();

        return $routeName !== null && $item->ownsRoute($routeName);
    }

    public function locationContext(): ?LocationContext
    {
        return $this->currentLocation->context($this->user());
    }

    /**
     * Get the locations the staff member may switch to.
     *
     * @return Collection<int, Location>
     */
    public function locationOptions(): Collection
    {
        return $this->currentLocation->options($this->user());
    }

    public function canViewAllLocations(): bool
    {
        return $this->user()->canViewAllLocations();
    }

    /**
     * The switcher is only worth showing when there is something to switch to.
     */
    public function canSwitchLocation(): bool
    {
        return $this->locationOptions()->count() + ($this->canViewAllLocations() ? 1 : 0) > 1;
    }
}
