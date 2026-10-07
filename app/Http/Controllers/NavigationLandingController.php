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

        foreach (['settings.locations', 'settings.users', 'settings.lists'] as $id) {
            $destination = $navigation->find($id) ?? abort(404);

            if ($destination->route !== null && $navigation->allows($request->user(), $destination)) {
                return to_route($destination->route);
            }
        }

        abort(403);
    }
}
