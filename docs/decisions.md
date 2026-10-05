# Architectural Decisions

Each entry records a decision, why it was made, and what it rules out. Entries are
added, not rewritten. A reversed decision gets a new entry that refers to the old one.

Status of entries 1–10: **Approved** (2026-10-04).

## 1. Keep the installed stack

Laravel 13, Livewire 4 single-file components, Flux free, Fortify, Tailwind 4, Pest.
No new packages are needed for Checkpoint A.

Why: everything Checkpoint A needs is already installed and tested.

## 2. No `companies` table and no `company_id` columns

Banana Painting is one company. A company table with one row, and a company ID on
every record, is the structure of software sold to many companies. It adds a
filter that must never be forgotten and buys nothing here.

Company-level details (name, branding) live in configuration until the Settings >
Company page is built.

Rules out: treating locations as tenants or as separate copies of the system.

## 3. Roles and permissions in code, grants in the database

Permissions and roles are PHP enums. Which person holds which role, and where, is
stored in the `access_grants` table.

Why: the list of roles changes rarely and should be reviewed and tested when it
does. Who holds a role changes often and belongs to an administrator.

Considered: the `spatie/laravel-permission` package. Not chosen because it would
add a dependency, and its location scoping does not express "explicitly
company-wide" as cleanly as a stated scope column.

## 4. What and where are stored together on each grant

A grant is person + role + scope + location. They are not two separate lists (a
person's roles, and a person's locations).

Why: with two separate lists, a salesperson at one location who also works the
call center across all locations would silently receive sales access everywhere.
Storing them together makes that impossible.

## 5. "All locations" is always an explicit value

A grant's scope is either `location` or `all_locations`. A missing location never
means everywhere, and a location-scoped grant without a location cannot be saved.
The same applies to the current-location selector: nothing remembered
means "pick an authorized location", never "all".

## 6. The current location is a view, not a permission

It is kept in the session and re-validated on every request. Access to a record is
always decided by the record's own location against the person's grants.

Why: a session value can be stale or tampered with, and a person may have two
browser tabs open on different locations.

Considered: putting the location in every URL. Not chosen for now because
Livewire's follow-up requests do not carry the page URL, so the check must exist
independently of the URL either way.

## 7. The `users` table is staff only

Homeowners get their own account table and their own sign-in when the portal is
built. No "user type" column is added to `users`.

Why: one mistake in a shared table could expose staff screens to a homeowner.
Separate tables and sign-ins fail closed.

## 8. Staff accounts: no registration, command-line first owner, deactivate not delete

- Public registration stays off (already the case in the installed starter kit).
- The first Owner is created with `php artisan backoffice:create-owner`, which
  prompts for the details. No default password exists in the code or the seeders.
- Other staff are invited from Settings > Users and Access.
- The starter kit's "delete my account" feature is removed. Accounts are
  deactivated so past activity stays attributable.

## 9. Reference material stays out of version control and out of the web root

`docs/reference/` is listed in `.gitignore`. It sits outside `public/`, so it is
not web-accessible, and because Forge deploys from git it will not be deployed.

Why: the screenshots contain another company's staff names, photos, figures and
some unredacted customer names.

## 10. One navigation registry with stable IDs

All navigation is defined in one place with stable IDs, permission requirements
and a built / coming-soon state. Unbuilt features get no routes, tables, buttons
or invented numbers.

Why: the shell can show the whole intended system honestly, and a feature moves
from "coming soon" to "live" by changing one entry.

## 11. Invitations use Laravel's password-reset machinery with their own table

Made while building Checkpoint A (2026-10-04).

Staff invitations use a second "password broker" named `invitations`, backed by its
own `staff_invitation_tokens` table, with a 72-hour lifetime.

Why: Laravel's broker already provides hashed, single-use, expiring tokens, so no
new security code had to be written. A separate table means an invitation link can
never be used on the ordinary password reset form, and a "forgot password" request
never cancels a pending invitation.

Considered: signed links. Not chosen because a signed link stays valid until it
expires, where a broker token is destroyed the moment it is used.

## 12. Deactivated staff are stopped in two places

Made while building Checkpoint A (2026-10-04).

Sign-in refuses a deactivated account, and a check on every web request signs out
anyone who is deactivated while signed in. The second check also covers passkey
sign-in and "remember me", which do not pass through the password check.

The "this account is deactivated" message appears only after the correct password
is given, so it cannot be used to discover which accounts exist.

## 13. Application code beyond Laravel's standard folders lives in `app/Support`

Made while building Checkpoint A (2026-10-04).

`app/Support/Access` holds the current-location resolver and `app/Support/Navigation`
holds the navigation registry. Staff operations are action classes in
`app/Actions/Staff`, next to the starter kit's `app/Actions/Fortify`.

