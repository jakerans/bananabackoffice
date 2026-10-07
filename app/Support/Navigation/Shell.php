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
    /**
     * Remembers which center the staff member was last in. It only decides which
     * menu is drawn around a shared page; it never grants access to anything.
     */
    public const string LAST_CENTER_KEY = 'backoffice.center';

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
     * Get the external surfaces the staff member may open in their current location.
     *
     * @return list<NavigationItem>
     */
    public function externalSurfaces(): array
    {
        return $this->navigation->externalSurfacesFor($this->user());
    }

    /**
     * Get the navigation destination the current page belongs to.
     *
     * A shared page, such as Leads, sits in more than one center's menu. It is
     * shown under a center the staff member may open, preferring the one they
     * were last in, so the menu around the page does not jump unexpectedly.
     */
    public function currentDestination(): ?NavigationItem
    {
        $user = $this->user();

        $candidates = array_values(array_filter(
            $this->navigation->destinationsForRoute($this->request->route()?->getName()),
            fn (NavigationItem $destination): bool => $this->navigation->allows($user, $destination),
        ));

        if ($candidates === []) {
            return null;
        }

        $session = $this->request->hasSession() ? $this->request->session() : null;
        $lastDestinationId = $session?->get(self::LAST_CENTER_KEY);

        if ($lastDestinationId === 'settings') {
            $lastDestinationId = 'company';
        }

        $destination = collect($candidates)->firstWhere('id', $lastDestinationId) ?? $candidates[0];

        $session?->put(self::LAST_CENTER_KEY, $destination->id);

        return $destination;
    }

    /**
     * Get the section of a center the current page sits inside, when that section has pages of its own.
     */
    public function currentSection(NavigationItem $center): ?NavigationItem
    {
        return collect($center->children)->first(
            fn (NavigationItem $section): bool => $section->children !== [] && $this->isWithin($section),
        );
    }

    /**
     * Get the page a section opens on for this staff member: itself when it has
     * a page, otherwise its first sub-section they may open.
     */
    public function landingFor(NavigationItem $section): ?NavigationItem
    {
        if ($section->isReachable()) {
            return $section;
        }

        return $section->children === [] ? null : $this->navigation->landingFor($this->user(), $section);
    }

    public function isCurrent(NavigationItem $item): bool
    {
        $routeName = $this->request->route()?->getName();

        return $routeName !== null && $item->ownsRoute($routeName);
    }

    /**
     * Determine whether the current page is this item or one of the pages beneath it.
     */
    public function isWithin(NavigationItem $item): bool
    {
        return collect($item->flatten())->contains(fn (NavigationItem $descendant): bool => $this->isCurrent($descendant));
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
