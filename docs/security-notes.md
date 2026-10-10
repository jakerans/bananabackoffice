# Security Notes

This document keeps the findings of security reviews so that nothing is lost between
checkpoints. Each open item says when it has to be dealt with.

## Review of Checkpoint A (2026-10-04)

The whole `checkpoint-a` branch was reviewed against `main` before merging: once by
the author and separately by two independent reviewers who were given the code and
the rules, not the author's conclusions. Each finding below was reproduced with a
test unless it says otherwise.

### What held up

- Access never leaked between locations. A single-location grant was never treated
  as company-wide, and a tampered or missing session value never became "all
  locations".
- Every center, section and settings page refuses unauthorized requests made by
  typing the address, and every Livewire action re-checks permission on each call.
- No way was found to raise one's own access. Roles, active status and scope
  cannot be set from any form.
- Invitation links are hashed, single-use, expire, are tied to the email address,
  and are rejected on the password reset form and for deactivated accounts.

### Fixed before merge

| # | Finding | Fix |
| --- | --- | --- |
| 1 | An email saved with a capital letter could not sign in, and inviting or creating an Owner with a capitalised duplicate crashed. | Every email is stored and compared in lowercase. See decisions.md, 16. |
| 2 | The "always one Owner" rule counted Owners who could not sign in, such as an unaccepted invitation. | The rule now counts only usable Owners. See decisions.md, 17. |
| 3 | Deactivating someone left their remembered login intact, so reactivating revived it. | Deactivation ends stored sessions and replaces the remember-me token. See decisions.md, 18. |
| 4 | An invitation email the mailer refused crashed the page and left an account behind. | Inviting is all or nothing, for any mail failure. See decisions.md, 19. |
| 5 | Signing in no longer upgraded an outdated password hash. | Restored, after the deactivated-account check. |
| 6 | The demo seeder could create known-password accounts if run by name on a server. | The seeder itself refuses to run outside local development. |
| 7 | The database could not stop two identical all-locations grants. | A database-calculated column and unique index now do. See decisions.md, 20. |
| 10 | Someone invited who used "forgot password" stayed marked "Invited" with a live invitation link. | Resetting a password now accepts the invitation and retires its link. |

### Resolved before Checkpoint B (2026-10-04)

Findings 8 and 9 are **resolved**: fixed, approved by the owner, merged into `main`
and deployed to staging. Both were found in the review above and deferred at the
time. Each fix has tests that were also run against the old code to confirm they
fail without it.

**8. Resolved. "Forgot password" revealed whether an email address has an account,
and was not rate-limited per visitor.**
An unknown address got "We can't find a user with that email address"; a known one
got "We have emailed your password reset link".
Fixed: every well-formed request now gets the same answer, including when a link
was sent moments ago and when the email fails to send. The work happens after the
answer has been sent, so the time taken does not give it away either. Requests are
limited to 6 a minute per visitor. See decisions.md, 24.
Checked on staging after deployment:

- An existing account and a made-up address got the same redirect, the same
  message and the same page (the pages matched in full apart from per-visit
  values).
- A reset link was created for the existing account only, nothing was recorded
  for the made-up addresses, and no email error was logged, so Resend accepted
  the email.
- Response times showed no meaningful difference. Over 12 paired requests the
  server took about 380 to 545 ms for the existing account and about 400 to 450 ms
  for made-up addresses, and the requests that really sent an email were no slower
  than the rest. The slowest request was whichever came first after a pause,
  whatever address it used. Without the fix, a request that sends an email would
  take several hundred milliseconds longer. No further timing defence was added.
- The seventh request inside a minute was refused for both kinds of address.
- The limit is counted against the visitor's own network address, not the proxy
  in front of the site: the server is set up to pass the real address through.

Not checked by the developer: the reset email arriving in the inbox, which only
the account's owner can see.

**9. Resolved. A deactivated person could complete a passkey sign-in, or a
two-factor step that was already in progress.**
They received a signed-in session and were signed out on their very next request.
Fixed: a deactivated account is now refused before it is signed in, at the
two-factor challenge (code or recovery code) and at passkey sign-in. No session is
created, no "signed in" event is recorded, and a recovery code is not used up. The
check at ordinary sign-in and the check on every request both remain.
Verified by automated tests only. It was not exercised by hand on staging, because
that would mean deactivating the only account there. The passkey tests replace the
passkey's own cryptographic check with one that accepts it, and test everything
the application decides after that.

### Owner impersonation (added 2026-10-05)

