<?php

namespace App\Http\Controllers;

use App\Support\Navigation\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NavigationLandingController extends Controller
{
    public function __invoke(Request $request, Navigation $navigation): RedirectResponse
    {
        $settings = $navigation->find('settings') ?? abort(404);

        abort_unless($navigation->allows($request->user(), $settings), 403);

        // A page inside Settings first, so its menu shows (decision 202). Locations sits
        // beside Settings in the Company menu, so it is only the last resort.
        foreach (['settings.users', 'settings.lists', 'settings.contact_center', 'settings.pricing', 'settings.integrations', 'settings.audit_log', 'settings.locations'] as $id) {
            $destination = $navigation->find($id) ?? abort(404);

            if ($destination->route !== null && $navigation->allows($request->user(), $destination)) {
                return to_route($destination->route);
            }
        }

        abort(403);
    }
}
