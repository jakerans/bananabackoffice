# Slice 30 — Production Readiness: Plan

Status: **proposed on 2026-10-26 as slice 29; renumbered 30 when Jacob
asked for it to wait until he is back (decision 191); approved on
2026-10-27 with the recommendation on every question (decision 193). Step
30a is built and merged (decision 194); 30b waits for Jacob.** Written as
what the implementation agent recommended from the plan check below.

## Why this is next (the plan check)

Checked against [product-architecture.md](product-architecture.md) and the
roadmap after slice 28:

1. **A job runs end to end in Backoffice.** Every Center opens on a live
   board, and a Lead can be followed from first call to financial close,
   pay and warranty (slices 1 to 28).
2. **What is still Coming Soon does not stop Banana using it:** the
   Reports pages (4.6, 5.9, 9.7), Inbox and Campaigns (4.3, 4.4, which need
   texting and email providers), the Payments and Payouts tabs (9.2), Color
   Studio's requests and catalog, and smaller Settings pages.
3. **Real work can only happen on production.** Real customer data is
   never entered into staging, and Banana's real QuickBooks companies are
   connected only on production (decision 189).
4. **Production's safeguards are written but not met** (roadmap,
   "Historical production-readiness plan" and "Still open"): its own server
   and database account, backups and a restore drill, a backup of uploaded
   files, the real domain, email, and three open questions.

What else could be next, and why not first: the Reports pages. They are
useful and can follow straight after, but are not needed before real use.
Settings > Audit Log, the other alternative, was built meanwhile as slice
29 (decisions 191 and 192), so production now has a page showing every
recorded change.

## What slice 30 is, and is not

Three reviewed steps.

| Step | Delivers |
| --- | --- |
| 30a | **What Backoffice itself needs for production.** Two-factor sign-in required for the roles Jacob names; Owner impersonation as he decides; a `backoffice:production-check` command that says whether a server is safe for real data: debugging off, HTTPS, email, private file storage, the scheduler running, backups configured, QuickBooks set to live keys. |
| 30b | **The production server.** A written runbook, then the server set up step by step: its own server in Forge, its own least-privilege database account, its own private file storage and key, email from Banana's domain, the real domain with HTTPS, the scheduler, and stricter backups. Jacob does every step that creates an account, pays for something or handles a key; the implementation agent writes the steps and checks the result without changing anything. |
| 30c | **Proof it can be recovered, then first use.** The restore drill on staging, following a written recovery procedure; the backup of uploaded files checked the same way; then the first Owner created on production from the command line, the three Locations added, and each Location's real QuickBooks company connected. |

Not in slice 30:

- **Moving existing data in:** there is none to import (roadmap, "Existing
  data").
- **Turning off staging:** it stays, with fictitious data only, for every
  later slice.
- **New features.**

## Decisions needed

1. **Is production next?** *Recommended:* yes, in the three steps above.
   The alternative is Reports first.
2. **The production address.** *Recommended:* a part of Banana's own
   domain, such as `backoffice.` followed by it. Jacob names it; he or
   whoever runs the domain adds one DNS record.
3. **Two-factor sign-in.** *Recommended:* required for Owner, Leadership
   and Finance, who can see every Location's money and people; offered to
   everyone else. Someone with a required role who has not set it up is
   sent to set it up before anything else.
4. **Owner impersonation on production.** *Recommended:* keep it as it is
   (decision 46): Owner only, with the banner always on screen and every
   start and stop audited.
5. **The server.** *Recommended:* a second Forge server on DigitalOcean,
   the same size as staging to start, in the same region. Jacob creates it
   and pays for it.
6. **Backups.** *Recommended:* the database every hour, kept 30 days, with
   MySQL's change log on so it can be put back to any minute; uploaded
   files copied every night to a separate private space, kept 30 days.
7. **QuickBooks on production.** *Recommended:* Jacob answers Intuit's
   questionnaire for live keys and adds the production Redirect URI once the
   address is known; the keys go into the production server's settings by
   him.

## Known limits of this plan

- **Steps that need Jacob:** creating the server, paying, the domain, email
  set-up, keys, and Intuit's questionnaire. The slice cannot finish without
  them.
- **The restore drill overwrites staging** while it runs; its fictitious
  data comes back from the backup taken just before.

## Step 30a as built

**Two-factor sign-in is required for Owner, Leadership and Finance**
(question 3), held anywhere:

- Someone who holds one of these roles and has not set it up is sent to
  Settings > Security from any page, with a notice saying why. They can still
  confirm their password, set up two-factor sign-in or a passkey, verify their
  email and sign out; any other page, and any action on a page opened
  earlier, is refused until it is set up.
- Once set up, it cannot be switched off: the Security page says it stays on
  and points to the recovery codes, and switching it off is refused however
  it is asked for.
- A passkey does not replace it: a password sign-in always asks for the code.
- An Owner viewing Backoffice as someone is not asked to set it up for them
  (question 4, decision 46 unchanged).
- `php artisan backoffice:two-factor-reset <email>` switches it off for
  someone who has lost their phone and their recovery codes, with a reason
  recorded in the Audit Log ("Staff member · Two factor reset"). They set it
  up again at their next sign-in.
- It is on unless `AUTH_REQUIRE_TWO_FACTOR=false`; only a developer's own
  computer and the tests switch it off.

**`php artisan backoffice:production-check`** says whether a server is safe
for real data. It reads settings and never changes one or prints a key. It
reports on: a production environment; debugging off; an HTTPS address and
HTTPS-only sign-in cookies; database sessions; required two-factor sign-in;
MySQL, with an account of its own (not `forge` or `root`) and its change log
on; the scheduler having run in the last five minutes; email through Resend
from `mail.bananapainting.com`; uploaded files in a private bucket that is
not staging's; and QuickBooks on live keys. It then lists what only a person
can check: the hourly database backups, the nightly copy of files, the
restore drill, other Wasabi keys, and whether the repository goes back to
private (decision 140). It ends "not ready" while any setting is wrong.

**Decisions made while building:** a passkey does not count instead of
two-factor sign-in, because a password sign-in would then ask for nothing
more; and the reset is a server command, not a button, so turning off
someone's second factor needs access to the server.

**Known limits:** staging gets the requirement when this is merged, so
Jacob's staging Owner account must set up two-factor sign-in at its next
sign-in. The production check cannot see Forge's backups or Wasabi's
settings; those stay on its list to check by hand.
