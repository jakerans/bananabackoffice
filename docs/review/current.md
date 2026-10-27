# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-27 · **Branch:** `main`

**State:** slice 30, Production Readiness, is approved (decision 193).
**Step 30a is merged (decision 194).** 30b waits for Jacob's answer on
whether production comes before more of what is still unbuilt.

## What to review

Plan and "Step 30a as built":
[../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md).

- Owner, Leadership and Finance must set up two-factor sign-in before
  anything else, and cannot switch it off.
- A server command resets it for someone who lost their phone and recovery
  codes, recorded in the Audit Log.
- `php artisan backoffice:production-check` says whether a server is safe
  for real data.

## In the browser (Jacob, after merging, on staging)

Merging puts the requirement on staging. At the next sign-in as the Owner:

1. Backoffice opens Settings > Security, asking for the password first.
2. Press "Enable 2FA", scan the code with an authenticator app (such as
   Google Authenticator or 1Password), press Continue and type the six digits.
3. Press "View recovery codes" and keep them in the password manager.
4. Sign out and in again: it asks for the six digits.

## Decisions

None open for 30a. Before 30c puts real data in, decision 140 is still
open: whether the repository goes back to private.

## Next, step 30b (needs Jacob)

The server runbook, then: a second Forge server, its own database account,
the production bucket and key, email, `backoffice.bananapainting.com` with
HTTPS, the scheduler and the backups. Jacob creates and pays; the
implementation agent writes the steps and checks the result.

## Noted separately

On a phone, the header's Location and account menus are wider than the
screen on every page, so the page can scroll sideways. Offered as its own
small fix.
