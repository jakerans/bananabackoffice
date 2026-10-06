<?php

namespace App\Http\Middleware;

use App\Actions\Staff\StopImpersonation;
use App\Enums\ImpersonationEndReason;
use App\Models\User;
use App\Support\Access\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApplyImpersonation
{
    /**
     * Signing out, and returning to yourself, are always done as the person who is really signed in.
     */
    private const array ROUTES_SERVED_AS_THE_OWNER = ['logout', 'impersonation.stop'];

    public function __construct(
        private Impersonation $impersonation,
        private StopImpersonation $stopImpersonation,
    ) {}

    /**
     * Serve the request as the staff member an Owner is viewing Backoffice as.
     *
     * Runs on every web request, including Livewire updates. The session belongs
     * to the Owner and only remembers a choice, which is checked again here each
     * time: the person signed in must still be an Owner who may do this, and the
     * staff member must still be active. If either has changed, impersonation
     * ends on this request.
     *
     * While it holds, the signed-in user for the rest of the request is the staff
     * member, so every permission, policy, location and menu is worked out for
     * them alone. The Owner's identity is put back once the response is ready,
     * so the session never stops being the Owner's.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->impersonation->forgetRequest();

        if (! $request->hasSession()) {
            return $next($request);
        }

        $remembered = $this->impersonation->remembered();

        if ($remembered === null) {
            return $next($request);
        }

        $owner = $request->user();

        // The choice only means something in the session of the Owner who made it.
        if (! $owner instanceof User || $owner->id !== $remembered['impersonator_id']) {
            $request->session()->forget(Impersonation::SESSION_KEY);

            return $next($request);
        }

        if ($request->routeIs(self::ROUTES_SERVED_AS_THE_OWNER)) {
            return $next($request);
        }

        $staff = User::query()->find($remembered['staff_id']);

        if (! $owner->isUsableOwner()) {
            $this->stopImpersonation->handle(ImpersonationEndReason::OwnerNoLongerAuthorized);

            // A deactivated Owner is signed out by the next check. One who is merely no longer an Owner carries on as themselves.
            return $owner->isActive()
                ? $this->backToYourself($request, __('You are no longer an Owner, so you are back as yourself.'))
                : $next($request);
        }

        if ($staff === null || $this->impersonation->refusalFor($owner, $staff) !== null) {
            $this->stopImpersonation->handle(ImpersonationEndReason::StaffMemberUnavailable);

            return $this->backToYourself($request, __(':name can no longer be viewed as, so you are back as yourself.', ['name' => $staff->name ?? __('That staff member')]));
        }

        Auth::setUser($staff);

        $this->impersonation->serveAs($owner, $staff);

        try {
            return $next($request);
        } finally {
            Auth::setUser($owner);

            $this->impersonation->forgetRequest();
        }
    }

    /**
     * Answer a request that can no longer be served as the staff member.
     *
     * It is not carried out as the Owner instead: the page it came from was the
     * staff member's. A Livewire update is told its page has expired, which
     * reloads it; anything else goes home, where the notice is shown.
     */
    private function backToYourself(Request $request, string $notice): Response
    {
        $request->session()->flash('impersonation_ended', $notice);

        if ($request->hasHeader('X-Livewire')) {
            return response('', 419);
        }

        return redirect()->route('dashboard');
    }
}
