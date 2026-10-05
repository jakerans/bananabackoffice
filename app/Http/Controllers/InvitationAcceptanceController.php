<?php

namespace App\Http\Controllers;

use App\Actions\Staff\AcceptInvitation;
use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvitationAcceptanceController extends Controller
{
    use PasswordValidationRules;

    /**
     * Show the form where an invited staff member chooses their password.
     */
    public function create(Request $request, string $token): View
    {
        return view('pages::auth.accept-invitation', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    /**
     * Accept the invitation and set the staff member's own password.
     *
     * @throws ValidationException
     */
    public function store(Request $request, AcceptInvitation $acceptInvitation): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => $this->passwordRules(),
        ]);

        if (! $acceptInvitation->handle($validated['email'], $validated['token'], $validated['password'])) {
            // One message for every failure, so the form never reveals which accounts exist.
            throw ValidationException::withMessages([
                'email' => __('This invitation link is invalid or has expired. Please ask an administrator to send a new one.'),
            ]);
        }

        return to_route('login')->with('status', __('Your password has been set. You can now log in.'));
    }
}
