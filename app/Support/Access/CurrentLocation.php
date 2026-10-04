<?php

namespace App\Support\Access;

use App\Models\Location;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Resolves the location a staff member is working in.
 *
 * The session only remembers a choice. It is re-checked against the person's
 * access grants on every request, so a stale or tampered value can never widen
 * access, and a missing value never means "all locations".
 */
class CurrentLocation
{
    public const string SESSION_KEY = 'backoffice.location';

    public const string ALL_LOCATIONS = 'all';

    private const string MEMO_KEY = 'backoffice.current_location';

    public function __construct(
        private Request $request,
        private Session $session,
    ) {}

    /**
     * Get the staff member's current location context, or null when they have no location to work in.
     */
    public function context(User $user): ?LocationContext
    {
        $memo = $this->memo($user);

        if (! $memo->hasContext) {
            $memo->context = $this->resolve($user);
            $memo->hasContext = true;
        }

        return $memo->context;
    }

    /**
     * Get the active locations the staff member may switch to.
     *
     * @return Collection<int, Location>
     */
    public function options(User $user): Collection
    {
        $memo = $this->memo($user);

        return $memo->options ??= $user->authorizedLocations();
    }

    /**
     * Switch to one location.
     *
     * @throws AuthorizationException
     */
    public function switchTo(User $user, Location $location): void
    {
        if (! $this->options($user)->contains($location)) {
            throw new AuthorizationException;
        }

        $this->store($location->id);
    }

    /**
     * Switch to the all-locations view.
     *
     * @throws AuthorizationException
     */
    public function switchToAllLocations(User $user): void
    {
        if (! $user->canViewAllLocations()) {
            throw new AuthorizationException;
        }

        $this->store(self::ALL_LOCATIONS);
    }

    /**
     * Discard anything resolved earlier in this request.
     */
    public function forget(): void
    {
        $this->request->attributes->remove(self::MEMO_KEY);
    }

    private function resolve(User $user): ?LocationContext
    {
        $stored = $this->session->get(self::SESSION_KEY);

        if ($stored === self::ALL_LOCATIONS && $user->canViewAllLocations()) {
            return LocationContext::allLocations();
        }

        $options = $this->options($user);

        $location = is_int($stored) ? $options->find($stored) : null;

        // Nothing valid is remembered: land on an authorized location, never on "all".
        $location ??= $options->first();

        if ($location === null) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        $this->session->put(self::SESSION_KEY, $location->id);

        return LocationContext::forLocation($location);
    }

    private function store(int|string $value): void
    {
        $this->session->put(self::SESSION_KEY, $value);

        $this->forget();
    }

    /**
     * Get the per-request memo for the staff member, starting a new one for a different person.
     */
    private function memo(User $user): LocationMemo
    {
        $memo = $this->request->attributes->get(self::MEMO_KEY);

        if (! $memo instanceof LocationMemo || $memo->userId !== $user->id) {
            $memo = new LocationMemo($user->id);

            $this->request->attributes->set(self::MEMO_KEY, $memo);
        }

        return $memo;
    }
}