An Owner can view Backoffice as another staff member. The points that matter for
security:

- The session never stops being the Owner's; it only remembers a choice, which is
  re-checked on every request. Signing out ends everything.
- The request is served as the staff member alone. The Owner's permissions are
  not combined with theirs, on page loads, Livewire updates or already-open pages.
- The staff member's password, email, two-factor codes, passkeys and "remember
  me" sign-ins are never read, changed or reset, and the pages and addresses that
  manage them are refused while impersonating.
- Staff accounts and access cannot be changed while impersonating.
- The session ID changes on entering and leaving, and a recent password
  confirmation is not carried across.
- Every audit event made while impersonating names the real Owner as well as the
  staff identity. Starting and stopping are audit events.
- Tests send real requests for these checks, because Livewire's test helper skips
  middleware and impersonation is applied by middleware.

To decide at the production-readiness checkpoint: whether impersonation should be
restricted or announced in production.

### Matching a lead to a customer the viewer cannot see (added 2026-10-06)

Customers are shared between locations, and a lead may be from a customer only
another location has dealt with. Staff are told so without being shown who.

- A hidden customer is suggested only on an exact normalized phone or email match
  with one of its Contacts. Never on an address or a business name.
- Only the kind of customer, which detail matched and a masked value are shown.
- Nothing is shown if it would reveal that a visible Contact has other customers,
  or how many hidden customers match.
- The hidden customer's ID is never sent to the browser. Confirming re-runs the
  match on the server.
- **What remains possible:** someone who knows a customer's exact phone or email
  can learn that some customer has it, and by linking a lead gain sight of that
  customer's Contacts and Properties. This is the approved behaviour: it is what
  lets two locations share one customer record. It is limited to 20 masked
  matches an hour per person, and every link, and every correction of a lead's
  phone or email, is an audit event. It never exposes another location's leads.

### Lead lifecycle (added 2026-10-06; reconciled 2026-10-07, step B7)

- Every lifecycle action is authorized on the server against the Lead's own
  Location, whatever the screen offered. A Sales Consultant acts only on Leads
  assigned to them.
- **Naming the original of a duplicate never reveals a Lead.** Only Leads the
  person can already see are offered, and any other ID is refused as not found.
  When a Lead points at an original its viewer cannot open, the page says only
  "a lead you cannot open".
- **Nonresponse closure is retired.** Unable to Contact is unavailable for new
  closure or newly selected corrections, regardless of role or elapsed time.
  Historical reasons, notes and audit entries remain; a historical note can be
  corrected and the Lead can be reopened. No action accepts a waiver.
- A change, its lifecycle revision, assignment, status-history row and audit
  events are saved in one transaction: all or none. An atomic revision claim
  rejects stale lifecycle saves even when the status or second has not changed;
  the claim also checks Location and assignee against the authorization context.
- The status history refuses to be edited or deleted in code. Like audit events,
  this is not yet enforced by the database itself.
- Through impersonation, the Lead and its history name the staff identity; the
  audit event names the Owner as well. The Owner's own permissions are not added:
  viewing as a Sales Consultant cannot reopen a Lead.

### Appointments (added 2026-10-10, slice 6 step 6a)

- **Location is still the boundary.** Booking, "booked in error" and every
  appointment shown are decided at the Lead's own Location. A Sales
  Consultant's right to book comes from a policy, for their own Lead and for
  themselves, not from a permission.
- **The server decides who can be booked.** The page offers only people with
  Sales Consultant access at the Lead's Location and accepts only someone it
  offered; the action checks again with that person's row and access read
  under a lock.
- **No customer details leave the Lead.** The salesperson's email and the
  notice in the app carry the appointment's time and a link. A refused
  double booking names the time that is taken, never the other customer.
- **Notices are per person.** The bell reads only the signed-in person's own
  notices, and an Owner viewing Backoffice as someone does not mark that
  person's notices seen.
- **Nothing is half-saved.** The appointment, the assignment, the status, the
  issue, the queue exit, the audit event and the notice are one transaction.
  The email is sent after it and cannot undo it.
- **Kept for good.** Appointments and issues cannot be deleted. An issue can
  only be marked voided.
- **Open:** an appointment whose time has passed has no outcome and its Lead
  stays Appointment Booked until the after-action report exists. The email is
  sent while the person waits, like invitation emails.

### Call queue screen (added 2026-10-09, slice 4 step 4c)

