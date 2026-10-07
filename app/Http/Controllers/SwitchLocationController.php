<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavigationItem;
use App\Support\Navigation\Shell;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class SwitchLocationController extends Controller
{
    /**
     * Change the location the staff member is working in.
     *
     * @throws AuthorizationException
     */
    public function __invoke(Request $request, CurrentLocation $currentLocation, Navigation $navigation, Router $router): RedirectResponse
    {
        $validated = $request->validate([
            'location' => ['required', 'string', 'regex:/^(all|[1-9][0-9]*)$/'],
        ]);

        $user = $request->user();

        if ($validated['location'] === CurrentLocation::ALL_LOCATIONS) {
            $currentLocation->switchToAllLocations($user);
        } else {
            // An unknown location is refused exactly like an unauthorized one, so the response reveals nothing.
            $location = Location::query()->find((int) $validated['location']) ?? throw new AuthorizationException;

            $currentLocation->switchTo($user, $location);
        }

        return redirect()->to($this->destination($request, $user, $navigation, $router));
    }

    /**
     * Stay in the remembered destination when it still owns the previous route
     * and remains authorized in the new location. Only our own routes are used.
     */
    private function destination(Request $request, User $user, Navigation $navigation, Router $router): string
    {
        try {
            $previous = $router->getRoutes()->match(Request::create(url()->previous()));
        } catch (HttpExceptionInterface) {
            return route('dashboard');
        }

        $candidates = array_values(array_filter(
            $navigation->destinationsForRoute($previous->getName()),
            fn (NavigationItem $destination): bool => $navigation->allows($user, $destination),
        ));

        if ($candidates === []) {
            return route('dashboard');
        }

        $lastDestinationId = $request->session()->get(Shell::LAST_CENTER_KEY);

        if ($lastDestinationId === 'settings') {
            $lastDestinationId = 'company';
        }

        $destination = collect($candidates)->firstWhere('id', $lastDestinationId) ?? $candidates[0];

        $request->session()->put(Shell::LAST_CENTER_KEY, $destination->id);

        return $destination->route !== null ? route($destination->route) : route('dashboard');
    }
}
