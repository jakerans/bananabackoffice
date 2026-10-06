<?php

namespace App\Support\Access;

use App\Models\User;
use Illuminate\Support\Facades\Session;

/**
 * An Owner viewing Backoffice as another staff member.
 *
 * The signed-in session always belongs to the Owner. It only remembers who is
 * being viewed as. On each request that choice is checked again and, if it still
 * holds, the request is served as that staff member and nobody else: every
 * permission, location and menu is theirs, and none of the Owner's are added.
 *
 * Nothing here touches the staff member's password or any other sign-in detail.
 */
class Impersonation
{
    public const string SESSION_KEY = 'backoffice.impersonation';

    /**
     * What belongs to the person a session is being used as, and so must not be
     * carried from one identity to the other: a recently confirmed password, and
     * the location and center last worked in.
     */
    private const array PERSONAL_SESSION_KEYS = [
        'auth.password_confirmed_at',
        CurrentLocation::SESSION_KEY,
        'backoffice.center',
    ];

    /** The real signed-in Owner, while the current request is being served as someone else. */
    private ?User $impersonator = null;

    /** The staff member the current request is being served as. */
    private ?User $impersonated = null;

    /**
     * Why this Owner may not view Backoffice as this staff member, or null when they may.
     *
     * The same rules are checked when impersonation starts and again on every
     * request while it lasts, so a change to either person takes effect at once.
     */
    public function refusalFor(User $owner, User $staff): ?string
    {
        return match (true) {
            ! $owner->isUsableOwner() => 'Only an Owner can view Backoffice as another staff member.',
            $owner->is($staff) => 'You cannot view Backoffice as yourself.',
            ! $staff->isActive() => "{$staff->name} is deactivated, so there is nothing to view as them.",
            $staff->invitationIsPending() => "{$staff->name} has not accepted their invitation yet, so there is nothing to view as them.",
            default => null,
        };
    }

    /**
     * Remember, in the Owner's own session, who they are viewing Backoffice as.
     *
     * The session gets a new ID so nothing issued before can be reused, and
     * nothing personal to the Owner is carried into the other identity.
     */
    public function start(User $owner, User $staff): void
    {
        Session::forget(self::PERSONAL_SESSION_KEYS);
        Session::put(self::SESSION_KEY, ['impersonator_id' => $owner->id, 'staff_id' => $staff->id]);
        Session::regenerate(destroy: true);
    }

    /**
     * Forget who was being viewed as, returning the session to the Owner alone.
     */
    public function stop(): void
    {
        Session::forget([self::SESSION_KEY, ...self::PERSONAL_SESSION_KEYS]);
        Session::regenerate(destroy: true);

        $this->forgetRequest();
    }

    /**
     * What the session remembers, or null when it remembers nothing usable.
     *
     * @return array{impersonator_id: int, staff_id: int}|null
     */
    public function remembered(): ?array
    {
        $state = Session::get(self::SESSION_KEY);

        if (! is_array($state) || ! is_int($state['impersonator_id'] ?? null) || ! is_int($state['staff_id'] ?? null)) {
            return null;
        }

        return ['impersonator_id' => $state['impersonator_id'], 'staff_id' => $state['staff_id']];
    }

    /**
     * Note that the current request is being served as a staff member on behalf of an Owner.
     */
    public function serveAs(User $impersonator, User $staff): void
    {
        $this->impersonator = $impersonator;
        $this->impersonated = $staff;
    }

    /**
     * Forget what was noted for the current request.
     */
    public function forgetRequest(): void
    {
        $this->impersonator = null;
        $this->impersonated = null;
    }

    /**
     * Whether the current request is being served as someone other than the person signed in.
     */
    public function isActive(): bool
    {
        return $this->impersonator !== null;
    }

    /**
     * The real signed-in Owner, when the current request is being served as someone else.
     */
    public function impersonator(): ?User
    {
        return $this->impersonator;
    }

    /**
     * The staff member the current request is being served as.
     */
    public function impersonated(): ?User
    {
        return $this->impersonated;
    }

    /**
     * Refuse to show or change a person's own sign-in details while they are being viewed as.
     *
     * Impersonation is for seeing the application as someone does. It is never a
     * way to change their name, email, password, two-factor codes or passkeys.
     */
    public function refuseAccountChanges(): void
    {
        abort_if($this->isActive(), 403, __('Account and security settings are closed while you are viewing Backoffice as someone else.'));
    }

    /**
     * Refuse to manage staff accounts and access while viewing as someone else.
     *
     * An Owner manages staff as themselves, so a change to anyone's access is
     * always made, and recorded, in their own name.
     */
    public function refuseStaffAdministration(): void
    {
        abort_if($this->isActive(), 403, __('Staff accounts and access cannot be managed while you are viewing Backoffice as someone else.'));
    }
}
