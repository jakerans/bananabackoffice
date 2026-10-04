<?php

namespace App\Http\Controllers;

use App\Models\Location;
use App\Support\Access\CurrentLocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SwitchLocationController extends Controller
{
    /**
     * Change the location the staff member is working in.
     *
     * @throws AuthorizationException
     */
    public function __invoke(Request $request, CurrentLocation $currentLocation): RedirectResponse
    {
        $validated = $request->validate([
            'location' => ['required', 'string', 'regex:/^(all|[1-9][0-9]*)$/'],
        ]);

        $user = $request->user();

        if ($validated['location'] === CurrentLocation::ALL_LOCATIONS) {
            $currentLocation->switchToAllLocations($user);

            return to_route('dashboard');
        }

        // An unknown location is refused exactly like an unauthorized one, so the response reveals nothing.
        $location = Location::query()->find((int) $validated['location']) ?? throw new AuthorizationException;

        $currentLocation->switchTo($user, $location);

        return to_route('dashboard');
    }
}
