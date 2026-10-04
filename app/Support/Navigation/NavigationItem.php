<?php

namespace App\Support\Navigation;

use App\Enums\NavigationState;
use App\Enums\Permission;

/**
 * One entry in the navigation: a center, or a section inside one.
 */
final readonly class NavigationItem
{
    /**
     * @param  string  $id  Stable identifier that never changes, even if the label does.
     * @param  list<Permission>  $permissions  Holding any one of these makes the item visible.
     * @param  string|null  $route  Route name; present only for live items.
     * @param  list<NavigationItem>  $children
     */
    public function __construct(
        public string $id,
        public string $label,
        public array $permissions,
        public NavigationState $state = NavigationState::ComingSoon,
        public ?string $route = null,
        public ?string $icon = null,
        public ?string $description = null,
        public array $children = [],
    ) {}

    public function isLive(): bool
    {
        return $this->state === NavigationState::Live;
    }

    /**
     * Determine whether a route name belongs to this item, such as "settings.users" or "settings.users.show".
     */
    public function ownsRoute(string $routeName): bool
    {
        return $this->route !== null
            && ($routeName === $this->route || str_starts_with($routeName, $this->route.'.'));
    }

    /**
     * Get this item's children that have been built.
     *
     * @return list<NavigationItem>
     */
    public function liveChildren(): array
    {
        return array_values(array_filter($this->children, fn (NavigationItem $item): bool => $item->isLive()));
    }

    /**
     * Get this item's children that have not been built yet.
     *
     * @return list<NavigationItem>
     */
    public function comingSoonChildren(): array
    {
        return array_values(array_filter($this->children, fn (NavigationItem $item): bool => ! $item->isLive()));
    }

    /**
     * Get this item and everything beneath it as a flat list.
     *
     * @return list<NavigationItem>
     */
    public function flatten(): array
    {
        $items = [$this];

        foreach ($this->children as $child) {
            $items = [...$items, ...$child->flatten()];
        }

        return $items;
    }
}
