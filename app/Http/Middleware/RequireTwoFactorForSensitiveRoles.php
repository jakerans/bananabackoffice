<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Access\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactorForSensitiveRoles
{
    /**
     * Where someone who must set up two-factor sign-in may still go: the
     * Security page, the password confirmation it asks for, the sign-in
     * packages' two-factor and passkey addresses, email verification and
     * signing out.
     */
    private const array SETUP_ROUTES = [
        'security.edit',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'two-factor.*',
        'verification.*',
        'logout',
    ];

    private const array SETUP_ADDRESSES = [
        'user/*',
        'passkeys/*',
    ];

    public function __construct(private Impersonation $impersonation) {}

    /**
     * Send an Owner, Leadership or Finance member who has not set up two-factor
     * sign-in to the Security page before anything else (decision 193,
     * question 3), and keep those who have it from switching it off.
     *
     * While an Owner is viewing Backoffice as someone, the Owner has already
     * signed in with two factors, and that person's own sign-in details are
     * closed to them, so nothing is asked here.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $this->impersonation->isActive() || ! $user->mustUseTwoFactor()) {
            return $next($request);
        }

        abort_if($request->routeIs('two-factor.disable'), 403, __('Your role must use two-factor sign-in, so it cannot be switched off.'));

        if (! $user->hasEnabledTwoFactorAuthentication() && ! $this->isSettingUp($request)) {
            abort_if($request->expectsJson() || Livewire::isLivewireRequest() || ! $request->isMethod('GET'), 403, __('Set up two-factor sign-in on the Security page to continue.'));

            return redirect()->route('security.edit');
        }

        return $next($request);
    }

    /**
     * Whether the request is part of setting up two-factor sign-in.
     */
    private function isSettingUp(Request $request): bool
    {
        if ($request->routeIs(self::SETUP_ROUTES) || $request->is(self::SETUP_ADDRESSES)) {
            return true;
        }

        if (Livewire::isLivewireRequest()) {
            $securityPage = trim(route('security.edit', absolute: false), '/');
            $components = collect((array) $request->input('components', []));

            // Every component in the update must belong to the Security page. A
            // snapshot's page cannot be forged: Livewire refuses an altered one.
            return $components->isNotEmpty() && $components->every(fn (mixed $component): bool => is_array($component)
                && trim((string) data_get(json_decode((string) ($component['snapshot'] ?? ''), true), 'memo.path'), '/') === $securityPage);
        }

        return false;
    }
}
