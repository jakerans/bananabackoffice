<?php

namespace App\Support\Navigation;

use App\Enums\NavigationState;
use App\Enums\Permission;
use Illuminate\Support\Arr;

/**
 * One entry in the navigation: a center, or a section inside one.
 */
final readonly class NavigationItem
{
    /**
     * @param  string  $id  Stable identifier that never changes, even if the label does.
     * @param  list<Permission>  $permissions  Holding any one of these makes the item visible.
     * @param  string|null  $route  Route name. Every built item has one; an unbuilt item has one only when it is the page its center opens on.
     * @param  list<NavigationItem>  $children
     * @param  string|null  $primary  ID of the child this item opens on. Defaults to the first child.
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
        public ?string $primary = null,
    ) {}

    /**
     * Determine whether the feature behind this item has been built.
     */
    public function isLive(): bool
    {
        return $this->state === NavigationState::Live;
    }

    /**
     * Determine whether this item has a page a staff member can be taken to.
     */
    public function isReachable(): bool
    {
        return $this->route !== null;
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
     * Get the child this item opens on: the one named as primary, otherwise the first.
     */
    public function primaryChild(): ?NavigationItem
    {
        return Arr::first($this->children, fn (NavigationItem $child): bool => $child->id === $this->primary)
            ?? Arr::first($this->children);
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
