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
- **Resending an invitation is recorded** (added at the owner's review): it cancels
  the earlier link and issues a new one, so it is an account-access action. The
  event holds who resent it, for whom and when, and never the link or its token.
- **Recorded before an email goes out.** Where a change sends an email, the audit
  event is written first, because an email cannot be taken back if something later
  fails.
- **Not recorded, deliberately:** profile and password changes, sign-ins, and
  anything viewed or searched. These stay outside this business audit history.

Also in B1: the GitHub checks now run on `checkpoint-` branches as well as `main`,
so each step's database changes are run on MySQL 8.4 before review, not only when
merged.

## 39. How the managed lists are built (step B2)

Built in step B2 (2026-10-05).

- **Five small tables, one per list,** not one shared table with a "which list"
  column. Each record that later uses a list can then point at exactly the right
  kind of option, and the database itself refuses a lead whose "marketing source"
  is really a sales loss reason. The five share their behavior in code.
- **The five lists are fixed in code.** There is no way to create a sixth list or
  to add fields from the page. That is what keeps this from becoming a general
  "configure your CRM" tool.
- **The starting values are created with the tables,** in the same database change,
  not by a separate seeding step. Every environment, including a brand new
  production database and the test runs, has them without anyone remembering to
  load them.
- **No delete, even for an unused option.** One rule with no exceptions is easier
  to trust than "deleted if unused". Deactivating does everything deleting would.
- **At least one active option per list,** enforced when deactivating.
- **Order is a simple position,** renumbered 1, 2, 3 each time something moves.
- **"A note is required" and "ask which competitor" are stored on the option,** not
  tied to its name, so renaming "Other" does not switch the behavior off. They are
  not yet changeable from the page.
- **Leadership's access is a permission of its own.** The Settings center opens
  for anyone holding it, but the unbuilt Settings pages do not borrow it, so
  Leadership sees exactly one page there.
- **One list's page cannot touch another's options.** An option is always looked up
  inside the list being shown, never by its number alone.

## 40. How lead capture is built (step B3)

Built in step B3 (2026-10-05). The behavior was approved by the owner beforehand;
these are the choices made while building it.

- **A Lead is authorized by its own location, never the selected one.** Opening,
  correcting and working a Lead check the Lead's location against the person's
  grants. The list starts from "what this person may see" and only then narrows to
  the location they are working in. An unrouted Lead has no location, so only its
  own company-wide permission reaches it.
- **Capturing for a location and leaving a Lead unrouted are checked on the
  server every time.** The location on the form comes from the browser and is not
  trusted.
- **Times are stored in UTC and shown in one company timezone.** It is a setting
  (`APP_DISPLAY_TIMEZONE`), defaulting to US Eastern. It is one value for the whole
  company until locations in different timezones need their own. The owner
  confirmed `America/New_York` on 2026-10-05.
- **Phone numbers are stored twice:** as typed, and in international notation for
  matching. Recognition is tuned for U.S. numbers but the stored form is not
  U.S.-only, so it can be improved later without changing the data.
- **The "unknown" source is identified by a marker, not its name,** so renaming it
  cannot break the default. The marker also stops it being deactivated.
- **Pressing Save twice records one Lead.** Each filling-in of the form carries an
  identifier that can be used once.
- **A shared page is drawn inside a center the person may open,** the one they
  were last in when there is a choice. That is remembered in the session and only
  chooses which menu surrounds the page. It grants nothing.
- **The third menu row now exists,** for sections with pages of their own. Call
  Center is the first.
- **`marketing.call_center.leads` was retired** in favor of `shared.leads`, the one
  entry used in both menus. It had never been built, so nothing referred to it.
- **Not built in B3:** correcting the received time after saving, and showing a
  Lead's earlier inquiries on its own page.

Known limit: a Lead at a location that is later deactivated cannot be seen by
anyone, because a deactivated location is covered by no grant (permission model,
rule 3). Step B4 adds re-routing, which is the way to move such Leads; whether
deactivating a location with open Leads should be refused is for that step.

## 41. Services and Project Size on a Lead (B3, after the owner's browser review)

Decided by the owner on 2026-10-05 and built the same day.

- **Services are a managed list;** a Lead may have several, one primary. They are
  stored as rows linking a Lead to a Service, so reporting and later routing can
  ask "all Leads for Cabinet Painting" or "primary Service only" directly. The
  database refuses a second primary on one Lead.
- **Project Size is Small, Medium or Large, and required at intake.** It is the
  intake person's informed classification, not an estimate, and is stored apart
  from any estimate or sold amount so the two can be compared later.
- **The value each size covers is data and can change. The three sizes cannot.**
  They are fixed because behavior and reporting will depend on them, and because
  the staff-facing choice should stay simple.
- **A Lead copies the range its size covered when it was classified.** Changing
  the ranges later therefore never rewrites history, and nothing has to be
  redesigned when the average job changes.
- **The ranges are edited as two numbers** (where Medium starts, where Large
  starts), not three independent ranges, so they can never overlap or leave a gap.
- **Staff must choose a size; the data allows none.** That is for Leads recorded
  before sizes existed and for inquiries that will one day arrive automatically.
- **No salesperson routing is built.** Location, primary and additional Services
  and Project Size are captured from the first Lead so a later recommendation has
  the history it needs.
- **At least one Service is required** (decided by the owner on 2026-10-05, after
  it was first built as optional). With several, the person must name the primary;
  it is never taken from list order, because the Primary Service is meant to be
  the dominant opportunity and a silent default would not be.

## 42. Address lookup provider: Mapbox

Status: **Approved by the owner (2026-10-05) and built.** What follows is the
evaluation that led to the choice; decision 43 records how it was built.

The owner wants real address autocomplete that keeps a structured address, with
latitude and longitude, for Property records, duplicate detection, mapping,
service-area routing, distance and geographic reporting.

**The finding that decides it:** Google Places, the leading candidate, publishes
terms that do not allow keeping what it returns. Its policy says Places content
must not be pre-fetched, cached or stored, with one exception: the place ID may be
stored indefinitely. Latitude and longitude may be cached for up to 30 days. Read
plainly, that rules out keeping coordinates and address parts for the uses above.
This is a reading of Google's published terms on 2026-10-05, not legal advice.

| | Google Places (New) | Mapbox | Smarty |
| --- | --- | --- | --- |
| Finds addresses | Best, including many new builds | Good for US addresses | Good; US only; postal-service based |
| May we keep the result? | Place ID only; coordinates 30 days | **Yes, with "permanent" results** | Yes |
| Coordinates | Yes | Yes | A separate product |
| Cost at our size | Likely free (10,000 a month free, then about $5 per 1,000) | Suggestions within a free allowance; about $5 per 1,000 addresses kept | From about $21 a month; every keystroke counts |

**Recommendation: Mapbox,** using its "permanent" results for the address that is
saved, because it is the option whose terms plainly allow what the owner asked
for. Google remains the better matcher; choosing it would mean either accepting
its storage limits or getting advice that our use is permitted.

How it would be built, whichever provider is chosen:

- **Through our own server, not from the browser.** The page asks Backoffice,
  Backoffice asks the provider. The provider's key never reaches the browser, the
  number of requests per person is limited, and it can be tested without the
  provider. No new package is needed; Laravel's built-in HTTP client is enough.
- **Credentials:** one secret access token, entered by the owner directly in each
  server's environment settings, as the Resend key was. A separate token for
  staging and production.
- **What leaves Backoffice:** only the address text being typed. No names, phone
  numbers or anything else about the Lead.
- **If the provider is slow or down,** the form falls back to typing the address.

## 43. How address lookup and service areas are built

Built on 2026-10-05, after the owner approved Mapbox and ZIP-code service areas.

- **Two kinds of request.** Suggestions while typing are Mapbox "temporary"
  results: shown, never stored. The address a person selects is requested again
  as a "permanent" result, which Mapbox allows to be stored indefinitely. That is
  one paid request per address kept, and it is why coordinates may be kept.
- **Server-side only.** Backoffice calls Mapbox from the server with Laravel's
  built-in HTTP client. No package was added. The token is read from the server's
  environment (`MAPBOX_ACCESS_TOKEN`) and is never sent to a browser.
- **Lookup is behind one boundary.** The rest of Backoffice asks for suggestions
  and for a structured address, and does not know that Mapbox answers. With no
  token set, lookup is simply off and addresses are typed.
- **Lookup can never block a Lead.** No suggestion, a refused token, a timeout or
  a nonsense answer all end the same way: the address is typed by hand. Failures
  are reported to the log without the token in the message.
- **Requests are limited:** five characters before the first request, a short
  pause after typing, and 60 lookups a minute per person.
- **Coordinates come only from the provider.** The selected address is held where
  the browser cannot alter it, and a hand-typed address never has coordinates.
- **A service area is a list of ZIP codes per Location,** managed with the
  Location. A Location is suggested only when exactly one active Location lists
  the address's ZIP code. Distance is never used.
- **One question, answered behind a boundary:** "which Location serves this
  address?" Territories drawn on a map can answer it later without changing Leads,
  which store only the Location they were given.
- **A suggestion grants nothing.** It pre-selects a Location only where the person
  may capture a Lead, and the saved Location is authorized on the server as before.
- **Tests never reach an outside service.** The test suite refuses any real
  outgoing request, and the lookup tests use a stand-in for Mapbox.

## 44. Lead routing and assignment (step B4)

Approved by the owner on 2026-10-05 and built the same day.

- **Routing and assignment are separate.** Routing answers "which Location owns
  this Lead?"; assignment answers "who is responsible for working it?". They are
  separate actions and separate data, even if automation later does both at once.
- **One assignee, plus a history.** A Lead has one current assignee. Every
  assignment is also kept as a row with its start, end and the reason it ended, so
  workload, response times, performance and fair distribution can be measured
  later. No report was built.
- **A change of Location always clears the assignee,** even when that person also
  works at the destination. A transfer is an operational handoff.
- **Sales Consultants do not claim Leads.** Distribution stays with management and
  the Call Center, because future routing will weigh Service, Project Size,
  performance and workload. There is no way for a Sales Consultant to take an
  unassigned Lead: not by assigning, not by starting work, and they have no
  Unassigned queue.
- **A Sales Consultant who records a Lead at their own Location is assigned it.**
  That covers a referral, a repeat customer or a field-generated opportunity
  coming to them directly. Anyone else's capture is left unassigned.
- **A Location Manager may move a Lead between two Locations they both manage.**
  Otherwise they may only return it to central. Authorization is checked at both
  ends.
- **Correcting the Received time needs a reason,** because it changes response
  times. Anyone who may correct the Lead may do it. Future times are refused.
- **A Location with active Leads cannot be deactivated.** Refusal only, with the
  count; no override and no bulk action. Active Projects will block it too (B8).
- **Role-appropriate starting view:** Unrouted for company-wide Call Center,
  Assigned to me for Sales Consultants, Unassigned for Location Managers, All
  leads for Owner and Leadership.
- **No recommendation engine.** The Assign dialog shows eligible people and their
  open-Lead count. A recommendation can later be added as a marker in that same
  dialog, with management still choosing.
- **Projects will carry their own Services and Project Size (B8),** copied from
  the Lead when there is one.

Choices made while building, for the owner to confirm:

- **Only people who may assign can leave a Lead unassigned.** A Sales Consultant
  cannot release their own Lead; they ask a manager. This follows the decision
  that distribution is controlled by management.
- **A Sales Consultant still sees unassigned Leads in "All leads",** and may still
  correct the details of any Lead at their Location, because Location is the
  security boundary and assignment is not. They cannot take or start one.
- **The open-Lead count in the Assign dialog counts only Leads the viewer may
  see,** so a Location Manager does not learn about work at other Locations.
- **An unrouted Lead can be assigned only to company-wide lead staff,** since
  nobody else can open it.
- **A new permission, `leads.transfer`,** carries the Location Manager's direct
  move. `leads.route` stays company-wide and covers routing and any move.
- **Received time is corrected from its own small dialog on the Lead page,** not
  inside "Correct details", so the required reason is only asked for when the
  time is what is being changed.
- **The test run's memory limit was raised to 512 MB** in `phpunit.xml`. The
  suite outgrew PHP's 128 MB default on a development machine. Nothing in the
  application changed.

## 45. B4 approved; Received Time cannot break the order of events

The owner approved B4 on 2026-10-05 and confirmed the choices listed under 44:
Sales Consultants cannot release their own Leads; they may see unassigned Leads
at their Locations and correct ordinary Lead details there, but may not claim,
assign, start, release or reassign unless another role they hold allows it; no
"Take it" button; `leads.transfer` with its both-ends check; authorization-scoped
open-Lead counts; Received Time in its own dialog; the multi-role
auto-assignment.

One correction was required and is built:

- **A Received Time correction may not create an impossible order of events.**
  It may not be later than when the Lead was first routed, first assigned
  (including to someone who no longer has it), or first worked. Any of those
  would produce a negative response time.
- **Nothing downstream is rewritten.** A correction that does not fit is refused
  with a message naming the earliest of those moments and its time.
- **When the Lead's record was created is not a limit.** The first build also
  refused a Received Time later than the record's creation time. The owner
  removed that on review: the creation time is a database-record timestamp, not
  business chronology, and a legitimate corrected Received Time can be later.
- The required reason and the audit event are unchanged.

With that correction, B4 is approved.

## 46. Owner impersonation

Requested by the owner on 2026-10-05, to review and troubleshoot each role's
experience during development and for support afterwards. Built the same day.

**What it is.** An Owner can view Backoffice exactly as another active staff
member sees it, from Settings > Users and Access > the staff member >
Impersonate.

**How it works**

- **The session stays the Owner's.** It only remembers who is being viewed as.
  On every request that choice is checked again, and the request is then served
  as the staff member and nobody else. Every permission, policy, location and
  menu is worked out for the staff member alone; none of the Owner's access is
  added. The Owner's identity is put back when the response is ready.
- **Nothing of the staff member's sign-in is involved.** Their password is not
  asked for, shown, changed or reset, and their "remember me" sign-ins on their
  own devices are untouched.
- **It is applied by middleware on every web request,** including Livewire
  updates and pages that were already open. A page the Owner opened as
  themselves cannot be used with the Owner's permissions once impersonating.
- **The session gets a new ID on entering and on leaving.** A recent password
  confirmation, and the location and center last worked in, are not carried from
  one identity to the other.

**Who and whom**

- Only the Owner role may start it. It is not a permission another role could be
  given. Someone holding Owner alongside other roles may.
- Not as yourself, not as a deactivated person, and not as someone who has not
  yet accepted their invitation (they have never signed in, and every page would
  send them to email verification).
- Another Owner may be impersonated.
- It cannot be started while already impersonating.

**While impersonating**

- A red bar stays at the top of every signed-in page: "Viewing Backoffice as
  [Name]", with a "Return to [Owner]" button. It stays in view when the page
  scrolls and is also shown on "Not authorized" pages.
- **Account and security settings are closed:** name and email, password,
  two-factor codes and recovery codes, passkeys, password confirmation and email
  verification. This covers the Backoffice pages, pages that were already open,
  and the sign-in packages' own addresses.
- **Staff accounts and access are closed,** even when impersonating another
  Owner: inviting, granting, revoking, deactivating, reactivating and resending
  invitations. An Owner manages staff as themselves, so such a change is always
  made, and recorded, in their own name.
- Everything else behaves exactly as it does for the staff member.

**Ending**

- "Return to [Owner]" restores the Owner at once and goes back to the staff
  member's page.
- Signing out ends the whole session. No Owner session is left behind.
- If the staff member is deactivated, impersonation ends on the next request and
  the Owner is back as themselves with a notice. A Livewire action in flight is
  not carried out as the Owner; its page is reloaded.
- If the staff member's access changes, the next request simply reflects it.
- If the Owner is deactivated, the session is signed out. If they are no longer
  an Owner, impersonation ends and they continue with their own reduced access.

**Audit**

- Starting and stopping are audit events in the Owner's own name, about the
  staff member. A stop records why: returned, signed out, the staff member became
  unavailable, or the Owner was no longer authorized.
- **A change made while impersonating records both people.** The actor is the
  staff identity the change was made as. The event also carries the real Owner
  (`impersonator_id` and a copy of their name). The audit recorder adds this
  itself for every event, so no caller can leave it out.
- It reads as "Jacob Ransom, impersonating Roseanne Smith". Ordinary screens do
  not show this; the audit history will, when it is built (B11).

**Known limits**

- A record created while impersonating belongs to the staff identity, exactly as
  if they had made it: a Lead they record shows them as the person who recorded
  it and is assigned to them. Only the audit event names the Owner.
- If the session simply expires while impersonating, no "stopped" event is
  recorded, because nothing runs at that moment.
- Impersonation is available wherever the code is deployed, including production
  later. Whether production should restrict or announce it is a question for the
  production-readiness checkpoint.

## 47. Lead notes (step B5)

Built on 2026-10-05, limited to notes on a Lead. The timeline and History view
remain step B11.

- **One `notes` table for every kind of record,** pointing at the record the note
  is about. Only Leads use it today.
- **A note has no location of its own.** Reading, adding and removing are decided
  by the record it is about, so a note can never be seen by someone who cannot
  see its Lead.
- **Adding a note is not an audit event.** The note is the record. Removing one
  is an audit event about the Lead, which names the note, its author and when it
  was written, and does not copy what it said.
- **Notes are never edited or deleted.** Both are refused by the application, not
  only left out of the screens.

Choices made while building, for the owner to confirm:

- **A new permission, `notes.remove`,** for removing anyone's note: Owner,
  Leadership and Location Manager. The plan said "its author or a manager"
  without naming who a manager is. Everyone else removes only their own.
- **An author can remove their own note only while they can still write notes
  where the Lead is.** After a Lead moves away, or their access changes, they can
  no longer reach it.
- **No reason is asked for when removing a note.** Who and when are recorded.
- **A removed note is hidden from everyone,** managers included, until the History
  view is built in B11. Nothing on the Lead page says a note was removed.
- **A note written while impersonating is shown as the staff member's.** The
  first build added a line on the note naming the Owner. The owner removed it on
  review: impersonation is not exposed in ordinary screens. Instead, a note
  created while impersonating records an audit event ("note added while
  impersonating") that holds the real Owner, the staff identity, the Lead, the
  note and the time, without copying the note's text. The note and that event
  are saved together or not at all. An ordinary note still records no audit
  event. The note also keeps the Owner's ID in a column that no screen shows.

The owner approved B5 with that correction, and confirmed the other choices
above.
- **The Production Manager role holds `notes.write`,** as the approved matrix
  says, but cannot see Leads, so it has no effect until Projects exist.

## 48. Customers: the B6 decisions, and how B6a is built

The owner approved the B6 plan on 2026-10-06 with these decisions. They are set
out in full under "Customers: decisions for step B6" in
[checkpoint-b.md](checkpoint-b.md).

- B6 is built in three separately reviewed steps: B6a, B6b, B6c.
- A customer with no Lead is created by company-wide staff only.
- **A Contact may hold several roles for the same Account.** Primary Contact is a
  separate mark, not a role.
- A Residential Account needs a primary Contact; a Commercial Account may start
  without a Contact. Compound names are not split automatically.
- Location staff may link a Lead to a customer they cannot see only on an exact
  normalized phone or email match, shown masked, never on an address alone.
  Every such link is audited.
- No notes on customers.
- Archiving shared customer data is for Owner and Leadership through an
  all-locations grant. This narrows the earlier matrix, which let a Location
  Manager archive.
- A Lead needs an Account, Contact, Property and Location before it can become a
  Project (B8).
- Phone and email are matching signals, never unique identity.
- A Property never stores a Location.

Choices made while building B6a, for the owner to confirm:

- **Roles are their own table,** one row per role on a connection, rather than a
  list packed into one column. The database can then refuse a duplicate, and a
  later report can ask "who are our billing contacts?" directly.
- **A connection between a Contact and an Account is archived, not deleted,** so
  "removed from this customer" can be restored like everything else.
- **Location staff need both permissions at the same Location** to see a customer
  through a Lead: the lead permission and the customer permission. Every role
  that works Leads holds both, so this only matters for unusual mixes of grants.
- **The location filter is offered only for Locations where the person can see
  Leads,** because "a customer at a Location" means "with a Lead there". Someone
  who sees customers but not Leads (Finance, Production with an all-locations
  grant) gets no location filter and no Lead count.
- **Customers appears in the Marketing, Sales, Production and Finance menus,** the
  four places the approved navigation map already listed it.
- **Company-wide customer creation is not in any of the three steps as listed.**
  It needs the Contact form, so it is assumed to belong to B6b unless the owner
  says otherwise. Until then no screen creates a customer.

## 49. Managing customers (step B6b)

Built on 2026-10-06, after the owner approved B6a and asked for a more
identifying Properties column, Contact and Property management, and company-wide
customer creation. Described under "Managing customers, as built in step B6b" in
[checkpoint-b.md](checkpoint-b.md).

- **Two permissions.** `accounts.manage` is a location permission, applied the
  same way as seeing a customer: through an all-locations grant it covers every
  customer; through a location grant it covers customers with a Lead the person
  can see there. `accounts.archive` is a company permission held by Owner and
  Leadership, and counts only through an all-locations grant.
- **A refusal cannot leak,** because the people who can remove or archive are
  company-wide and can already see every Lead that might be in the way.
- **Every change is an audit event saved with it,** and records the real Owner
  when made while impersonating. A person's details and their roles at a customer
  are recorded as separate events, and only what actually changed is recorded.

Choices made while building, for the owner to confirm:

- **"Link existing" searches only contacts the viewer can already see.** It is a
  deliberate lookup, not an automatic suggestion. Matching a new Contact against
  existing ones, including masked matches outside the viewer's sight, stays in
  B6c as approved.
- **A customer's first Contact becomes primary, business or household.** A
  business may have no Contact; once it has one, somebody is primary.
- **Removing a person's last connection archives the person.** Restoring the
  connection restores the person with it.
- **A Contact needs at least one role.** "Other" exists for when nothing fits.
- **A Property needs a street address.** The city, state and ZIP code are
  optional when typed by hand.
- **"The same address" means the same Mapbox place, or the same street, unit and
  ZIP code** after ignoring case, punctuation and the usual abbreviations
  (Street and St, North and N, Apartment and Apt). A different unit or ZIP code
  is a different address.
- **Any company-wide holder of the manage permission may create a customer
  directly,** including a Location Manager or Sales Consultant who has been given
  an all-locations grant, not only Owner and Leadership.
- **The customer's own name and type cannot be edited yet.** Renaming a business
  or switching a household to a business was not in the B6b list. It is raised
  with the owner for B6c.

## 50. Resolving a lead into a customer (step B6c)

Built on 2026-10-06 from the approved B6 plan, with customer editing added at
the owner's request. Described under "Resolving a lead into a customer, as built
in step B6c" in [checkpoint-b.md](checkpoint-b.md).

The owner also confirmed the B6b choices (decision 49) and reworded the note on
editing a shared Contact: "Changes update this person's contact information
wherever they're used in Backoffice."

Choices made while building, for the owner to confirm:

- **Resolving has its own page,** opened from the Lead, with the customer, the
  person and the place on one screen. It is not a pop-up over the Lead.
- **A masked match is shown only when it points to exactly one hidden customer,**
  and never for a person who is also a contact of a customer the viewer can see.
  In those cases nothing is shown and staff may create a new customer. This is
  what keeps "do not reveal counts" and "never reveal hidden relationships"
  true. A company-wide person sees the real match.
- **A masked link attaches the Lead to the customer and the matching Contact
  only.** The Property is chosen afterwards, once the person can see the
  customer.
- **20 masked matches an hour per person.** Seeing the same one again does not
  count. This limits guessing phone numbers by editing a Lead. Every link and
  every correction of a Lead's phone or email is also audited.
- **Business names are matched** after ignoring case, punctuation and words such
  as "The", "Inc" and "LLC". Only ever among customers the viewer can see.
- **People are not matched by name.**
- **An archived customer is never offered as a match,** even to someone who could
  restore it. They would find it under "Show archived" on Customers.
- **Removing a link clears the customer, Contact and Property together.**
- **The new lead form shows no match hints while typing.** The approved plan
  allowed an optional subtle hint; none was added, so intake is unchanged.
- **A household that becomes a business keeps its primary Contact; a business
  that becomes a household loses its business name,** which stays in the audit
  history.
- **A customer can be archived only when it has no active Lead.** Since every
  Lead is active until B7, that means no Lead at all for now.

## 51. B1 to B6 merged and verified on staging; the rest continues on a new branch

On 2026-10-06 the owner approved B6 and asked for an integration checkpoint.

- **B1 to B6 are approved and merged into `main`,** with Owner impersonation, by
  an ordinary merge that keeps each step's commits.
- **The staging integration checkpoint is complete.** What was checked is in
  [roadmap.md](roadmap.md) under "Integration checkpoint".
- **Mapbox is the approved address provider** (decisions 42 and 43), and its
  staging integration is configured and verified with a real lookup from the
  staging server. The owner entered the staging token in Forge; it exists only
  there.
- **B7 to B12 remain unbuilt.** They are built on the
  `checkpoint-b-continuation` branch, started from the verified `main`, one
  reviewed step at a time as before.
- **The production-readiness checkpoint is still planned around B7 or B8.**
  Production does not exist yet and real customer data is still never entered
  into staging.

Why merge before Checkpoint B is finished: six approved steps had built up on one
branch, none of it yet proven on the real server and database. Merging at a point
where every step has been reviewed keeps the remaining work small and means a
problem that only appears on staging is found now, not at the end.

## 52. Qualification, disqualification and nurture (step B7)

Built on 2026-10-06 from the B7 plan as the owner approved it with 18 decisions.
Described under "Qualification, disqualification and nurture, as built in step
B7" in [checkpoint-b.md](checkpoint-b.md).

What the owner decided:

- **Qualified requires a Property** as well as a Location, an assignee, a
  customer, a Contact, a Service with a main one and a Project Size.
- **Disqualification from New is allowed** (spam never needs to be "worked").
  Nothing returns to New.
- **Disqualifying clears the assignment** and keeps it in the assignment history.
  **A reopened Lead is Working and unassigned**; the previous assignee is not
  restored.
- **"Unable to Contact"** needs a Working Lead, 3 days since work started and a
  note. Only Owner, Leadership and a Location Manager may waive the wait, and a
  waiver is audited.
- **Nurture** needs an assignee, a date and a note, has no maximum date, and
  nothing happens automatically when the date passes.
- **Reopening** needs a reason and is for Owner, Leadership, Location Manager
  and Call Center, not a Sales Consultant.
- **The reason's category is recorded as it was at the time.**
- **A duplicate names its original** where one can be identified among Leads the
  person can see; otherwise an explanation. Never merged, never deleted, no
  chains.
- **A dedicated, append-only status history,** not the audit log, carries
  lifecycle reporting.
- **Active** means every status but Disqualified, so Qualified still blocks
  archiving and deactivation until B8.
- **All leads hides Disqualified** unless that status is chosen. No Disqualified
  queue.

Choices made while building, for the owner to confirm:

- **One new permission, `leads.waive_contact_wait`,** held by Owner, Leadership
  and Location Manager. It is the only way to express "managers but not the Call
  Center" without naming roles in the code. It is a location-level permission.
- **Reopening and correcting a reason use the existing "may assign Leads here"
  rule,** which is exactly Owner, Leadership, Location Manager and Call Center.
  No new permission was needed. It also fits: a reopened Lead has to be handed
  out again.
- **The two special reasons are recognized by a marker** on the reason, set by
  the migration on the starting values "Unable to Contact" and "Duplicate Lead".
  Renaming them keeps the rule. A newly added reason never has one. The marker is
  not editable in Settings, like "note required".
- **Correcting a reason is limited to the people who may reopen,** since a
  disqualified Lead has no assignee.
- **Correcting only the note keeps the category the Lead was disqualified
  under.** Choosing a different reason takes that reason's category as it is now.
- **Possible originals for a duplicate** are other visible Leads with the same
  phone, the same email or the same customer, newest first, at most eight.
- **The follow-up note is separate from Lead notes** (B5). It says what the
  follow-up is about and is replaced when the follow-up is rescheduled; each
  version stays in the status history.
- **A Qualified Lead can be disqualified.** The plan listed it and nothing
  replaces it until Projects exist.
- **The Leads page gained a fifth view, Follow-ups,** and its Status filter now
  starts on "Open leads" in every view.
- **The Lead page does not show the status history yet.** It is recorded; showing
  it belongs to the timeline (B11).
- **Location deactivation and customer archiving needed no new rule:** they
  already refuse while "active" Leads exist, and active now includes Qualified
  and Nurture and excludes Disqualified.

**The owner confirmed all of these on 2026-10-06** and approved B7 in principle,
pending a browser review: the separate `leads.waive_contact_wait` permission and
who holds it; reopening and correcting through the existing authority to assign;
the markers on the two special reasons; "Unable to Contact" for Working Leads
only; category snapshots that never change, with a genuinely corrected reason
taking the new reason's current category; "Open leads" as the filter's default;
and no status history on the Lead page until B11.

The owner also required that **Follow-ups never loses an unassigned Nurture
Lead.** For someone who may assign Leads at the current Location, the view lists
every Nurture Lead there, assigned or not, with "Unassigned" shown. A Sales
Consultant still sees only their own. That was already how it worked for a Lead
moved between Locations. One gap was closed: a Nurture Lead **returned to
central** has no Location as well as no assignee, and was missing from the view.
It is now listed for the people who may see unrouted Leads (Owner, Leadership and
a company-wide Call Center Agent).
## 53. How work is planned, reviewed and approved

Decided by the owner on 2026-10-06. The agreement is
[collaboration.md](collaboration.md).

- Jacob is the product owner and the only source of approval. Claude Code is the
  implementation engineer. ChatGPT is Jacob's review partner: it advises him,
  cannot see the repository, and has no authority to change it.
- **The approved documents in `docs/` are the canonical record.** A conversation
  stops being the record once its decision is written into them.
- A step that needs an unrecorded decision stops at a planning report. Built work
  stops at a report. Both are handed over in one file,
  [review/current.md](review/current.md), which is temporary and is updated or
  cleared once the decisions are recorded.
- The existing discipline is unchanged: one step at a time on the checkpoint
  branch, the complete local check, both GitHub jobs, a report, and a stop.

Why: every review had been carried between tools as a long pasted prompt, and
decisions risked living only in a conversation.

How it reaches every session: the rules that must always apply are also in
`.ai/guidelines/collaboration.md`, which Laravel Boost builds into the generated
agent instructions (`AGENTS.md`) when `php artisan boost:update` runs. That file
is generated and not committed, so the committed guideline is the source.

Rules out: treating advice from any AI tool as approval; putting secrets or real
customer information in a review file; an archive of old handoffs (Git history
serves).

## 54. Projects (step B8): the plan as approved, not yet built

The owner approved the B8 plan in principle on 2026-10-06 with these decisions.
**B8 is not started.** The production-readiness checkpoint comes first.

- **Two reviewed steps. B8a:** the Project record, creating one from a Qualified
  Lead, creating one directly, the Project page, the Projects list, visibility.
  **B8b:** responsibilities, transfer, archive and restore, notes on a Project,
  the blocking rules, and clearing responsibilities when access is lost.
- **Creating from a Lead:** the Lead's assigned Sales Consultant, an Owner,
  Leadership, or a Location Manager within scope. Not the Call Center. The Project
  takes the Lead's current Location, and uses the Account, Contact and Property
  the Lead already resolved (B7).
- **First Sales Consultant responsibility:** the Lead's assignee, or the creator
  of a direct Project, when that person is eligible. Otherwise it is left unfilled.
- **Who may hold a responsibility:** Sales Consultant responsibility needs the
  Sales Consultant role at the Project's Location; Production Manager
  responsibility needs the Production Manager role there. Being able to edit
  Projects is not enough. The person assigning (Owner, Leadership, Location
  Manager) need not hold that role. A responsibility still grants no access.
- **Transfer clears every responsibility,** even for someone who also works at the
  destination, and needs a reason. What was held before stays in the history.
- **An archived Project does not reverse the Lead's history.** Once a Lead has
  produced any Project it stays Qualified for good: it cannot return to Working,
  move to Nurture, be disqualified or be reopened into an earlier stage, even if
  every Project from it is later archived. A Project created by mistake will get
  its own deliberate workflow if one is needed; archiving is not that. A Qualified
  Lead may still produce further Projects. This replaces the first proposal, in
  which archiving the only Project would have released the Lead.
- **Sales outcome is added in B8 with the single value Open** and no screen to
  change it. Won, Lost, Sold Amount and Sales Loss Reasons stay in B9.
- **A title is required,** pre-filled where possible as "Primary Service — short
  street address" (for example "Exterior Painting — 123 Oak St"). It is ordinary
  editable data and is not regenerated when the Service or Property changes. The
  description is optional.
- **Menus:** the shared Projects page appears in Sales and Production only. Call
  Center and Finance get no Projects menu yet; other authorized people reach a
  Project from the customer page.
- **Every Project carries its own Services, with exactly one primary, and its own
  Project Size with the range as it was.** Copied from the Lead when there is one;
  captured for a direct Project. Reporting on Projects never depends on a Lead's
  classification, which can change.
- **Relationships:** Property and Location required; the Account is reached
  through the Property; the originating Lead is optional; the primary Contact is
  optional and, when given, must belong to the Account.
- **No file storage in B8.** Attachments, photos and object storage are B10. No
  bucket is created and no storage driver installed before then.

## 55. B7 reconciliation only (2026-10-07)

**Approved by Jacob.** The new target product architecture supersedes conflicting
older plans, but this approval implements only these bounded B7 corrections:

- Present the existing stored `nurture` status as **Deferred Follow-up**, with a
  **Schedule follow-up** action: a deliberate manual callback requiring a date and
  note. It is not the future multichannel nurture system. Stored status/event
  identifiers and existing history stay unchanged.
- Retire **Unable to Contact** as a new disqualification or newly selected
  correction reason. Nonresponse does not become terminal merely because time
  passed. Remove the three-day rule and waiver action without replacing them with
  guessed thresholds. Existing records remain readable and their notes may be
  corrected; legitimate reason corrections and reopening remain available.
- **Qualified** means the existing qualification milestone only, not booked,
  Lead Issued, demoed, sold or Project-created. Existing qualification requirements,
  assignment and first-qualification history remain unchanged.
- Preserve legitimate disqualification, duplicates, reopening, audit/history and
  all other authorization behavior. Existing waiver grants remain legacy only;
  no lifecycle action consumes them.
- Atomically guard B7 lifecycle changes with a monotonically increasing revision,
  including same-status reschedules/corrections. Use one additive revision column
  with a zero default for existing Leads. Claim it transactionally against the
  caller's revision, status, Location and assignee; validation, assignment changes,
  history and audit share that transaction. Unchanged saves retain the revision.

This supersedes the conflicting B7 rules in decision 52. It does not approve a
new roadmap, B8/Projects, Contact Center, appointments, dialer, Inbox, AI, deployment
or any downstream feature. Decisions 53/54 and other older future plans do not
provide authorization to start them. Stop for owner review; do not merge or deploy.

## 56. B7 owner-review corrections (2026-10-07)

**Approved by Jacob.** Fix only the B7 review findings before owner re-review:

- The Follow-ups badge must count the same eligible result as the table, including
  future callbacks, the current search and all pages. Preserve existing location,
  assignment and unrouted authorization. This supersedes the due/overdue-only
  badge rule; Assigned to me and assignment workload counts keep their rules.
- Replace the native Return to working confirmation with the existing Flux modal
  pattern. Use it for Resume working too, preserve lifecycle authorization,
  assignment and history, and reject stale dialog submissions.
- Clear obsolete disqualification field errors when the selected reason changes;
  the newly selected reason must still be validated on submission.

No new architecture, B8, merge or deployment is authorized.

## 57. Accessible application confirmation dialogs (2026-10-07)

**Approved by Jacob.** Replace every application-triggered browser-native alert,
confirmation or prompt with a consistent accessible styled in-app dialog. The
current inventory contains nine native confirmations and no production alerts or
prompts: customer archive, customer-contact removal, property archive, Lead-note
removal, Lead-customer unlink, list-option deactivation, Location deactivation,
staff deactivation and access revocation.

- Use the shared Flux confirmation component. The safe Cancel action receives
  initial focus, Tab and Shift+Tab remain inside the dialog, Escape and Cancel
  dismiss without mutation, and focus returns to the trigger.
- Preserve every existing authorization, validation, audit and domain-action
  semantic. Opening a dialog resolves and authorizes the target but changes
  nothing. Confirmation resolves and authorizes the locked target again before
  calling the existing action, so stale or substituted records are not trusted.
- Keep the standard durable in `.ai/guidelines/collaboration.md` and
  `docs/design-system.md`, and enforce the native-dialog prohibition with a
  regression test.
- Browser- and operating-system-owned permission dialogs are outside this rule;
  they are not application UI and must not be bypassed.

This is a bounded owner-review extension. It does not authorize B8, new product
behavior, dependency changes, merge, deployment, or staging/production mutation.

## 58. B7 merge and next planning boundary (2026-10-07)

**Approved by Jacob.** Merge the reviewed `checkpoint-b-continuation` branch into
`main` with the ordinary merge strategy that preserves its commits. The automatic
staging deployment caused by pushing `main` is allowed for this merge. No separate
deployment action and no production deployment are authorized.

B7 is complete after the merge. Do not start B8 or another feature. The next work
is planning and documentation: reconcile [roadmap.md](roadmap.md) against the
canonical [product-architecture.md](product-architecture.md), then return the
result for the owner's approval before implementation.

## 59. Architecture-to-roadmap reconciliation proposal (2026-10-07)

This is a **documentation reconciliation proposed for owner review**, not
implementation approval. [product-architecture.md](product-architecture.md) is
the target-direction authority; `LOCKED`, `CONFIGURABLE`, `PENDING` and `DEFERRED`
describe product certainty, separately from whether implementation is proposed,
approved or built.

- Decisions 27, 28, 34 to 37 and 54 remain intact as historical Checkpoint B
  context. Their unbuilt B8-to-B12 sequence, Project-from-Qualified transition and
  B9-only Open/Won/Lost assumptions require revalidation before implementation.
  This entry does not invent the exact Project creation point or Pending ownership
  rules. Decisions 55 to 58 remain authoritative for built B7 and its merge.
- The target has five parent Centers: Marketing, Sales, Production, Finance and
  Company. Color Studio is an external surface; Settings is under Company.
  Finance includes People & Access in navigation without expanding the Finance
  role or any existing staff-management permission.
- Marketing Contact Center and Inbox are separate. Sales uses one Workspace
  instead of top-level Estimates and Proposals. Production uses one Workspace
  instead of separate Project/stage modules. Shared Customers and related records
  remain canonical shared records. None of these target surfaces is claimed built.
- Unified Notes preserve context, provenance and history. Customer Activity is a
  selected stream of major business events, not every message or audit event.
  Manual Deferred Follow-up remains the B7 callback workflow and is not silently
  converted into future automated scheduling.
- Existing authorization remains the implementation authority: related-record
  visibility never reveals unrelated records; responsibility never grants access;
  grants do not union into broader authority; sensitive financial fields require
  explicit permissions. Target navigation placement changes none of those rules.

The proposed next sequence is: (1) five-Center shell alignment after a reviewed
route/ID/access compatibility map; (2) shared Customer Activity and unified Notes;
(3) contact-attempt evidence without provider or penalty rules; (4) scheduled
work and an atomic due queue, with cadence configurable and scheduler/timezone/
retry contracts decided first; (5) an Appointment issuance and ownership-handoff
domain contract, leaving Project transition and Pending policy unresolved.

The owner must approve or revise this sequence and authorize its first bounded
slice before implementation begins.

## 60. Five-slice order and slice 1 planning (2026-10-07)

**Approved by Jacob.** Use the five-slice order proposed in decision 59. This
approval authorizes planning only for slice 1, the five-Center shell alignment.

The implementation plan must map the live registry, routes, stable IDs, gates,
location scope and active-navigation behavior to Marketing, Sales, Production,
Finance and Company. It must keep all working URLs reachable through retained
routes, aliases or redirects; keep Color Studio reachable as an external surface;
place Settings under Company without widening access; and keep Finance → People &
Access permission-filtered by the existing staff-management gate. Unbuilt
workspaces remain unbuilt.

The reviewed proposal is recorded in [navigation-map.md](navigation-map.md). No
navigation code, permission change, B8 work, merge or deployment is authorized
by this decision. Slice 1 implementation requires Jacob's next explicit approval.

## 61. Standing authority to release approved work to staging (2026-10-07)

**Approved by Jacob.** Commit and push approved work using the connected Windows
repository and existing Git credentials. Merge completed, verified work into
`main` and allow automatic staging deployment without another approval request.
This explicitly includes `docs/architecture-roadmap-reconciliation`. Verify the
remote commits and relevant CI results. The standing agreement is recorded in
[collaboration.md](collaboration.md).

This supersedes earlier requirements to ask again for each merge or staging
deployment within approved scope. It does not approve navigation implementation:
planning is approved, and the compatibility plan remains a proposal until Jacob
authorizes implementation. No force-push, production deployment, destruction of
important data or expanded feature scope is authorized.

## 62. Slice 1 five-Center navigation implementation (2026-10-07)

**Approved by Jacob.** Implement the reviewed slice 1 compatibility map only:
show Marketing, Sales, Production, Finance and Company as the five Centers; place
Settings under Company; and keep Color Studio reachable as a separately grouped
external destination. Preserve existing URLs, route names, stable IDs, gates,
location scope and shared-page active-context behavior. Finance → People & Access
must remain filtered by the existing staff-management permission and does not add
that permission to the Finance role. Unbuilt target items remain muted navigation
and gain no feature behavior.

This approval covers the navigation registry and shell, the permission-aware
`/settings` compatibility landing, related views/routes, regression tests and
handoff documentation. It does not authorize database, permission-grant, policy,
domain-feature or dependency changes, B8, slices 2–5, production action or a force
push. Under decision 61, the completed work may merge and deploy to staging only
after the required local checks, exact-commit GitHub jobs and owner browser review
are complete.

## Future decisions already constrained by the brief

- Estimates hold any number of named, ordered options. No fixed tiers.
- Accepted proposals are stored as versioned snapshots.
- Projects carry several narrow states (sales, production, payment), not one
  combined status.
- Customer-visible information is opted in field by field, never by default.
- Customer uploads use private storage.
