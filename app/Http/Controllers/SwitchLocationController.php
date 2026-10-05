<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Models\User;
use App\Support\Access\CurrentLocation;
use App\Support\Navigation\Navigation;
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

        return redirect()->to($this->destination($user, $navigation, $router));
    }

    /**
     * Stay in the center the staff member came from when they may still open it
     * in the new location; otherwise go home. Only our own routes are ever used.
     */
    private function destination(User $user, Navigation $navigation, Router $router): string
    {
        try {
            $previous = $router->getRoutes()->match(Request::create(url()->previous()));
        } catch (HttpExceptionInterface) {
            return route('dashboard');
        }

        $center = $navigation->centerForRoute($previous->getName());

        return $center !== null && $center->route !== null && $navigation->allows($user, $center)
            ? route($center->route)
            : route('dashboard');
    }
}
