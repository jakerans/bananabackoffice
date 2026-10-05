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

### Fixed before Checkpoint B (2026-10-04)

Both were found in the review above and deferred at the time. Each fix has tests
that were also run against the old code to confirm they fail without it.

**8. "Forgot password" revealed whether an email address has an account, and was not
rate-limited per visitor.**
An unknown address got "We can't find a user with that email address"; a known one
got "We have emailed your password reset link".
Fixed: every well-formed request now gets the same answer, including when a link
was sent moments ago and when the email fails to send. The work happens after the
answer has been sent, so the time taken does not give it away either. Requests are
limited to 6 a minute per visitor. See decisions.md, 24.
Still to check: that the time taken really is the same on the server. It depends
on how the server runs PHP and can only be measured once this is deployed.

**9. A deactivated person could complete a passkey sign-in, or a two-factor step
that was already in progress.**
They received a signed-in session and were signed out on their very next request.
Fixed: a deactivated account is now refused before it is signed in, at the
two-factor challenge (code or recovery code) and at passkey sign-in. No session is
created, no "signed in" event is recorded, and a recovery code is not used up. The
check at ordinary sign-in and the check on every request both remain.

### Open: must be handled before real staff use the system

These are not blocking for development, but they must be closed before Backoffice
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
