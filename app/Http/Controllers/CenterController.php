<?php

namespace App\Http\Controllers;

use App\Support\Navigation\Navigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    /**
     * Open a center on its primary section. A center has no page of its own.
     */
    public function __invoke(Request $request, Navigation $navigation, string $center): RedirectResponse
    {
        $center = $navigation->destination($center) ?? abort(404);

        abort_unless($navigation->allows($request->user(), $center), 403);

        $route = $navigation->landingFor($request->user(), $center)->route ?? abort(403);

        return to_route($route);
    }
}
