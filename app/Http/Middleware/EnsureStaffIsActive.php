<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffIsActive
{
    /**
     * Sign out a staff member whose account has been deactivated.
     *
     * Runs on every web request, including Livewire updates, so deactivation
     * takes effect on the person's next action whatever way they signed in.
     *
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     * @throws ValidationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('status', __('This account has been deactivated. Please contact an administrator.'));

            throw new AuthenticationException(redirectTo: route('login'));
        }

        $this->refuseTwoFactorChallengeForDeactivatedStaff($request);

        return $next($request);
    }

    /**
     * End a two-factor sign-in that is waiting for its code if the account has been deactivated.
     *
     * After a correct password, Fortify remembers who is signing in under
     * "login.id" until their code is checked. Refusing here, before the code is
     * looked at, means a deactivated person is never signed in at all.
     *
     * @throws ValidationException
     */
    private function refuseTwoFactorChallengeForDeactivatedStaff(Request $request): void
    {
        if (! $request->hasSession() || ! $request->session()->has('login.id')) {
            return;
        }

        $challengedStaffMember = User::query()->find($request->session()->get('login.id'));

        if (! $challengedStaffMember instanceof User || $challengedStaffMember->isActive()) {
            return;
        }

        $request->session()->forget(['login.id', 'login.remember']);

        throw ValidationException::withMessages([
            Fortify::username() => __('This account has been deactivated. Please contact an administrator.'),
        ])->redirectTo(route('login'));
    }
}
