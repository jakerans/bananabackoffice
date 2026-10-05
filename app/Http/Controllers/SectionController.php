<?php

namespace App\Http\Controllers;

use App\Support\Navigation\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    /**
     * Show the page for a section that is not built yet.
     *
     * Only a center's primary section is routed here, so the center has somewhere
     * to open. The page states that the section is coming and shows nothing else.
     */
    public function __invoke(Request $request, Navigation $navigation, string $section): View
    {
        $section = $navigation->find($section) ?? abort(404);

        abort_unless($navigation->allows($request->user(), $section), 403);

        return view('sections.show', [
            'section' => $section,
            'center' => $navigation->centerForRoute($section->route) ?? abort(404),
        ]);
    }
}
