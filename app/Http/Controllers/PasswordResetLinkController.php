<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Fortify;

use function Illuminate\Support\defer;

class PasswordResetLinkController extends Controller
{
    /**
     * Accept a request for a password reset link without revealing whether the account exists.
     *
     * Every well-formed request gets the same answer. The link is prepared and
     * emailed only after that answer has been sent, so neither the wording, an
     * error, nor how long the request takes shows whether the address belongs to
     * an account, whether a link was sent recently, or whether the email failed.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            Fortify::email() => ['required', 'string', 'email'],
        ]);

        $email = User::normalizeEmail($validated[Fortify::email()]);

        defer(fn () => Password::broker(config('fortify.passwords'))->sendResetLink([Fortify::email() => $email]));

        return back()->with('status', __('If that email address belongs to an account, we have emailed a password reset link.'));
    }
}
