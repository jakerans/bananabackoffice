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
  can restore from its dashboard. A Forge restore writes directly over the database
  the backup came from; it cannot restore into a separate one. So Forge Restore has
  deliberately not been run against active staging, and a full restore drill is
  required before production holds meaningful real data (roadmap.md, "Restoring").
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

## 24. "Forgot password" answers the same way for every email address

Decided 2026-10-04, closing security finding 8.

- **One answer.** Any well-formed request gets "If that email address belongs to
  an account, we have emailed a password reset link." Nothing differs for an unknown
  address, for an address that was sent a link moments ago, or when the email fails.
- **The work happens after the answer.** Looking up the account, creating the link
  and sending the email all run once the response has been sent. Otherwise the
  extra time those steps take would give the answer away.
- **A failed email is reported, not shown.** It goes to the application log. The
  person sees the same answer and can ask again.
- **Limited per visitor,** 6 requests a minute, counted by network address and never
  by email address. The existing wait between links for one account (60 seconds)
  still applies, silently.
- **Our own route replaces Fortify's.** Fortify's version cannot be made to answer
  uniformly, so `routes/web.php` declares the same address and takes its place. The
  form page is still Fortify's.

The cost: someone who mistypes their address is no longer told so. That is the
unavoidable price of not revealing which addresses have accounts.

## Checkpoint B decisions (25 to 37)

Decided by the owner on 2026-10-05, before any of Checkpoint B was built. The full
model is in [checkpoint-b.md](checkpoint-b.md); these entries record what was
chosen, why, and what it rules out.

## 25. The customer record is an Account with many Contacts

"Account" in the code and data; "Customers" wherever staff see it. An Account is
Residential or Commercial. A Contact is a person and may be connected to several
Accounts, each connection carrying a role. An Account has no Location.

Why: real customers are a homeowner and a spouse, an owner and a property manager,
a business and its manager. A record limited to one name, email and phone would
have to be rebuilt.

Rules out: a single name, email and phone on the customer; homeowner-only
assumptions; copying a person once per Account they deal with.

## 26. A Lead is one inquiry and is never a Project

A Lead is its own record with its own lifecycle. It preserves the inquiry as
received, may be linked later to an Account, Contact, Property and Location, and
may remain unrouted for a time. A Project always has a Location; "not yet
assigned" lives on the Lead.

Why: someone may inquire and never book, inquire again later, have several
properties, or never become work. A placeholder project at first contact would
misrepresent all of those and pollute sales figures.

Rules out: treating a Lead as an unassigned Project; a Project without a Location.
This supersedes the first proposal for Checkpoint B and refines the brief's "a
project belongs to a location once assigned".

## 27. A Lead becomes a Project when a real scope of work is identified

A Qualified Lead may produce one or more Projects. A Project may also be created
directly, with no Lead, for legitimate repeat-customer work.

Rules out: a single universal conversion point such as "estimate scheduled";
requiring every Project to have a Lead.

## 28. Disqualifying a Lead and losing a sale are different things

A Lead is New, Working, Qualified or Disqualified. A Project's sales outcome is
Open, Won or Lost. Each has its own list of reasons. "Not ready yet" is not a
disqualification.

Why: disqualified Leads must never drag down the Sales close rate, and reasons for
not pursuing an inquiry answer a different question from reasons for losing a
qualified opportunity.

Rules out: one "closed" status; one combined reason list; one status field
covering sales, production and finance.

## 29. Attribution is three separate facts, recorded on each Lead

Marketing Source (how they first heard of us), Lead Source (where this inquiry
entered), Lead Channel (how they communicated). Each belongs to the Lead, not the
Account, and each is reportable on its own. Unknown is recorded as unknown.

Why: the three answer different spending questions, and the same customer can
arrive differently each time.

Rules out: one "source" field; storing attribution on the customer; guessing.

## 30. The lists staff choose from are managed data, not fixed in code

Marketing Sources, Lead Sources, Lead Channels, Disqualification Reasons and Sales
Loss Reasons can be added to, renamed, reordered, and activated or deactivated by
Owner and Leadership. A used value is never deleted, and old records keep it.

Why: these lists change with the business, and changing them should not need a
developer. Statuses and roles are different: they carry behavior, so they stay in
code.

Rules out: a general "configure your CRM" framework.

## 31. Location is the authorization boundary; assignment is not

Leads and Projects are governed by their Location. Accounts, Contacts and
Properties have none: a person sees them through a Lead or Project they are
authorized for, and that never reveals another location's work. A Property's
address decides nothing. Being assigned to a record grants nothing.

Why: assignment changes constantly and is about who is responsible. If it were the
security boundary, covering for a colleague would mean changing access.

Rules out: per-person record ownership as security; access by geography.

## 32. Records with history are archived, and duplicates are never merged silently

