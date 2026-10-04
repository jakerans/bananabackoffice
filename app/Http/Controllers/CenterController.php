<?php

namespace App\Http\Controllers;

use App\Support\Navigation\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CenterController extends Controller
{
    /**
     * Show a center's landing page: what is available now and what is coming.
     */
    public function __invoke(Request $request, Navigation $navigation, string $center): View
    {
        $center = $navigation->center($center) ?? abort(404);

        abort_unless($navigation->allows($request->user(), $center), 403);

        return view('centers.show', ['center' => $center]);
    }
}