Why: these are neither models, controllers nor Livewire pages, and Laravel has no
standard home for them. One clearly named folder is easier to find than several.

## 14. Personal account pages stay at `/settings/profile` and `/settings/security`

Made while building Checkpoint A (2026-10-04).

The starter kit's profile, password, two-factor and passkey pages were left where
they were and relabelled "My account". The company Settings center shares the
`/settings` address prefix.

Why: moving them would have touched working sign-in security code for a cosmetic
gain. Route names hide the addresses, so they can be moved later in one place.

## 15. A center has no page of its own

Made after the owner's visual review (2026-10-04). Replaces the center landing
pages built in step A4.

Choosing a center opens its primary section directly. The second row lists only
that center's sections. There is no "Overview" page or item, and "Coming soon" is
a state of an individual section, never a navigation item.

Why: a landing page put an extra level between choosing a center and working in
it. Three levels are enough: header, sections of the center, the section's page.

Consequence: a center's primary section must have a page even before it is built,
or the center would have nowhere to open. That page says the section is not built
and shows nothing else. Every other unbuilt section still has no route at all.

Each center's address (for example `/sales`) is kept as a shortcut that checks
authorization and then forwards to the primary section, so links and bookmarks to
a center keep working as sections are built.

## 16. Email addresses are stored and compared in lowercase

Made after the security review of Checkpoint A (2026-10-04).

Every email address is trimmed and lowercased when it is saved, whatever saves it,
and is lowercased before any uniqueness check. Sign-in, password reset and
invitation acceptance already lowercase what is typed.

Why: sign-in lowercases the address it is given. On a database that compares text
exactly, an address saved with a capital letter could then never be matched, which
locked the account out, and two addresses differing only by capitals could coexist.

Where: the `User` model normalizes on save, so no caller can forget. The profile
page, the invitation form and the create-owner command normalize before validating
so that "already taken" is reported correctly.

## 17. "Always one Owner" means an Owner who can actually sign in

Made after the security review of Checkpoint A (2026-10-04).

The safeguard counts only usable Owners. A usable Owner holds the Owner role and:

- is active,
- has a confirmed email address, because the management pages require one, and
- has a password they chose themselves, so an unaccepted invitation does not count.

The last usable Owner cannot lose the Owner role or be deactivated. The
create-owner command uses the same test, so it works without `--force` when the
only Owner on record cannot sign in.

Why: counting any active Owner row let the only person able to sign in hand the
system to an invitation that might never be accepted.

Consequence: an Owner who changes their email address is not counted until they
confirm the new one. During that time the other Owners cannot remove themselves.

## 18. Deactivating someone ends every sign-in they have

Made after the security review of Checkpoint A (2026-10-04).

Deactivation deletes the person's stored sessions and replaces their remember-me
token. Reactivation restores their access grants but never an old sign-in: they
must sign in again.

Why: without this, a remembered login on a lost laptop came back to life when the
account was reactivated.

How: both are Laravel's own mechanisms. Replacing the remember-me token is what
Laravel does on sign-out. Deleting session rows is how Laravel's own starter kits
sign out other devices, and it requires sessions to be stored in the database,
which is this project's configured default. See security-notes.md for the limit
this places on other session stores.

Considered: resetting the password on deactivation. Not chosen, because
reactivating should give someone back the account they had.

## 19. Inviting someone is all or nothing

Made after the security review of Checkpoint A (2026-10-04). Changes decision 11's
behaviour on failure.

If the invitation email cannot be sent for any reason, no account is created.
Resending works the same way: if it fails, nothing changes and an earlier link
keeps working.

Why: the first version kept the account when the mail server was unreachable, but
crashed, leaving an account behind, when the mailer refused the address. An
address the mailer refuses can never be invited, so keeping that account was
pointless and there is no screen to correct its email.

Also: if someone invited sets their password through "forgot password" instead of
the invitation, that counts as accepting it. The reset link went to the same
inbox, so it proves the same thing. The invitation is marked accepted, the email
address confirmed, and the unused invitation link retired. Merely requesting a
reset still leaves the invitation alone, as decision 11 says; completing one is
what accepts it.

## 20. The database itself prevents duplicate access grants

Made after the security review of Checkpoint A (2026-10-04).

`access_grants.location_key` is calculated by the database: the location's ID, or
0 for an all-locations grant. A unique index on person, role and `location_key`
replaces the original index.

Why: the original index included `location_id`, which is empty for an
all-locations grant, and databases never treat two empty values as equal. Two
identical all-locations grants could therefore be stored.

A person can still hold a role once per location and once company-wide; those
remain separate grants. The permission model is unchanged.