- **The held Lead is never named by the browser.** The page keeps the cadence ID
  and hold token as locked Livewire state; every action (Next lead, poll, log,
  skip, disqualify, Put back) authorizes `leads.work_queue` again and re-reads
  the Lead and the person on the server. Logging uses `LeadPolicy::update` plus
  the queue's eligibility rule, disqualify uses `changeStatus` and the existing
  reasons and lifecycle-version check, Put back uses `returnToQueue`.
- **A hold grants nothing** and the page never reads or changes the assignee.
- **The gone-hold notice gives a reason only,** never anything about a Lead the
  person may no longer see. The poll only reads.
- **The summary, the held list, the cadence-finished list and the queue history
  are shown only where the person may assign Leads,** and cover only those
  Locations. Since decision 85 (built 2026-10-09) a Call Center Agent also
  needs their manager-view switch, which only Owner and Leadership can change.
  It is checked on the server every time: by the two policies, by the
  summary's scope and again inside Put back in queue, so switching it off
  takes effect on the agent's next request, including a save from a dialog
  that was already open. The settings page finds the person again from the ID
  it is sent and refuses anyone who is not a Call Center Agent.
- **The device timezone is display only:** formatted in the browser from a
  server-rendered ISO timestamp, never sent to or read by the server.
- A busy database on the page's own saves is answered with "try again"; the
  MySQL lock-order follow-up from 4b was fixed on 2026-10-09 (decision 87): the
  actions that also end a hold or a cadence now run again from the start on a
  deadlock, with their records read again first, so a retry cannot act on what
  a rolled-back try left in memory.
- Every dialog is the shared Flux confirmation pattern; no native dialog.

### Call queue engine (added 2026-10-09, slice 4 step 4b)

- **Who may be given a Lead is decided in one place,** on rows read through
  locks at the moment of the claim: the person is active, holds
  `leads.work_queue` for the Lead's own Location (an all-locations grant for an
  unrouted Lead) and passes the existing "work this Lead" rule. Nothing sent by
  a browser and nothing worked out earlier in the request is trusted. Through
  impersonation only the staff member's access counts.
- **A hold grants nothing.** It is not assignment and gives no access the person
  did not already have. Skipping and Put back in queue re-read the Lead and
  authorize again on current rows.
- **The database, not only the code, refuses the two worst mistakes:** a second
  cadence for one Lead and a second held Lead for one person (unique indexes),
  and a cadence step used twice (unique index on `contact_attempts`).
- **Queue history is append-only in the application, not in the database,** like
  audit events and the status history: `lead_queue_events` and the two cadence
  columns on `contact_attempts` refuse update and delete in code. A row changed
  by hand in the database would not be stopped.
- Put back in queue saves its change, its history entry and its audit event
  (`lead.returned_to_queue`) in one transaction. Taking, skipping, expiring and
  counting are operational history, not audit events, by design.
- The timer's failure log carries only the part that failed, a cadence ID and
  the exception class: no name, number or message text.
- The concurrency tests start separate PHP processes through a script under
  `tests/` that refuses to run outside the testing environment. It is not an
  application command or route.

### Open: must be handled before real staff use the systemThese are not blocking for development, but they must be closed before Backoffice
is used with real accounts on a server.

**Two-factor sign-in is optional for every role.**
Decide whether Owner and Finance accounts must use it. Already listed in
roadmap.md under decisions for Checkpoint B.

**Database backups run on staging, but a restore has never been performed.**
Staging is backed up daily (keep 7) to a dedicated private DigitalOcean Spaces
bucket, with failure notifications, and a successful backup has been verified
(2026-10-05). Forge restores a backup directly over the database it came from and
cannot restore into a separate one, so Forge Restore was deliberately not run
against the active staging database. A full Forge restore drill is required before
production holds meaningful real data: done deliberately when staging can safely be
overwritten or rebuilt, with a fresh backup taken immediately beforehand and a
written recovery procedure. Production also needs its own backup configuration.
See roadmap.md, "Backups".

**Database backups do NOT cover uploaded files.**
A database backup contains the database only. Uploaded customer and project files
are stored separately and would not be restored by it. Backoffice stores no
uploaded files today. Before it begins storing meaningful uploaded files, it needs
a separate, durable object-storage and backup strategy for them: private, protected
against deletion and overwriting, restorable, and tested. This must be settled
before Checkpoint B stores its first real photo.

**The application uses the server's main database account.**
On staging the application connects to MySQL as the server's general `forge`
account, which can also reach the other database on that server. Production should
use an account of its own that can reach only the Backoffice database.

