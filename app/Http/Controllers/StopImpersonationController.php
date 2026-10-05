<?php

namespace App\Http\Controllers;

use App\Actions\Staff\StopImpersonation;
use App\Enums\ImpersonationEndReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StopImpersonationController extends Controller
{
    /**
     * Return to being yourself.
     *
     * This request is always handled as the person who is really signed in. The
     * Owner lands back on the staff member they were viewing as, if they may
     * still open that page.
     */
    public function __invoke(Request $request, StopImpersonation $stopImpersonation): RedirectResponse
    {
        $staff = $stopImpersonation->handle(ImpersonationEndReason::Returned);

        return $staff !== null && $request->user()->can('update', $staff)
            ? redirect()->route('settings.users.show', $staff)
            : redirect()->route('dashboard');
    }
}
