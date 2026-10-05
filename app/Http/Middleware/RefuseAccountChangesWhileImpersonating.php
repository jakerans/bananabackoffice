<?php

namespace App\Http\Middleware;

use App\Support\Access\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RefuseAccountChangesWhileImpersonating
{
    /**
     * The sign-in and security addresses that act on "the signed-in person":
     * password confirmation, two-factor codes, passkeys and email verification.
     */
    private const array ACCOUNT_ADDRESSES = [
        'user/*',
        'passkeys/*',
        'email/verification-notification',
        'email/verify/*',
    ];

    public function __construct(private Impersonation $impersonation) {}

    /**
     * Keep an Owner who is viewing Backoffice as a staff member away from that
     * person's sign-in details.
     *
     * These addresses belong to the sign-in packages and change the password
     * confirmation, two-factor codes, passkeys or verified email of whoever is
     * signed in. While impersonating, that would be the staff member, so every
     * one of them is refused. The account pages of Backoffice itself refuse in
     * the same way from inside each page.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is(self::ACCOUNT_ADDRESSES)) {
            $this->impersonation->refuseAccountChanges();
        }

        return $next($request);
    }
}
