# Slice 30 — Production Readiness: Plan

Status: **proposed on 2026-10-26 as slice 29; renumbered 30 when Jacob
asked for it to wait until he is back (decision 191); approved on
2026-10-27 with the recommendation on every question (decision 193). Step
30a is built and merged (decision 194); 30b is done except QuickBooks's
live keys, which wait for Intuit's review; 30c is planned below and goes
ahead, except first use, which waits (decision 215).** Written as
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

## Step 30b as done

Jacob set up the production server from the step-by-step guide on
2026-10-27, at `app.bananapainting.com` (decision 198). On its first full
run the production check said **ready** for every setting except
QuickBooks's live keys, which wait for Intuit to review the app (guide
step 16; Backoffice works without them). Intuit's form asks for a
license agreement and a privacy policy; Backoffice's own public pages,
`/legal/terms` and `/legal/privacy`, are given (decision 201).

**Found while doing it:**

- Forge switches MySQL's change log off on new servers; it is switched on
  with a Forge recipe run as root, since DigitalOcean's own console does not
  work on Forge's servers.
- The Laravel Scheduler switch did not show on the site, so the
  every-minute job was added by hand under the site's Processes >
  Scheduler, pointing at the site's `current` folder. The check said ready
  six minutes later with nothing run by hand.
- The check ends as a failed command in Forge while anything says not yet;
  that is on purpose.

**Still to check by hand, in 30c:** the hourly backups and their first
copy, the nightly copy of files, the restore drill, other Wasabi keys, and
whether the repository goes back to private (decision 140).

## Step 30c: the plan (proposed 2026-10-31)

Checked against what is really on the servers, and against every "before
production" promise in the documents. The table near the top of this plan
was written before estimating existed, so first use now needs more than it
says.

Answered on 2026-10-31 (decision 215): everything goes ahead but the
repository, which stays public, and first use, which waits. Real Locations,
staff and customers go in only after the app has been walked through on
staging's Test Location and polished.

### What the check found

1. **Staging's database backups are failing.** Every daily backup since
   2026-10-28 has failed with an empty file; the last good one is from
   2026-10-27. Forge says only "failed". The server has plenty of disk
   space, so that is not the cause. Staging and production use one storage
   provider in Forge, not one each as the guide says, and the failures
   began the morning after production's were set up: the likely cause is
   that the provider's key now reaches only production's bucket. Forge will
   not show it, so this is the best reading, not proof. Nobody noticed for
   four days.

   **Production's backups run:** every hourly backup has succeeded. But
   Forge's "retention" counts backups, not days, so 30 reaches back about
   31 hours, not the 30 days of decision 193. It should be 720.
2. **The clean-up of the file copy is owed** (decision 195): the copy keeps
   files Backoffice has removed, for good, against the 60 days decision 157
   promises.
3. **A signed agreement shows the company's time, not the Location's.** An
   Albuquerque customer signing at 4 pm would read 6 pm Eastern, on the page
   and in their email.
4. **An empty production cannot sell.** It starts with the lists (lead
   sources, reasons, services) but no Locations, prices, rates, areas, line
   items, discounts, tax rates, agreement wording, pay plans, service areas
   or subcontractors.
5. **The first Owner cannot be created from Forge's Commands tab:**
   `backoffice:create-owner` asks questions, and that tab cannot answer
   them. It runs in a terminal connected to the server, which is also how
   the saved PaintScout file gets there for the imports.
6. **A dependency warning was due before production** (security-notes.md):
   three critical warnings and a new high one remain in the build tools.
   The fix has moved on from the one recorded: `vite-plus` needs a current
   1.x, and production builds with them on the server at each deploy.
7. **Production is behind.** It holds what Jacob last deployed, from before
   slices 31 to 36, so its check, the imports and customer email are not
   there yet.

Still sound: CI, staging's disk, and production answering at its address.
Leads reach Backoffice by being typed in; nothing arrives from the website.

### The steps

Each stops for Jacob's review, like any other.

| Step | Delivers | Who |
| --- | --- | --- |
| 30c-1 | **Backups working again.** Find why staging's fail, and read production's. Nothing else is proved until these run. | Jacob reads Forge's failure emails and the storage provider; the agent narrows the cause |
| 30c-2 | **What is owed in the code,** built, tested and merged: the removed-file clean-up in the copy, with a delete-only key, so a stolen key still cannot destroy it; the Location's time on the agreement; the production check brought up to date (Wasabi's copy, customer email, and a "ready to sell" list of the empty things above); a way to leave the file drill's test file in place long enough to see it reach the copy; the dependency change, if approved. | The agent |
| 30c-3 | **Recovery proved on staging.** A written recovery procedure (who does what, in what order, how to confirm, what to do if it fails partway). A fresh backup, a known change, Forge's Restore, then proof the change is gone, the site works and the tables match. Procedure corrected from what happened. | The agent, with Jacob's go-ahead for the Restore |
| 30c-4 | **Recovery proved on production, while it is empty.** Deploy the latest code. Restore a production backup; put the database back to a chosen minute with the change log; the file drill, including seeing the test file in the copy. Key check in Wasabi. | Jacob presses each button, from a guide with text to copy |
| 30c-5 | **First use; waits (decision 215).** The Owner created, two-factor set up, recovery codes saved. The Locations added with their right time zones. Prices and agreement wording imported. Tax rates, pay plans, service areas and targets checked. Customer email's sending domain and settings. The production check clean, and its by-hand list signed off. Then staff invited. | Jacob, from the same guide |
| Later | **QuickBooks** for each Location, when Intuit approves. It does not hold up first use. | Jacob |

The guide is a private page like 30b's, with no key or password in it.

### Known limits

- **Production and staging run PHP 8.5; the checks run 8.4.** Staging has
  run on it without trouble, but the tests themselves do not.
- **"Any minute" holds only while the server lives.** The change log is on
  the server's own disk. It undoes a mistake to the minute; if the whole
  server is lost, the last hourly backup is the limit, so up to an hour.
- **Nobody is told when Backoffice has an error.** It goes to the server's
  log, and Forge's backup failures go to one email address. This plan does
  not change that; a small step could follow first use.
- **Restoring a whole server is written down, not rehearsed:** the drills
  prove the backups and the procedure, not building a new server.
- **The copy's restore is proved on one test file,** not by switching
  Backoffice over to the copy bucket.