Accounts, Contacts, Properties and Projects are archived and restored, not
deleted. Leads have their lifecycle instead. Possible duplicates are suggested
from normalized phone and email, and a person decides.

Why: deleting a customer destroys the history of the work, and an automatic merge
that is wrong is very hard to undo.

## 33. One attachment model, in private storage of its own

A general attachment record, though Checkpoint B shows only project photos. Files
live in a private DigitalOcean Space used for nothing else, separate from the
backup Space, one per environment, each with a key limited to it. Access is by
permission check followed by a short-lived link. A removed file is hidden at once,
kept 30 days, then eligible for purge. Laravel's standard S3-compatible storage
driver is approved for this.

Rules out: a photos-only table; public file addresses; sharing the backup bucket.

## 34. Notes, audit events and the timeline are three things with one event store

A note is what a person wrote. An audit event is the system's append-only record
of an important change. The timeline is what staff read: notes plus selected audit
events. Audit events also cover Checkpoint A's staff and access changes.

Why: "customer prefers Tuesday" and "the location was changed from A to B" are
different kinds of fact, but keeping two parallel histories invites them to
disagree.

Rules out: a compliance product; logging who viewed or searched what.

## 35. Leads and Projects are each one shared page, shown in more than one center

Decided by the owner at the final review (2026-10-05). Leads is one page shown in
Marketing and in Sales. Projects is one page shown in Sales and in Production.
Each is opened by its own permission, not by access to a center. Future
center-specific screens are different views of the same records.

Why: Sales Consultants work Leads but cannot open the Marketing Center, and a
Project passes from Sales to Production without becoming a different thing.

Rules out: Marketing Leads and Sales Leads, or Sales Projects and Production
Projects, as separate data or separate behavior.

## 36. Sold Amount is typed in when a Project is Won, until proposals exist

Marking a Project Won records its Sold Amount, so revenue is in the system from
the first day. Owner and Leadership may correct it, and every correction is
audited. When estimates and proposals are built, the accepted proposal becomes the
authoritative source and the amount is no longer entered by hand.

Why: the business launches before estimating is built. Without this, nothing would
record what the first jobs sold for.

## 37. Launch on B1 to B9, and prepare production before they are finished

The operational target for launch is steps B1 to B9. Attachments, the timeline and
the wrap-up may follow. Work stops around B7 or B8 for a production-readiness
checkpoint. Production is a separate environment, never staging reused, and real
customer data is never entered into staging. Leadership manages the Lead & Sales
Lists page through a permission that opens no other Settings page.

Why: leaving production to the end would put the first real customer data into an
environment nobody had time to check. Authorization, data integrity, tests and
auditability are not traded for the date.

## 38. How audit events are recorded (step B1)

Built in step B1 (2026-10-05).

- **Saved together with the change.** The change and its audit event are written in
  one database transaction. If either cannot be saved, neither is. Tests make the
  audit event fail on purpose and check the change is not kept.
- **Explicit, not automatic.** Each action that makes an important change asks for
  its event to be recorded. Nothing is recorded behind the scenes whenever a row
  changes, so the list of what is audited is the list in checkpoint-b.md, and a
  change that did nothing (renaming to the same name, deactivating someone already
  inactive) records nothing.
- **Who acted.** The signed-in person, unless another is named. Accepting an
  invitation is recorded as done by the person accepting. A change from the command
  line, such as creating the first Owner, has no actor.
- **Readable later.** The actor's name is copied onto the event, and so is a
  location's name inside the values, because both can change afterwards.
- **Before and after are stored separately,** each holding only the fields that
  matter for that change. MySQL does not keep the order the fields were stored in,
  so nothing may depend on that order. (The first run of the checks on MySQL 8.4
  caught four tests that did; SQLite had hidden it.)
- **Append-only in the application.** An event refuses to be changed or deleted
  through the application. Someone with direct access to the database could still
  alter it; preventing that is part of giving production a least-privilege
  database account.
- **Records are named, not addressed by class.** An event points at "user 5" or
  "location 2", using a short fixed name for each kind of record. Every kind of
  record that can be pointed at must be given a name first; an unnamed one is
  refused. This also applies to Livewire pages that hold a record.
- **Not recorded, deliberately:** resending an invitation, profile and password
  changes, sign-ins, and anything viewed or searched.

Also in B1: the GitHub checks now run on `checkpoint-` branches as well as `main`,
so each step's database changes are run on MySQL 8.4 before review, not only when
merged.

## Future decisions already constrained by the brief

- Estimates hold any number of named, ordered options. No fixed tiers.
- Accepted proposals are stored as versioned snapshots.
- Projects carry several narrow states (sales, production, payment), not one
  combined status.
- Customer-visible information is opted in field by field, never by default.
- Customer uploads use private storage.
