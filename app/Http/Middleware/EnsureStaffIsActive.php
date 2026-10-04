<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        return $next($request);
    }
}