Considered: an index that applies only to all-locations rows. Not chosen because
not every database engine supports it, and the production engine is not yet chosen.

## 21. Every foreign key gets an index of its own; schema changes are checked on MySQL

Made after the first staging deployment failed on MySQL 8.4 (2026-10-04).

What happened: MySQL requires each foreign key to be backed by an index that
starts with the key's column. `access_grants` was created with a unique index
beginning with `user_id`, so MySQL used that index for the `user_id` foreign key
and kept no separate one. Replacing the unique index (decision 20) then failed
with MySQL error 1553, "needed in a foreign key constraint". SQLite, which local
development and the tests use, has no such rule, so nothing caught it.

Decision:

- A foreign key column is given an explicit index of its own, so that no
  uniqueness rule is ever the only index holding a foreign key in place.
  `access_grants.user_id` now has one.
- A migration that swaps one rule for another adds the new rule before removing
  the old, and checks before each step whether it is still needed. MySQL cannot
  undo a half-finished schema change, so a migration must be safe to run again.
- Production runs MySQL 8.4. A schema change is not finished until it has been
  run on MySQL, not only on SQLite.

How it was verified: on a temporary MySQL 8.4.6 server the original failure was
reproduced exactly, then the corrected migrations were run from the state staging
was in, on an empty database, rolled back and re-applied, and resumed from two
half-finished states. The whole test suite was also run on MySQL and passed.

Still open: the automated checks on GitHub run on SQLite only. Adding a MySQL 8.4
run there would catch this class of problem before a deployment does. See roadmap.md.

Update (2026-10-04): done. The checks on GitHub now include a job that applies
every migration to an empty MySQL 8.4 database and then runs every test against
it, alongside the existing SQLite job. See roadmap.md, "Automated checks on GitHub".

## 22. Resend sends application email, from a domain used only for sending

Decided by the owner (2026-10-04).

Backoffice sends its transactional email through Resend, using Laravel's built-in
Resend mail transport and Resend's official PHP library. Transactional email means
messages a person triggers and expects: staff invitations, email verification,
password resets and account or security notices.

- **Sending domain:** `mail.bananapainting.com`, verified in Resend. It is a
  subdomain kept for application email, so its reputation is separate from the
  company's main domain and from any marketing email added later.
- **Sender:** "Banana Backoffice" at `backoffice@mail.bananapainting.com`. No
  email is sent under a generic or framework name.
- **Replies:** the sending domain receives no mail. A monitored inbox can be set
  as the reply-to address (`MAIL_REPLY_TO_ADDRESS`); until then no reply-to is added.
  Staging currently uses `admin@bananapainting.com`, an interim choice by the
  owner (2026-10-04).
- **Keys:** one Resend API key per environment, each limited to sending and to
  this domain. A key is entered only into that server's environment settings. It
  is never committed, never put in documentation, and not needed on a developer's
  machine.
- **Local development** does not use Resend. It writes emails to a log or a local
  mail catcher, so nothing real is sent while developing.
- **Not set up:** broadcasts, newsletters, marketing contacts, automations,
  webhooks and inbound email. Customer and marketing email will be designed
  separately, on its own sending domain.

Why Resend's API transport and not SMTP: it needs one key and no mail server
settings, and Laravel supports it natively.

## 23. Database backups use Forge's built-in backups, to a bucket of their own

Decided by the owner (2026-10-04). Configured on staging, with a first successful
backup, on 2026-10-05 UTC. Current state is in roadmap.md, "Backups".

- **Method:** Forge's native database backups, which Forge schedules, prunes and
  can restore from its dashboard.
- **Staging:** the `banana_backoffice` database, daily at 08:00 UTC, keeping the 7
  most recent, with failure notifications on.
- **Destination:** DigitalOcean Spaces, in a bucket used only for Backoffice
  staging backups (`banana-backoffice-staging`), reached with an access key that
  can use only that bucket. The organization's existing Spaces destination belongs
  to another system and is not reused.
- **Production** will have a separate configuration, bucket and access key, with
  more frequent backups and longer retention.

Why a bucket and key of its own: backups hold everything the database holds. A key
shared with another system would let a problem in either one reach the other's
backups.

What this does not cover: uploaded files. A database backup contains the database
only. Storage and backup of uploaded customer and project files is a separate
decision that must be made before any are stored. See roadmap.md, "Backups".

## Future decisions already constrained by the brief

- Estimates hold any number of named, ordered options. No fixed tiers.
- Accepted proposals are stored as versioned snapshots.
- Projects carry several narrow states (sales, production, payment), not one
  combined status.
- Customer-visible information is opted in field by field, never by default.
- Customer uploads use private storage.