**Session invalidation depends on database sessions.**
Ending a deactivated person's sessions works by deleting their rows from the
`sessions` table, which requires `SESSION_DRIVER=database` (the configured
default). With any other session store, their remember-me login is still
destroyed, and any session that makes a request is ended, but a session that stays
idle could survive until it expires on its own (two hours by default). Keep
database sessions in production, or revisit this.

### Open: low priority

**11. A signed-in person without access can tell which staff ID numbers exist.**
`/settings/users/{id}` answers "forbidden" for an ID that exists and "not found"
for one that does not. Staff IDs are not secret, so this is minor.

**12. A malformed browser header on the location switch shows an error page.**
The switch itself has already been saved by then. It cannot be triggered by normal
use and does nothing harmful.

**13. Removing a role from the code while grants still use it breaks those accounts.**
A stored grant with a role the code no longer knows causes an error on every page
for that person. Before ever deleting a role, move or remove its grants first.

**14. Location names are compared exactly.**
"Northside" and "northside" can both exist. Unlike email addresses this causes no
lockout, only confusion.

**15. An Owner can open the "All Locations" view before any location exists.**
Only by sending the request by hand; the selector is not offered. Harmless, because
there is nothing to see and the Owner has access everywhere anyway.

**16. Two Owners removing each other's Owner role at the same instant might both
succeed.**
The check and the change are not locked together. Suspected from reading the code;
not reproduced. It needs two Owners acting within the same fraction of a second.

**Rules about a grant's scope live in application code.**
The database now prevents duplicate grants, but "a location grant must name a
location" and "Owner is always company-wide" are still enforced only when a grant
is saved through the application. A row inserted by hand could break them.

**A double-submitted grant relies on the database to refuse it.**
The friendly "already has that access" message for that case is written but has no
automated test, because the timing cannot be reproduced reliably in a test.

## npm dependency audit (2026-10-08)

Jacob approved fixing the critical `npm audit` warnings with non-breaking updates
only. See decisions.md, 64. The audit was run before anything changed and again
afterwards, with npm 10.9.9 on Node 22.

### Before and after

| Severity | Before | After |
| --- | --- | --- |
| Critical | 5 | 3 |
| High | 0 | 0 |
| Moderate | 0 | 0 |
| Low | 0 | 0 |
| Info | 0 | 0 |

`npm audit` counts every affected package, so the 5 warnings came from two
underlying problems: one in `shell-quote` (2 packages) and one in `tinypool`
(3 packages).

### Fixed

| Package | From | To | Why it was flagged |
| --- | --- | --- | --- |
| `shell-quote` | 1.9.0 | 1.12.0 | Command injection in `quote()` (GHSA-pqg4-j6r4-53mv). |
| `concurrently` | 10.0.5 | 10.0.6 | Flagged only because it required the affected `shell-quote`. |

Both are patch or minor updates inside the range `package.json` already allowed
(`concurrently` `^10.0.3`), so `package.json` did not change. `concurrently` is
used only by the local `composer dev` command. Nothing else in the lockfile
changed, and the built assets are byte-for-byte the same as before the update.

### Open: needs Jacob's decision

**Three critical warnings remain, all from one cause: `tinypool` 2.1.0.**

| Package | Installed | Why it is flagged | Fix |
| --- | --- | --- | --- |
| `tinypool` | 2.1.0 | Prototype pollution that can lead to code execution (GHSA-5gmw-xhrv-c9v3, GHSA-85c8-ppgw-ccpr). | Fixed in 2.1.2. |
| `oxfmt` | 0.64.0 | Requires exactly `tinypool` 2.1.0. | Fixed from 0.67.0. |
| `vite-plus` | 0.3.0 | Requires exactly `oxfmt` 0.64.0. | Fixed in 0.3.3. |

Each package pins the next one to an exact version, so the only fix is to move
`vite-plus` from 0.3.0 to 0.3.3. `package.json` pins `vite-plus` to exactly
0.3.0, so npm offers this only through `npm audit fix --force`, which decision 64
does not allow. It was not run and the pin was not loosened.

`tinypool` arrives through the code formatter bundled inside the `vite-plus`
build tool. It is installed on development machines, in CI and wherever the
assets are built. It is not part of the application code served to staff, and
Backoffice's own scripts (`vp build`, `vp dev`) do not call the formatter. How
exposed that leaves a build machine was not investigated further.

Must be decided before production: whether to raise the `vite-plus` pin to 0.3.3
as its own approved dependency change, with a full build and test check.
