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

## 63. Slice 3 manual contact-attempt evidence foundation (2026-10-08)

**Approved by Jacob.** Implement only the bounded contact-attempt record and Lead
history foundation. An attempt is immutable evidence linked to its Lead and stores
the responsible rep, manual source, channel, direction, actual event time, number,
duration, factual answered/connection disposition, lifecycle result and the
location, linked customer/contact, lifecycle status and impersonation provenance
in effect when it was recorded.

Manual entries must be identified as manual and never presented as provider
verification. A sent message or voicemail alone is not a real connection. Manual
records cannot claim a recording or transcript, and recording an attempt must not
change Lead status, assignment, follow-up or Customer Activity. Every write must
recheck current Lead/location authorization, and each history row remains scoped
to its recorded location and currently visible related customer data.

This approval covers the additive schema, enums/model/action, the manual recording
form and Lead history, regression tests and handoff documentation. It does not
authorize a provider integration, outbound call or message, dialer, queue, AI,
nurture/cadence behavior, automated punishment or manager policy, B8, slice 4,
production action or a force push. The implementation returns for owner review
after local checks, rendered browser review and exact-commit GitHub checks.

## 64. npm security fixes and merge guideline correction (2026-10-08)

**Approved by Jacob.** Fix the critical npm security warnings reported by
`npm audit`, using non-breaking updates only. This is the owner approval that
[project-brief.md](project-brief.md) requires for a dependency change, and it is
limited to these security fixes: no new package, no major or otherwise breaking
upgrade, and no feature, permission or behaviour change.

A critical warning that can only be fixed by a breaking or major version change,
or that cannot be fixed at all, is not forced. It is left as it is and reported
to Jacob with a plain-English recommendation. The before and after `npm audit`
results are reported as they actually are and recorded in
[security-notes.md](security-notes.md).

The same approval corrects the merge line in `.ai/guidelines/collaboration.md`,
which still described the per-checkpoint merge approval that decision 61
superseded. It now matches decision 61 and [collaboration.md](collaboration.md).
No rule changed; the short guideline was brought back in line with the record.

## Slice 4 decisions (65 to 74)

Decided by Jacob in conversation on 2026-10-08 and recorded here by planning job
#9. The technical plan is [slice-4-plan.md](slice-4-plan.md).

## 65. Slice 4 scope, steps and implementation approval (2026-10-08)

**Approved by Jacob.** Slice 4 delivers the scheduling engine and a simple working
queue in Marketing > Contact Center: a "due now" count and a **Next lead** button
that gives the setter one Lead nobody else can get. They call on their own phone
and log the result with the existing slice 3 manual contact-attempt form. The
system schedules the next attempt.

It is built in three separately reviewed steps: **4a** settings and timezones,
**4b** the engine, **4c** the queue screen.

**Jacob's approval of this planning batch is his explicit implementation approval
for slice 4 as described in decisions 65 to 73 and in
[slice-4-plan.md](slice-4-plan.md), and for nothing beyond it.** Each step is
still built on its own, checked, reviewed and reported before the next begins.

Not included, and not authorized: a dialer, a provider integration, any outbound
call, text or email, the new-lead popup, the snooze tray, Aged Leads, AI,
penalties, new packages, paid services, slice 5, B8, Projects and any production
action.

## 66. The call cadence (2026-10-08)

**Approved by Jacob.**

- **Phone only.** One cadence for the whole company.
- **Default:** day 1 immediately, about 30 minutes later, and about 2 hours later;
  day 2 three attempts; day 3 two; days 4 and 5 two each. Twelve attempts. On
  days 2 to 5 the attempts are spread evenly through calling hours.
- **Editable** on a settings page under Company > Settings. Only Owner and
  Leadership may change it, through a new company-scope permission. A change
  applies to attempts scheduled from then on and is audited.
- **Every newly captured Lead enters the cadence.** Leads that existed before
  go-live do not enter automatically.
- **Day 1 is the day the Lead arrives,** unless it cannot be called that day (for
  example it arrives at 9 pm); then the next calling day is day 1.
- **Attempts are never skipped.** One that does not fit before closing waits for
  the next opening and the rest of the schedule shifts along, so every Lead still
  gets all twelve.

Rules out: per-Location or per-source cadences; text or email steps; dropping an
attempt because the day ran out.

## 67. Leaving the queue; a logged attempt now advances the cadence (2026-10-08)

**Approved by Jacob.** A Lead leaves the queue when:

- a real two-way connection is logged; a person then decides the next step;
- it is disqualified;
- a manual Deferred Follow-up is scheduled; that B7 callback takes over and stays
  a separate workflow, unchanged; or
- it reaches the last attempt with no connection, when it is marked **"Cadence
  finished, no contact"** so managers can find it.

A voicemail or a sent message does not end it. The marker is not a lifecycle
status: it adds and changes no B7 status.

**This changes one slice 3 rule.** Decision 63 said recording an attempt has no
follow-up side effect. From slice 4, logging an attempt advances the cadence.
Everything else in decision 63 stands: an attempt still never changes the Lead's
lifecycle status or its assignee, and is still not Customer Activity.

## 68. Each Location has its own timezone (2026-10-08)

**Approved by Jacob.** Each Location gets its own timezone, default US Eastern.

**This supersedes the part of decision 40** that said one company timezone
applies until locations in different timezones need their own.

- Calling hours are evaluated in the Lead's Location timezone.
- An unrouted Lead uses Eastern until it is routed, then its Location's time.
  Jacob accepts this as an edge case.
- In the queue, times are shown in the setter's own device timezone, with the
  Location's local time beside them.

**Known follow-up, not built in slice 4:** existing screens (Received time,
follow-up dates and the rest) keep company time for now. Switching them to
Location time is a separate later job and needs its own approval.

## 69. Calling hours and calling days (2026-10-08)

**Approved by Jacob.**

- **Hours:** 8:00 am to 9:00 pm local by default. One set of hours for the whole
  company, applied in each Location's local time.
- Owner and Leadership may **narrow** them (the team is small at first) but never
  outside 8 am to 9 pm. A **Reset to default** button restores the default.
- **Days:** tickable, all seven by default. They call on Sundays.
- No holiday calendar.

## 70. Who works the queue, holds, order and skipping (2026-10-08)

**Approved by Jacob.**

- **Who:** Owner, Leadership, Location Manager and Call Center Agent, through a
  new permission. Not Sales Consultant. People only receive Leads at Locations
  they already have access to. Unrouted Leads only go to people with company-wide
  access.
- **Claiming is a temporary hold, not assignment.** It never changes the
  assignee, and every Lead in the cadence is in the shared pool whoever it is
  assigned to.
- **A hold lasts 5 minutes by default,** adjustable on the settings page. It ends
  when the attempt is logged. If the setter walks away, the Lead returns to the
  queue when the hold expires. One held Lead per setter.
- **Order:** never-called new Leads first, newest first; then follow-up attempts,
  most overdue first. Setters get the next Lead and cannot pick from a list.
- **Skip:** a setter may put a Lead back without calling but must choose a short
  reason. It is recorded for managers, with no automatic penalty, and the Lead is
  not offered straight back to the same setter.
- **Disqualify on the card,** using the existing B7 rules, permissions and
  reasons. Jacob expects spam and test leads to be the main case.

This does not change decision 44: Sales Consultants still do not claim Leads, and
a hold is not a way to take one.

## 71. Failure rules, Put back in queue and the manager summary (2026-10-08)

**Approved by Jacob.**

- **Attempts never pile up.** A Lead has only one attempt due at a time, and the
  next is scheduled only after the current one is logged.
- **After an outage or restart the system catches up by itself,** with nothing
  lost or duplicated, and open holds expire normally.
- **If a held Lead is disqualified or routed elsewhere, or the setter loses
  access, the hold ends at once and the setter is told.**
- **Every save rechecks current authorization.**
- **Put back in queue.** People who may already assign Leads there get this
  action, with a required note, recorded in history, to correct a wrongly logged
  result or to restart a Lead marked "Cadence finished, no contact".
- **Manager summary** at the top of the queue: due now, longest wait, Leads
  currently held and the cadence-finished count, with a warning if the background
  timer has not run in the last 10 minutes. No email or text alerts.

(Changed for Call Center Agents by decision 85: they are to see the manager
summary and Put back in queue only when Leadership switches that on. Not built
yet.)

## 72. Future requirement: a confirmation text outside calling hours (2026-10-08)

**Recorded by Jacob as a future requirement. Not built and not authorized.**

Once texting exists, a Lead that arrives outside calling hours still gets a
confirmation text immediately. No provider is chosen, and a paid service needs
Jacob's separate approval. Slice 4 sends nothing to anyone.

## 73. How slice 4 is designed (2026-10-08)

The technical contracts the roadmap listed as pending are now specified in
[slice-4-plan.md](slice-4-plan.md). In short:

- **The queue is read from stored state, never driven by timer events.** Each
  Lead in the cadence stores the one attempt that is due. Nothing is generated in
  advance, so nothing can pile up, and after an outage there is nothing to replay.
- **One conditional database update decides who gets a Lead,** the same pattern
  the B7 lifecycle revision uses. It needs nothing only one database offers, and
  is proven on SQLite and MySQL 8.4, including by real simultaneous processes.
  The database itself refuses a second hold for one setter and a second use of
  one cadence step.
- **The background timer is a safety net and a heartbeat.** It runs every minute,
  tidies up and proves it is alive. The queue stays correct if it stops; the
  manager summary says so. It needs Laravel's scheduler enabled on staging and
  nothing else: no queue worker, no new package, no paid service.
- **Times are stored in UTC** and calling hours are checked live in the Lead's
  Location timezone. A scheduled time is never recalculated when a Lead moves or
  a setting changes.
- **"Cadence finished, no contact" lives on the cadence record,** not in the Lead
  lifecycle. No B7 status, history row or revision is touched.
- **Queue activity has its own append-only history;** audit events are kept for
  the settings, the timezone and Put back in queue.
- **Two new permissions:** `settings.contact_center.manage` (company scope; Owner
  and Leadership) and `leads.work_queue` (location scope; Owner, Leadership,
  Location Manager, Call Center Agent).

Choices made while planning, where Jacob's decisions left a detail open. Each is
in the plan, and each is for Jacob to confirm or change:

- **"About 2 hours later" on day 1** is read as about two hours after the Lead
  arrived, so the third attempt follows the second by 90 minutes. It is editable
  on the settings page.
- **"Spread evenly"** means the first attempt of the day at opening and the rest
  at equal gaps: 8:00 am, 12:20 pm and 4:40 pm for three; 8:00 am and 2:30 pm for
  two, with default hours.
- **What counts as an attempt:** an outbound phone call that did not connect. A
  real two-way connection on any channel ends the cadence. A text or email that
  was only sent is kept as evidence and does not use up an attempt.
- **Skip reasons** are a short fixed list: No usable phone number; Needs a
  manager to look at it; I cannot make this call right now; Other (with a note).
  It is not a sixth managed list (decision 39).
- **"Not offered straight back"** means not for one hold length (5 minutes by
  default). Other setters are offered it at once.
- **The setter's pool is every Location they have queue access at,** whichever
  Location is selected in the header, because a Lead is authorized by its own
  Location (decision 40) and setters do not pick.
- **"Managers" for Put back in queue and the summary** are the people who may
  already assign Leads there, as decision 71 says. That includes Call Center
  Agents, so in practice everyone who works the queue sees the summary.
- **Put back in queue is offered for any Lead that has left the queue** and is
  New, Working or Qualified. A disqualified Lead is reopened first and a Deferred
  Follow-up is resumed first, under the unchanged B7 rules. A finished Lead
  restarts at attempt 1; any other resumes where it stopped.
- **A Location's timezone is set by whoever may manage Locations** (the Owner
  today), on the existing Locations page, from a list of United States zones.
- **Limits on the settings page:** hours in 15-minute steps and at least one hour
  long; at least one calling day; a hold of 1 to 30 minutes; a cadence of 1 to 14
  days with 1 to 6 attempts a day.
- **Contact Center still opens on Leads.** Call Queue is added beside it.

## 74. The queue permission is added in step 4a (2026-10-08)

**Approved by Jacob** in the goal of build job #10 (step 4a): "Add the new queue
permission to Owner, Leadership, Location Manager and Call Center Agent as the
plan defines, without widening any existing access."

The plan had placed `leads.work_queue` in step 4b. This moves only the step in
which it is added to the code: the permission (`WorkLeadQueue`, location scope)
and its four role grants are added in 4a. Its name, scope, roles and rules are
exactly as decisions 70 and 73 and the plan define them.

In 4a the permission guards nothing: no route, navigation item, policy, action or
screen reads it, so holding it changes what nobody can see or do. Step 4b builds
the engine that uses it and step 4c the screen. Nothing else moves between steps.

## Slice 5 decisions (75 to 84)

Decided by Jacob in conversation on 2026-10-08 and recorded here by planning job
#13. The full rulebook is
[slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md); its rule
numbers are given in brackets. **Design only: none of these decisions authorizes
code, a table, a permission, a screen or Projects.**

## 75. Slice 5 is a design rulebook, and later appointment work must follow it (2026-10-08)

**Approved by Jacob.** Slice 5 delivers the rulebook for appointment issuance and
the handoff of ownership from Contact Center to Sales, and nothing else. Later
appointment work must follow it. Building any part needs its own reviewed plan
and Jacob's explicit approval.

Rules out: treating the rulebook as implementation approval; filling a gap in it
by guessing.

## 76. When a Lead can be booked, and who can book it (2026-10-08)

**Approved by Jacob.** (Rules 1, 2 and 5.)

- A Lead can be booked only when it is Qualified, routed to a Location and
  linked to a customer with a property address.
- A booking needs a salesperson, a date and time, and a length. The length
  defaults to 90 minutes.
- Owner, Leadership, Location Manager and Call Center Agent can book. A Sales
  Consultant can book only a Lead already assigned to them.
- The chosen salesperson must have Sales Consultant access at the Lead's
  Location.
- Backoffice refuses to book a salesperson into a time that overlaps another
  Backoffice appointment. Personal calendar conflicts wait for Google Calendar.

## 77. "Issued" happens when the appointment is saved (2026-10-08)

**Approved by Jacob.** (Rules 3 and 4.) The moment the appointment is saved: the
Lead is assigned to the salesperson; it gets a new status, **Appointment
Booked**; it leaves the call queue; the issued time is stored permanently so
Leads Issued can be counted; an Activity entry is written; and the salesperson
is notified in the app and by an email with a link and no customer details.

Qualified keeps its meaning from decision 55. **Once appointments are built,
Appointment Booked is one of the things that removes a Lead from the slice 4
call queue,** alongside the exits in decision 67. Slice 4 is not changed now.

## 78. Rescheduling, and moving an appointment to another salesperson (2026-10-08)

**Approved by Jacob.** (Rules 6 and 7.)

- **Reschedule:** the salesperson or a manager changes the time. Same
  salesperson, still issued, one issued Lead. The old time is kept in history.
- **A different salesperson:** only a manager. It is a handoff with an Activity
  entry and a notification, and history shows both people.

## 79. Customer cancellation, Cancelled Appointments and cancellation reasons (2026-10-08)

**Approved by Jacob.** (Rules 8 and 11.)

- A cancelled appointment is kept as a cancelled record with who cancelled and a
  reason. The Lead stays with the salesperson to rebook and still counts as
  issued.
- Not rebooked within 30 days of the cancellation (an adjustable setting), it
  comes off the salesperson's list automatically and goes to the call center in
  its own group, **Cancelled Appointments**.
- Cancelled Appointments is a saved segment, selectable on its own for the call
  queue and, once broadcasts exist, for reactivation texts and emails under the
  consent, suppression and send-window safeguards in
  [product-architecture.md](product-architecture.md).
- A call center rebooking may go to any salesperson and is recorded as a
  re-issue with full history.
- **Cancellation reasons are a managed list on the existing Lead & Sales Lists
  page.** The list is reserved; the reasons come from Sales Leadership.

**This supersedes the part of decision 39** that fixed the managed lists at
five. Nothing is added until appointment work is approved.

## 80. Sending an issued Lead back, and "booked in error" (2026-10-08)

**Approved by Jacob.** (Rules 9 and 10.)

- A manager can send any issued Lead back to Contact Center by hand with a note.
  It re-enters the call queue.
- A manager can mark an appointment "booked in error" with a note. The handoff
  is undone, the Lead returns to Contact Center and it does not count as issued.
  Manager-only and recorded.

## 81. A salesperson may pick a released Lead back up (2026-10-08)

**Approved by Jacob.** (Rule 12.) When a Lead was released from a salesperson to
the call center, after a cancellation or an expired Pending, and the homeowner
contacts that salesperson, the salesperson can take it back with a short note
and continue where it left off: same history, same bid, no new issued count. It
leaves the call center groups and an Activity entry is written. If it has
already been rebooked with a different salesperson, a manager must make the
move.

**This is a narrow exception to decision 44** ("Sales Consultants do not claim
Leads"). It covers only a Lead that the same salesperson was issued before.
Everything else in decision 44 stands.

## 82. A Project is created when the sale is Won (2026-10-08)

**Approved by Jacob** as the target rule. (Rule 13.) Before Won, the record is
the Lead and its appointment.

**This replaces the Project-from-Qualified assumption in decision 54** (and the
same idea in decision 27) **and the historical B8 sequence.** It settles the
"exact Project creation point" that decision 59 left open. Nothing is built and
Projects stay unauthorized.

The historical permission rows it affects are listed in the rulebook, rule 13:
`leads.convert`, `projects.create` and `projects.sales_outcome`, with their
matrix rows in [permission-model.md](permission-model.md). None is in the code.
Creating a Project directly with no Lead, and where Pending and Lost are
recorded, are not decided here and need revalidation before any Project work.

## 83. Pending ownership, provisional (2026-10-08)

**Decided by Jacob, provisional until Sales Leadership confirms.** (Rule 14.)
The salesperson owns a Pending for an adjustable period, starting at 30 days,
then it releases to Contact Center Rehash. **Offer Valid Until is a separate
concept and stays open.**

## 84. What slice 5 leaves open (2026-10-08)

**Recorded by Jacob as open.** Nothing below may be assumed:

- which salesperson an issued Lead counts for after a reassignment or a re-issue
  (Sales Leadership);
- offer validity (Sales Leadership);
- the cancellation reasons themselves (Sales Leadership);
- no-shows and appointment outcomes, which belong to the later after-action
  report.

Two readings made while writing the rulebook, for Jacob to confirm or change:

- **"A manager"** means Owner, Leadership or Location Manager at the Lead's
  Location, not a Call Center Agent, as decision 52 already uses the word.
- **A Sales Consultant booking their own Lead books it for themselves,** because
  decision 44 keeps handing out Leads with management and the call center.

Both readings were confirmed by Jacob on 2026-10-09: decisions 88 and 89.

Gaps the decisions do not cover are listed in the rulebook under "Not decided
here". The appointment build plan brings them to Jacob.

## Decisions after the slice 4 and 5 reviews (85 to 89)

Decided by Jacob in conversation on 2026-10-09, in answer to the decisions the
job #12 and job #13 handoffs asked for.

## 85. Call Center Agents see the Call Queue's manager part only when Leadership switches it on (2026-10-09)

**Decided by Jacob. Product direction: LOCKED. Implementation: proposed, not
planned and not approved to build.**

Call Center Agents get a switch that Leadership can turn on to let them see the
management part of the Call Queue. Without it they do not see it.

This changes decisions 70 and 71 for Call Center Agents only. Those gave the
manager summary and Put back in queue to "people who may already assign Leads
there", which includes agents, and step 4c was built that way. **Until the
switch is built, the screen stays as built: agents see the manager part at
their own Locations.** Owner, Leadership and Location Manager are unaffected.

Jacob answered the open questions later the same day (2026-10-09):

- **One switch for each agent.** Leadership allows the manager part person by
  person, not for all agents at once and not by Location.
- **It starts off.** An agent sees the manager part only after someone switches
  it on for them. When the switch is built, agents who see it today stop seeing
  it until then.
- **Owner and Leadership may change it:** the people who already manage the
  Contact Center settings, on that settings page. Each change is recorded in
  the audit history.
- **It covers both places:** on the Call Queue page the summary, the timer
  warning, the cadence-finished list and Put back in queue; and on the Lead
  page the "Call queue" panel, the queue history and Put back in queue. Jacob
  left the exact coverage to the builder, who chose both so that an agent does
  not keep a manager tool through the Lead page.

With these answers the switch was approved to build ("you build everything",
2026-10-09).

**Built on 2026-10-09.** How it was built:

- The switch is a yes or no on the person, not a permission and not a role
  grant. An agent with it off still assigns Leads and works the queue as
  before; only the manager part of the queue is withheld.
- One rule decides it everywhere: the person may assign Leads at that Location,
  and where that comes from Call Center Agent access their switch must be on.
  A person who is an agent and also a Location Manager at the same Location
  keeps the manager part there whatever the switch says.
- It is changed on Company > Settings > Contact Center, in a new section
  "Manager view for Call Center Agents" that lists every active person with
  Call Center Agent access. Each switch saves by itself and writes the audit
  event `staff.queue_manager_view_changed`.
- When this reaches staging or production, every agent's switch is off, so
  agents stop seeing the manager part until someone switches it on for them.

## 86. Skip after calling hours close stays as built (2026-10-09)

**Decided by Jacob.** When a Location's calling hours close while a setter holds
a Lead, the card is replaced by "Calling hours have closed for this lead." The
setter cannot record a skip reason for that Lead, and the hold simply ends. Skip
is not added to that notice.

This corrects one reading in the slice 4 plan, which said a skip is accepted
outside calling hours while the setter still holds the Lead. The engine still
accepts such a skip; the screen no longer offers it.

## 87. The MySQL deadlock fix is approved as its own job (2026-10-09)

**Approved by Jacob for implementation.** Scope is the follow-up recorded in the
4b and 4c handoffs and nothing more: take the Lead lock before the cadence lock
in `EndQueueHold::atLocation` and `RunQueueTick::releaseExpiredHold`, and retry
the hooked actions when the database reports a deadlock, as the claim already
does. No rule, screen, table, permission or audit event changes. It follows the
usual workflow: built on its own branch, proved on MySQL 8.4, reported, and
stopped for review.

**Built on 2026-10-09.** What was built, and what it deliberately leaves alone,
is in [slice-4-plan.md](slice-4-plan.md), contract 5 ("Locks") and "Known limits
and recorded follow-ups". Two things were learned while building it:

- Putting the Lead before the cadence removes the Lead-and-cadence deadlock,
  but one pair cannot share an order: deactivating a Location takes the
  Location row and then a Lead row, and a claim takes them the other way round.
  That pair relies on the retry.
- The existing test check "no process may end in an error" in the
  real-process queue tests never failed on an error, because of how the test
  library's key check was called. It is corrected, so those tests now enforce
  what they say.

## 88. "A manager" in the appointment rulebook (2026-10-09)

**Confirmed by Jacob.** In rules 6, 7, 9, 10 and 12 of the slice 5 rulebook, "a
manager" means Owner, Leadership or Location Manager with access at the Lead's
Location. It does not mean a Call Center Agent. An agent can book, and can
rebook a Lead in Cancelled Appointments, but cannot reschedule a salesperson's
existing appointment, move it to another salesperson, send an issued Lead back
or mark one "booked in error".

## 89. A Sales Consultant booking their own Lead books it for themselves only (2026-10-09)

**Confirmed by Jacob.** A Sales Consultant cannot book their own Lead for a
colleague. Handing a Lead to someone else stays with management and the call
center (decision 44).

## Slice 6 decisions (90 to 96)

Decided by Jacob on 2026-10-10 ("Agreed to all"), in answer to the seven
questions the slice 6 plan asked before step 6a. The plan is
[slice-6-appointments-plan.md](slice-6-appointments-plan.md); the rules it
follows are in [slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md).

## 90. Slice 6 is appointment booking, in three steps; step 6a is approved to build (2026-10-10)

**Approved by Jacob.** Slice 6 builds appointment booking from the slice 5
rulebook in three reviewed steps: 6a books an appointment, issues the Lead,
lets a manager mark it "booked in error", tells the salesperson and lists
appointments in Sales > Calendar; 6b reschedules, moves to another salesperson,
records a customer's cancellation and sends an issued Lead back; 6c releases
and returns Leads.

**Step 6a is approved for implementation,** with the two permissions the plan
names: `appointments.book` (Owner, Leadership, Location Manager, Call Center
Agent) and `appointments.manage` (Owner, Leadership, Location Manager). A Sales
Consultant holds neither and may book only a Lead already assigned to them, for
themselves (decision 89).

Steps 6b and 6c are approved in order only. Each is planned in detail after the
step before it is reviewed, and 6b and 6c still need the plan's decisions 8 to
11. Left out of slice 6: Pending and Rehash, Projects and the sales outcome,
commissions, Google Calendar, a calendar grid, no-shows and appointment
outcomes, and any message to a customer.

**Step 6a was built on 2026-10-10.** What was built, and the readings made
while building it, are in the plan under "Step 6a as built".

## 91. The salesperson is told inside the app by a bell in the header (2026-10-10)

**Decided by Jacob.** Backoffice gets a small bell in the header with a count
of unread notices, opening a list of notices that each link somewhere. It is
built once in step 6a for "you have a new appointment" and is reused by later
work. It uses Laravel's own notifications table; no package is added.

## 92. An appointment's time is the Lead's Location's time (2026-10-10)

**Decided by Jacob.** An appointment is entered and shown in the Lead's
Location's own timezone (decision 68), always labelled. It is stored in UTC
with the timezone it was entered in kept beside it.

## 93. What may be booked (2026-10-10)

**Decided by Jacob.** Any future time on any day, in 15-minute steps. The
length is from 30 minutes to 4 hours in 15-minute steps and starts at 90
minutes (rule 1). There is no working-hours limit: the salesperson's real
availability is not in Backoffice until Google Calendar is (rule 5).

## 94. Booking takes the Lead from whoever has it (2026-10-10)

**Confirmed by Jacob.** When a Lead that is assigned to someone else is booked
with a salesperson, the earlier assignment ends and stays in the assignment
history, as any handoff does today.

## 95. While an appointment stands (2026-10-10)

**Decided by Jacob.** A Lead with an upcoming appointment cannot be moved to
another Location, returned to central or disqualified: the person is told to
cancel or undo its appointment first. Until step 6b brings cancelling, the way
to free such a Lead is a manager's "booked in error".

If the salesperson loses access to the Lead or is deactivated, the appointment
stays on record, the Lead becomes unassigned as it does today
(permission-model.md, rule 9), and the Lead says its appointment needs a
salesperson, for a manager to fix.

## 96. "Booked in error" puts the Lead back exactly as it was (2026-10-10)

**Decided by Jacob.** Rule 10's "the handoff is undone" means the Lead is as it
was a moment before the booking: Qualified, assigned to whoever it was assigned
to then (or nobody), and, if it was in the call queue, back in it at the
attempt it had reached. The issue does not count (rule 10). It needs a manager
and a note and is recorded.

## Slice 6 decisions before step 6b (97 to 99)

Given by Jacob on 2026-10-10 in answer to questions 8, 9 and 11 of the slice 6
plan. Question 10 (a cancellation that does not come from the customer) was not
answered and is asked again with the step 6b plan. Nothing here approves a
build: step 6b still needs its plan approved.

## 97. A Lead that comes back to Contact Center is Qualified (2026-10-10)

**Decided by Jacob.** A Lead that goes back from Sales to Contact Center (a
manager sends it back, or it is released after a cancellation) shows the status
Qualified again.

**Not settled: who it is assigned to.** The plan recommended "nobody". Jacob's
answer read "it should come back as qualified and assigned to somebody". The
step 6b plan asks which is meant, and who "somebody" would be. Nothing is built
on either reading until he says. **Settled the same day by decision 103:
nobody.**

## 98. Cancelled Appointments gets its own button, worked as hot Leads (2026-10-10)

**Decided by Jacob.** The Cancelled Appointments group (rulebook, rule 8) is not
mixed into the one shared call queue. It gets its own button on the Call Queue.
These Leads "need to be prioritized next to new leads because those are very
hot leads".

Jacob's message said "canceled deployments"; it is read as cancelled
appointments, the group question 9 asked about. The group and its button are
built in step 6c. How "next to new Leads" appears on the screen is settled in
the 6c plan.

## 99. Rebooking after a cancellation adds no issued count (2026-10-10)

**Decided by Jacob.** After a cancellation the Lead can be booked again with
the same salesperson, and that rebooking does **not** add to Leads Issued: it
is one Lead that was issued and brought back.

The reason, in Jacob's words: salespeople are "heavily tracked on NSLI" (Net
Sales per Lead Issued), a second count "would actually negatively impact their
NSLI, and we want to encourage them to get canceled bookings back on the
calendar".

## Slice 6 decisions on the step 6b plan's readings (100 to 102)

Given by Jacob on 2026-10-10 in answer to the four readings the step 6b plan
said it would build "unless Jacob says otherwise". Three are decided here. The
fourth (whether sending a Lead back always cancels its upcoming appointment) he
is still thinking about; it is question 20 in the plan. Nothing here approves a
build.

## 100. Whether a Lead that leaves Sales still counts as issued depends on the reason (2026-10-10)

**Decided by Jacob.** This replaces the plan's reading "a Lead that is sent
back still counts as issued". In his words: it "should only still count as
issued if it was done for the correct reason. If it was disqualified because
it should never have been booked in the first place, then it should not count
as issued."

So a Lead that should never have been booked does not count as issued, however
it leaves the salesperson: sent back, or disqualified. Any other reason leaves
it counted. How the person says which it is, and who may say it, is question
19 in the plan. Rule 10 ("booked in error", manager-only and recorded) already
takes an issue away for a standing appointment; this extends the same idea to a
Lead leaving Sales later.

## 101. A Lead with a cancelled appointment can still be moved, returned or disqualified (2026-10-10)

**Decided by Jacob.** This reverses the plan's reading that such a Lead must be
sent back first. A Lead whose appointment was cancelled can be moved to another
Location, returned to central or disqualified. His example: it was cancelled
because of the wrong service area and should have been at the other Location.

Decision 95 is unchanged for a Lead whose appointment is still upcoming: cancel
or undo it first. What a cancelled Lead looks like after a move or a return is
question 21 in the plan.

## 102. A passed appointment is not changed, provided a missed one has a clear way to be booked again (2026-10-10)

**Decided by Jacob, with a condition.** An appointment whose time has passed
cannot be rescheduled, moved or cancelled. He agreed to that on the condition
that there is "a clear path" for the case he described: the appointment was
missed and the customer messages in afterwards to set a new time. The path is
question 22 in the plan. What happened at the passed appointment is still for
the after-action report (decision 84).

## Slice 6 decisions on the step 6b plan's questions 12 and 14 (103 and 104)

Given by Jacob on 2026-10-10. The plan's questions 13 and 15 to 22 are not
answered yet, and step 6b is still not approved.

## 103. A Lead that comes back to Contact Center is assigned to nobody (2026-10-10)

**Decided by Jacob** ("correct no one"), completing decision 97. A Lead that
goes back from Sales to Contact Center is Qualified and unassigned. The call
queue hands it to the next available person, as it does every Lead, and the
Appointment card says why it came back.

## 104. A cancellation by the salesperson or the company is recorded the same way (2026-10-10)

**Decided by Jacob.** A cancellation that does not come from the customer uses
the same record and the same rules as rule 8. "Who cancelled" offers three
choices: the customer, the salesperson, the company. The Lead stays with the
salesperson, still counts as issued, and the 30 days run the same way.

## Slice 6 decisions that complete the step 6b plan (105 to 114)

Given by Jacob on 2026-10-10 in answer to the plan's questions 13 and 15 to 22,
with two follow-up answers the same day. With them every question the step 6b
plan asked is answered, and **step 6b is built under decision 90**, which
approved the three steps in order.

## 105. A Lead a manager sends back re-enters the ordinary call queue (2026-10-10)

**Decided by Jacob.** His first answer was "no nurturing automations, just
manual reach outs"; asked which of three readings he meant, he chose "Call
Queue, ordinary place". A Lead sent back to Contact Center starts the call
cadence over at attempt 1, due at the next opening of calling hours, in line
with follow-up calls. It is not treated as a new Lead. Nothing is sent to the
customer automatically; Backoffice sends nothing to customers at all.

## 106. A Lead whose appointment is cancelled shows "Appointment cancelled" (2026-10-10)

**Decided by Jacob.** A new Lead status, Appointment cancelled, for a Lead
whose appointment was cancelled and that is still with the salesperson.
Booking it again returns it to Appointment booked.

## 107. Who may record a cancellation (2026-10-10)

**Decided by Jacob.** Anyone who may book that Lead: a manager, a Call Center
Agent, and the salesperson whose appointment it is.

## 108. The Cancellation Reasons list, and what "Other" asks (2026-10-10)

**Decided by Jacob.** The list starts with: Holding off project; Went with
someone else; No longer needed; Other. Owner and Leadership manage it on Lead &
Sales Lists like the other lists.

"Other" needs a typed note and asks whether the Lead is disqualified. Asked
what "disqualified" should do, he chose "Disqualify the Lead": the cancellation
also disqualifies the Lead in the same step, so it leaves the salesperson and
shows Disqualified. Whether it still counts as issued is asked as decision 110
says.

Jacob listed "Other" first; it is stored last, where the other lists keep
theirs, and Leadership can reorder it.

## 109. Rebooking with a different salesperson is for a manager (2026-10-10)

**Decided by Jacob.** While a cancelled Lead is still with its salesperson, a
Call Center Agent or the salesperson can book it again with the same
salesperson only. Booking it with a different salesperson needs a manager and
is recorded as a move (rule 7). After the 30-day release, in step 6c, the call
center may choose anyone (rule 8).

## 110. How a person says whether a Lead leaving Sales still counts as issued (2026-10-10)

**Decided by Jacob,** carrying out decision 100. When a Lead that is with Sales
is sent back, moved to another Location, returned to central or disqualified,
one question is asked with two answers: "It should never have been booked" (the
issue no longer counts, as "booked in error" does) or "Another reason" (it
still counts). Only a manager may give the first answer. When anyone else moves
or disqualifies such a Lead it stays counted.

## 111. Sending back and moving stay two separate actions (2026-10-10)

**Decided by Jacob.** "Move to another salesperson" keeps the appointment and is
for when the appointment should still happen. "Send back to Contact Center" is
for a Lead the call center must work again: it always cancels an upcoming
appointment, and its dialog points to Move. A salesperson cannot send a Lead
back themselves.

## 112. A cancelled Lead that is moved or returned is treated as sent back (2026-10-10)

**Decided by Jacob,** carrying out decision 101. A Lead that is with Sales and
is moved to another Location or returned to central leaves the salesperson and
is then exactly as a Lead sent back: Qualified, assigned to nobody, back in the
call queue as decision 105 says, with the counting question of decision 110.
Its appointments stay on its record. One that is disqualified is Disqualified,
with the same counting question.

## 113. After an appointment's time has passed, another can be booked (2026-10-10)

**Decided by Jacob,** meeting the condition in decision 102. Once an
appointment's time has passed the Appointment card offers "Book another
appointment", under the rules for rebooking after a cancellation: with the same
salesperson by anyone who may book the Lead, with a different one by a manager,
and no new issued count. Backoffice does not record whether the passed
appointment was held or missed; that is for the after-action report (decision
84).

**Changed by decision 126 (2026-10-12):** another appointment can be booked
only once the one that passed has been marked run or no-show.

## 114. The call center is told when a Lead came back from Sales (2026-10-10)

**Decided by Jacob,** while step 6b was being built: "if a cancelled appmt is
going back to the queue then the agent needs to know they are calling a
cancelled appmt, because that has a different script". A Lead that came back
from Sales says so wherever the call center works it: on the Call Queue card
when it is handed out, and on the Lead page. It says whether its appointment
was cancelled, by whom and for which reason, or that a manager sent it back
and why. The marker stays until the Lead is booked again or disqualified.

## Slice 6 decisions for step 6c (115 and 116)

Given by Jacob on 2026-10-10 in answer to questions 25 and 26 of the step 6c
plan. His answers to 23 and 24 are recorded below as far as they go; 27 and 28
he asked to have explained, and they are asked again. Step 6c is not approved.

## 115. Cancelled appointments are called on the same schedule as every other Lead (2026-10-10)

**Decided by Jacob** ("correct"). A Lead in the Cancelled Appointments group is
called on the same cadence and within the same calling hours as every other
Lead, starting at attempt 1. There is no second set of settings.

## 116. The days before a cancelled Lead is released are one setting (2026-10-10)

**Decided by Jacob** ("yes"). One number on Contact Center settings, from 1 to
180 days, starting at 30, changed by the people who change those settings
today (Owner and Leadership). A Lead is released once that many full days have
passed since its cancellation was recorded.

**Said by Jacob on questions 23 and 24, not yet a decision.** On 23: the
cancelled appointment queue "should only have appointments that were cancelled
that are not disqualified". On 24: "it should probably just be a new queue",
with the note that he is not sure how the queues work today, and that what
matters is that a cancelled appointment gets called and the call center knows
it is a cancelled booking to be rebooked. Both read as agreeing with the plan's
recommendations (a separate queue with its own button, holding every cancelled
Lead that has come back to the Contact Center). They are confirmed with him
before anything is built on them. **Confirmed the same day: decision 117.**

## Slice 6 decisions that complete the step 6c plan (117 to 120)

Given by Jacob on 2026-10-10 after the queue as it works today, and questions
27 and 28, were explained to him. With them every question the step 6c plan
asked is answered, and **step 6c is built under decision 90.**

## 117. Cancelled appointments are a queue of their own (2026-10-10)

**Decided by Jacob** ("Yes, you're correct... I think it's a good idea for 23
and 24"). There is a second call queue, Cancelled Appointments, with its own
button beside "Next lead" and its own count. It holds every Lead that has come
back to the Contact Center with its last appointment cancelled and is not
disqualified: released after the waiting period, sent back by a manager, or
moved to another Location or returned to central. "Next lead" never hands one
out, so the agent knows before dialling that it is a cancelled booking to try
to rebook. The Lead that came back most recently is handed out first.

A Lead whose appointment is cancelled still stays with its salesperson first
(rule 8). The call center gets it after the waiting period, or sooner when a
manager sends it back.

**This changes decision 105** for a Lead that comes back with a cancelled
appointment: it goes into this queue, not the ordinary one. A Lead that comes
back after an appointment whose time passed still goes into the ordinary queue.

## 118. The salesperson is warned before a Lead is released (2026-10-10)

**Decided by Jacob** ("that looks fine. I don't mind that warning"). Sales >
Calendar shows how many days are left on each cancelled Lead. The bell warns
the salesperson 5 days before the Lead is released and tells them again when
it is. No email.

## 119. The waiting period starts at 14 days, not 30 (2026-10-10)

**Decided by Jacob** ("Let's change it from 30 days to 14 days"). A cancelled
Lead that is not booked again stays with its salesperson for 14 days. This
replaces the 30 days in rule 8 and the starting value in decision 116. It is
still one adjustable number on Contact Center settings, from 1 to 180.

## 120. Who may take a released Lead back (2026-10-10)

**Decided by Jacob,** carrying out rule 12. Only the salesperson a Lead was
released from by the timer may take it back, with a short note. A Lead a
manager sent back cannot be taken back that way; a manager can still hand it
over. There is no time limit: it can be taken back for as long as it is still
with the Contact Center and has not been booked with someone else or
disqualified.

## Decisions after slice 6 (121 and 122)

Given by Jacob on 2026-10-11, after slice 6 was merged.

## 121. An appointment's outcome and a proposal's outcome are two different things (2026-10-11)

**Decided by Jacob.** They are recorded separately and never as one field:

- **The appointment outcome** is what happened to the visit. He named: a
  no-show, a cancellation, a reschedule.
- **The proposal outcome** is the commercial result. He named: Pending, Lost.

His reason: "you can have multiple appointments for one proposal, and you can
have one appointment with multiple proposals that come from it." So a proposal
is a record of its own, and appointments and proposals are linked many to
many.

What this changes in the record:

- [product-architecture.md](product-architecture.md) 5.6 lists "Won / Pending /
  Lost" as a field of the after-action report, which is filled in for an
  appointment. Under this decision those three belong to each proposal pitched,
  and the report says what happened at the appointment and then gives an
  outcome for each proposal. 5.6 is to be reworded when the after-action
  report is planned.
- Rule 14 of the slice 5 rulebook ("the salesperson owns a Pending") is about a
  proposal, not an appointment and not a Lead.
- Slice 6 already records two of the appointment outcomes he named, as part of
  the appointment: a cancellation (with who and why) and a reschedule (with the
  earlier times). A no-show is not recorded yet.

**To confirm with Jacob when the after-action report is planned,** because his
lists did not name them: that an appointment can also simply have taken place,
and whether "took place but nothing was presented" is its own outcome (the
architecture's Demo Rate needs it); and that a proposal's outcomes are Won,
Pending and Lost. **Answered the same day by decision 123.**

## 122. Work is checked against the product architecture after every slice (2026-10-11)

**Decided by Jacob:** "moving forward... we're always sticking to the plan and
coming back there after each slice or section."

[product-architecture.md](product-architecture.md) is the plan. After a slice
is merged, and before the next one is proposed, the implementation agent goes
back to it and reports: what the plan describes in the area just built that
was not built; what was built that the plan does not describe; and which part
of the plan the next step comes from. The next step is proposed from the plan,
not invented. This is step 10 of the workflow in
[collaboration.md](collaboration.md).

The first check, after slice 6, is in the review handoff of 2026-10-11. It was
prompted by a mistake: the implementation agent said the after-action report
had no plan, when 5.6 specifies it.

## 123. An appointment that ran must have a result, and whether a proposal was presented is counted (2026-10-11)

**Decided by Jacob,** in answer to the three questions decision 121 left.

- **An appointment's status** is one of: cancelled, rescheduled, no-show
  (decision 121), or **run**. "Run" is his word: "appointment was run". He
  also offered "demoed" and "shown"; "run" is the one to use, because an
  appointment can be run without anything being shown.
- **"It took place" is not an outcome by itself.** An appointment that ran
  must be given a result: **Sold, Pending or Lost.** "Sold" is the word he
  uses for what the product architecture calls Won. **Corrected by decision
  124: the result is given to a proposal, never to the appointment, which
  simply shows as run.**
- **Whether a proposal was presented is its own fact.** "An appointment was
  run, but no proposal was built": nothing was pitched or demonstrated and no
  price was given. That is what **Demo Rate** is counted from: of the
  appointments that ran, how many had a proposal presented with a price.
- **A run appointment with no proposal still has a result,** and it is often
  Pending: the salesperson started and has to finish the proposal later.
- **Demo Rate is to be watched.** "One thing we need to keep an eye on is the
  demo rate."

What this means beside decision 121, to be settled in the after-action report's
plan and not assumed:

- Where Sold, Pending or Lost is kept when an appointment ran and no proposal
  exists yet. Decision 121 puts those results on a proposal; this decision
  says the appointment needs one even without a proposal.
- With several proposals from one appointment, whether each has its own result
  and what the appointment then shows.
- "Rescheduled" as a status: slice 6 keeps a rescheduled appointment as the
  same appointment with its earlier times in its history. Whether a report
  needs to count it apart is for that plan.

## 124. Only a proposal has a result; how Demo Rate and wins are counted (2026-10-11)

**Decided by Jacob,** answering the two points decision 123 left open. **It
corrects decision 123 in one place:** an appointment that ran is not itself
given Sold, Pending or Lost.

- **An appointment has no result.** "Appointments don't have one lost or
  pending attached to them. Only proposals have one lost or pending attached to
  them, so the appointment itself would show as run."
- **A result needs a proposal.** "You can't resolve a proposal that hasn't been
  created."
- **Demo Rate** is counted from appointments marked run that have no proposal.
  Such an appointment counts against the salesperson for as long as no
  proposal exists, and counts for them from the day one is created, even if
  that is a day later.
- **Wins are counted against appointments, not proposals.** A salesperson who
  wrote three proposals from one appointment and sold one is not marked down
  for the other two. Two proposals accepted from one appointment count as one
  win.

**Also from Jacob that day, about how the work is steered:** the implementation
agent is not to ask him whether a piece of work is next. It is to say, from
the plan, what is next and why (see decision 122). The first such answer is in
[slice-7-appointment-results-plan.md](slice-7-appointment-results-plan.md),
"Why this is next, from the plan".

**And about his messages:** he dictates them with Wispr Flow, and they reach
the implementation agent as pasted text. They are his own words and are
treated as such, an approval to merge included.

## 125. Slice 7 is approved in four steps, with its eleven answers (2026-10-12)

**Approved by Jacob** ("Follow your recommendation. All the rest follow your
recommendation."). Slice 7 is built from
[slice-7-appointment-results-plan.md](slice-7-appointment-results-plan.md) in
its four steps, each stopping for his review: 7a run or no-show; 7b proposals;
7c results and the after-action report; 7d the first Sales figures. **Step 7a
is approved to build.** 7b to 7d are approved in order.

His answers to the plan's eleven questions, each as recommended:

1. An appointment is marked run or no-show by the salesperson whose
   appointment it is, or a manager, from its start time.
2. A no-show is treated as a cancellation by the customer: the Lead stays with
   the salesperson for the waiting period, then goes to the Cancelled
   Appointments queue, where the card says it was a no-show. It still counts as
   issued.
3. A Lead whose appointment was run shows a new status, Appointment run, still
   with the salesperson.
4. A proposal counts for Demo Rate when it is saved with a name and a price and
   tied to that appointment.
5. A proposal cannot exist with no appointment, in this slice.
6. Sold records the sold amount and the day, and the Lead shows Sold. Creating
   the Project is a later slice.
7. A Lost reason comes from the Sales Loss Reasons list as it stands.
8. Objections are a new managed list that starts with "Other (see note)".
9. A Lead is Sold when one of its proposals is Sold, and Lost when the
   salesperson or a manager marks the Lead lost, which marks every proposal
   still Pending as Lost.
10. **Close Rate is counted for each Lead, not each appointment.** "Correct,
    it's per lead, not per appointment." A return visit that closes the sale
    does not lower the rate. Two proposals sold against one Lead count as one
    win, "unless it's booked again in the future for a second job". Read as: a
    second job is its own Lead for that customer, which Backoffice already
    makes with "New lead for this customer", and so is counted by itself. This
    settles how decision 124's "against the number of appointments" is
    counted.
11. The voice note and its transcript are not in this slice.

## 126. Close out first, a reminder after 15 minutes, and the salesperson is told (2026-10-12)

**Decided by Jacob,** in reply to step 7a's handoff, which listed as a known
limit that nothing made anyone answer. His words: "they can't start another
appointment until the last one was closed out. There should be a prompt and an
email that's sent out 15 minutes afterwards to remind them to close out the
appointment. We want to build the habit that they close it out immediately
afterwards in the car. Salesperson should get notified if a manager corrects
or touches their appointments."

The plan (5.6) said "Goal: 1–2 minutes from the car" and "Appointment remains
operationally incomplete until report is submitted". It did not say what
"incomplete" stops, or anything about a reminder. This decision does, and the
plan now carries it.

1. **Close out first.** Once an appointment's end time has passed and nobody
   has marked it run or no-show, nothing else can be done with its Lead: it
   cannot be booked again, sent back to the Contact Center, moved to another
   Location, returned to central or disqualified. Each says "Say what happened
   at this lead's last appointment first: run or no-show." This changes
   decision 113.
2. **In order.** A salesperson cannot mark a later appointment of theirs while
   an earlier one that is over has no answer. A manager answering for them is
   not held to this.
3. **A prompt.** A salesperson with an appointment that is over and unanswered
   sees a bar under the header on every page, with how many and a link to the
   oldest. It goes when they are answered.
4. **A reminder.** Fifteen minutes after an appointment's end time, if it still
   has no answer, its salesperson gets a notice in the app and an email. Once
   for each appointment. Neither says anything about the customer.
5. **The salesperson is told** in the app when a manager marks their
   appointment run or no-show, corrects that answer, or marks the appointment
   "booked in error". A new time, a move, a cancellation and a Lead sent back
   were already told (decisions 91 and 106).

Read by the implementation agent, for Jacob to change if wrong:

- **"Can't start another appointment."** Backoffice has no step where a
  salesperson starts an appointment, so this is built as items 1 and 2. The
  salesperson can still open their next appointment's Lead and see where to
  go.
- **The block begins at the end time,** not the start. During the appointment
  it can already be answered, and can still be rescheduled or cancelled.
- **The reminder is sent once.** After it, the prompt and the "To close out"
  list are what keep it in view.
- **A manager is not prompted or reminded** about other people's appointments.
  They see them under "To close out" in Sales > Calendar.

## 127. Money is kept in whole cents (2026-10-12)

**Made by the implementation agent while building step 7b,** the first time
Backoffice stores an amount of money. Jacob can change it.

An amount is stored as a whole number of cents (`price_cents`), never as a
decimal number of dollars. People type dollars ("8450", "8,450.50") and see
dollars ("$8,450", "$8,450.50"). One place reads and shows amounts:
`App\Support\Money`. An amount must be above zero and no more than ten million
dollars; anything else is refused, never rounded or guessed.

Why: adding up decimal dollars loses or gains a cent now and then, and Sold
Revenue, NSLI and commission will all be sums of these amounts. The sold
amount in step 7c is to be stored the same way.

## 128. A proposal can be corrected, or marked "added in error" (2026-10-12)

**Decided by Jacob** when he approved the merge of step 7b, which had built
proposals with no way to change one: "yes the proposal should be able to be
edited or removed or voided or archived. i like your ideas on it". The ideas
were the recommendation in that step's handoff, which is now the rule:

1. **Edited.** The salesperson the Lead is with, or a manager, can change a
   proposal's name and price while it is still Pending. Every change is kept
   in the audit record with what it was before.
2. **Removed, voided or archived** is one thing: a manager marks the proposal
   **"added in error"**, with a note. It stays on the record, shown as such,
   and from then on counts for nothing: not for Demo Rate, not for any result
   or figure.
3. **Nothing is ever deleted.**

To be built with step 7c, as recommended.

## 129. The objections list starts with seven (2026-10-13)

**Decided by Jacob** when he approved the merge of step 7c ("go ahead and
merge and use your suggestions"). Step 7c had built the Objections list with
one entry, "Other (see note)", which needs a typed note, so every Lost and
every Still pending needed typing. The list now starts with, in this order:

1. Price
2. Wants to think it over
3. Needs to talk to spouse or partner
4. Getting other bids
5. Timing
6. Financing
7. Other (see note), the only one that needs a note

This is a starting list until Sales Leadership gives theirs (plan, 24). Owner
and Leadership change it on Lead & Sales Lists like any other list.

## 130. Slice 8 is approved, with three answers that change its plan (2026-10-13)

**Approved by Jacob** ("Everything else looks great. Go ahead."). Slice 8 is
built from [slice-8-projects-plan.md](slice-8-projects-plan.md) in its two
steps, each stopping for his review: 8a the Project, 8b the handoff. **Step 8a
is approved to build.** The new permissions in the plan are approved with it.

His answers to the plan's ten questions. Three differ from the recommendation:

1. **One Project for each sold proposal, not one for the Lead.** "One lead can
   definitely have multiple projects going on. That could even be separate
   houses, things like that. Each proposal is connected to a project, not the
   other way around."
2. The Project is made automatically the moment a proposal is Sold. As
   recommended.
3. The title is "Main service — street address", filled in and editable. As
   recommended.
4. **When a manager corrects the sale, the Project is marked "Cancelled: sale
   corrected"** and kept, and brought back if the proposal is sold again. As
   recommended, "for now, but we may be adjusting it in the future".
5. **The handoff is questions the salesperson has to complete, not a notes
   box.** "We have had issues in the past with salespeople not having enough
   detail in the handover, and it creates issues for the project manager." The
   questions themselves are to be proposed with step 8a's handoff and agreed
   before step 8b is built.
6. No Project Coordinator role in this slice. As recommended.
7. A manager at the Location assigns the Production Manager, and a Production
   Manager there can take a waiting job. As recommended.
8. The new permissions, as proposed: `projects.view` for Owner, Leadership,
   Location Manager, Sales Consultant, Production Manager and Finance;
   `projects.manage` for Owner, Leadership and Location Manager.
9. Finance can see Projects. As recommended.
10. No message to the customer in this slice. As recommended.

Read by the implementation agent, for Jacob to change if wrong:

- **"Separate houses."** A proposal today takes its Lead's property, so a
  Project does too. Selling work on another of the customer's houses from the
  same Lead needs a choice of property on the proposal, which is not built.
  It is raised with step 8a's handoff.
- **The Lead still shows Sold once any of its proposals is sold** (decision
  125, answer 9). That is unchanged; each sold proposal now has a Project of
  its own beside it.

## 131. Stakeholder questionnaire reconciled into the plan (2026-10-13)

Jacob supplied *Banana Backoffice Stakeholder Discovery Questions.docx* and
asked that answered items adjust the plan and every remaining question be
listed with a recommendation. The document was treated as source material, not
as implementation instructions.

The answers now constrain the target architecture in
[product-architecture.md](product-architecture.md), including:

- employee-facing “gross margin” language; the current PM Production Bonus and
  Sales commission plans; payment timing and future-period cost adjustments;
- Project closeout, permanent color records, the final walkthrough, PM-owned AR
  timing and the deposit-paid handoff call;
- subcontractor compliance, scorecard visibility, VIP thresholds and current
  bonus rules;
- PFAD hours, scope/equipment limits, deposit, materials, pay and recovery;
- Sales confirmation/discovery, proposal validity and Pending ownership,
  Brand Ambassador tiers and the three current Sales compensation plans;
- invoice/change-order/tax/COGS/bad-debt rules that Finance already answered.

Nothing in this reconciliation authorizes implementation. Exact unanswered
items are deduplicated in
[stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md).
Questions are grouped by the work they can block so deferred Finance,
Commercial or W-2 work does not stop slice 8b. Commercial Sales remains
deliberately deferred.

## 132. Slice 8b handoff questions and property-per-proposal rule (2026-10-13)

**Decided by Jacob:** “I like your recommendations for both of those answers.”

1. The Sales-to-Production handoff requires structured answers for included
   scope, exclusions, promises beyond the proposal, requested timing, site
   access, colors, condition/prep risks, and customer priorities/expectations
   learned during WNBET discovery. It also has an optional final note.
2. Required fields may be answered explicitly with “None”. Production sees a
   waiting Project but cannot take it until the handoff is complete. The
   salesperson sees a persistent prompt and gets one bell/email reminder the
   next morning. The questions are fixed for the first version.
3. Every proposal selects one customer property, defaulting to the Lead's
   property. Its Project uses that property. One proposal cannot span multiple
   properties; each other property needs another proposal and Project.

This settles the product questions that were holding step 8b. It records the
requirements but does not by itself start implementation or merge step 8a.

## 133. Every remaining stakeholder-discovery recommendation is approved (2026-10-13)

**Decided by Jacob:** “Use all of your recommendations to answer the remaining
questions.” The 29 recommendations in
[stakeholder-discovery-open-questions.md](stakeholder-discovery-open-questions.md),
questions 3 through 31, are the answers exactly as written.

This settles, among other things:

- attribution after reassignment; no new no-demo exception; effective-dated
  Sales compensation, with no undefined “disqualifier” behavior;
- the PM job-cost materiality rule and rolling review window; the six-week
  Production-utilization calculation; subcontractor insurance deduction and
  configurable compliance; PFAD pickup/deposit authority;
- W-2 and warranty Performance Pay architecture without inventing a formula;
- the Backoffice/QuickBooks boundary, origin of accounting records, payment and
  AP sync, late-cost materiality, one operational completion month, accruals,
  refund/credit/write-off treatment, variable-compensation handoff, entity and
  dimension starting point, monthly close, controls and reporting; and
- Gross Receipts Tax as a liability, excluded from revenue and gross margin.

Some answers intentionally defer an exact value until Banana supplies evidence
or a technical integration check is performed. In particular: the W-2 P4P
formula is not invented; a QuickBooks product tier follows an integration
spike; actual legal-entity structure requires accountant confirmation; and
Commercial Sales remains deferred. Those are decided safeguards, not open
product questions.

This records policy only. It does not authorize implementation outside an
approved slice, merge step 8a, or start step 8b.

## 134. Slice 8 is merged; the fixes after it; Close Rate follows lead ownership (2026-10-13)

**Decided by Jacob,** in reply to step 8b's handoff: "merge it. make the fixes
- especially close rate - we work off lead ownership - so close rate is based
on who created the proposal and when it was accepted".

1. **Steps 8a and 8b are merged.** Slice 8 is complete.
2. **The fixes that handoff proposed are approved to build, as one step:**
   - a Project is named for what was sold, "Lake house deck — 9 Lake Rd", not
     for the Lead's main service. It stays editable. This changes answer 3 of
     decision 130.
   - the three places where Sales > Performance differed from decision 133:
     whose win it is in Close Rate, corrections, and a no-demo's reason.
3. **Close Rate works off lead ownership.** A win belongs to the salesperson
   who has the Lead and made the proposal, and it is counted in the month the
   proposal was accepted, which is the month it was marked Sold. This replaces
   step 7d's reading, which placed a Lead in the month of its first run
   appointment and gave the win to that appointment's salesperson.

Read by the implementation agent, for Jacob to change if wrong:

- **"Who created the proposal"** is read as the salesperson the Lead was with
  at the sale, because he says "we work off lead ownership" and decision 133
  says the win follows "the salesperson who owned the Lead when the proposal
  was sold". If a manager types a proposal in for a salesperson, the win is
  still the salesperson's.
- **What the wins are divided by** is unchanged: the Leads whose first run
  appointment was in the month (decision 125, answer 10). So a month's Close
  Rate is the Leads won that month over the Leads first seen that month. A
  sale that closes the month after its first visit is a win in the later
  month, and a strong month of late closes can read above 100%.
- **One Lead is one win** (decisions 124 and 125): it counts in the month its
  first proposal was accepted, and a second proposal sold later adds revenue,
  not a second win.
- **Corrections** (decision 133: "auditable metric adjustments rather than
  silently rewriting history") are built for money and wins and for Leads
  Issued: a sale corrected in a later month stays in the month it was made,
  and the correction is taken off the month it was corrected in, shown as
  such. The same for a Lead Issued that is later voided.
- **A no-demo's reason** is the salesperson's own words, asked when an
  appointment is reported as run with nothing presented. It still counts as a
  no-demo whatever the reason (decision 133).

## 135. The fixes are merged; Close Rate is demo to close (2026-10-13)

**Decided by Jacob,** in reply to the fixes' handoff, which asked what Close
Rate is divided by: "merge it - close rate is how many proposals were accepted
by how many were created - the only adjustment we're making from there is one
project is only caluclated. so if 3 proposals were created for some reason for
one project and all 3 were accepted, then it counts once. if only 1 of the 3
was accepted, then it still counts as a single win. otherwise sales reps can
game it by creating multiple proposals for one project and raising their
average close rate artificially. we sometimes do make multiple proposals for a
project. closed projects based on the lead would crate a lead to close rate,
we're looking for a demo to close rate. so if we demo'd at all by if demo had
any sales at all. if that makes sense. then we have appmt to demo rate, then
we have lead to appmt booking rate."

1. **The fixes after slice 8 are merged.**
2. **Close Rate is demo to close.** Of the jobs that were demoed at all, the
   ones that had any sale at all. A job that was run with nothing presented is
   not in it; that is what Demo Rate measures. This replaces what decision 134
   recorded the wins as divided by, the Leads first run in the month.
3. **Several proposals for one job count once,** in what was demoed and in
   what was won, so that making more proposals cannot raise the rate. Three
   proposals with all three accepted is one win; one of three accepted is one
   win.
4. **The funnel is three rates:** lead to appointment (booking), appointment
   to demo (Demo Rate), demo to close (Close Rate).

Read by the implementation agent, for Jacob to change if wrong:

- **"One project" is one Lead.** Backoffice has nothing between a Lead and its
  proposals that groups proposals into a job, and decision 125 (answer 10)
  says a second job for the same customer is its own Lead. So proposals for
  two houses under one Lead count as one demo and at most one win.
- **A Lead is demoed in the month of its first run appointment that has a
  proposal,** for the salesperson that appointment is with. Each Lead once,
  ever: a return visit does not count it again.
- **The win did not change** (decision 134): it counts in the month the
  proposal was accepted, for the salesperson who had the Lead then, and a
  correction comes off the month it is made in. So a month's Close Rate is
  the Leads won that month over the Leads first demoed that month, and a
  month of sales closed from earlier demos can read above 100%.
- **Lead to appointment booking rate is not on Sales > Performance.** The
  plan keeps booking rates with Marketing and Contact Center, by channel (4.1,
  4.6), and they are not built. Nothing is built for it in this step.

## 136. Close Rate as demo to close is merged; continue from the plan (2026-10-13)

**Decided by Jacob,** in reply to the handoff of the Close Rate change: "yes
merge and continue".

1. **Close Rate as demo to close is merged.**
2. **The readings in decision 135 stay as built.** He was asked about two of
   them and did not change them: proposals for two houses under one Lead are
   one demo and at most one win, and the lead to appointment booking rate
   stays with the Contact Center's figures, not built.
3. **"Continue" is read as: go on from the plan.** What the plan has next is
   Pending ownership and Rehash (product-architecture.md, 5.5; decision 131).
   Its plan is written for his approval before anything is built
   (decision 122).

## 137. Slice 9, Pending ownership and Rehash, is approved as recommended (2026-10-13)

**Decided by Jacob,** in reply to the slice 9 plan
([slice-9-pending-rehash-plan.md](slice-9-pending-rehash-plan.md)) and its
nine questions: "I agree with all of that. Go ahead."

1. **Slice 9 is approved in three steps,** 9a the clock, 9b Rehash, 9c the
   Pending figures, each stopping for his review.
2. **All nine answers are as recommended:**
   1. The seven days start the day the proposal was last presented.
   2. A Lead with several pending proposals stays with its salesperson until
      the last of them expires.
   3. A salesperson may move "valid until" up to 30 days ahead on their own; a
      manager to any day.
   4. A booked return visit keeps the Lead with the salesperson past the
      expiry. If it is cancelled or a no-show, the cancelled rules take over.
   5. A Lead run with nothing presented gets the same number of days from the
      appointment, then goes to Rehash too.
   6. When the customer tells the call center no, the setter marks the Lead
      lost with a Sales loss reason and the objection.
   7. A rehash booked by the call center is a new Lead Issued, as a cancelled
      Lead that is rebooked is today.
   8. The three figures of step 9c are as the plan's table.
   9. One bell notice two days before release, no email.

Read by the implementation agent while building step 9a, for Jacob to change
if wrong:

- **A proposal typed in late** is valid until today at the earliest, so it is
  never expired the moment it is saved.
- **A proposal that a correction makes pending again,** or that comes back
  when a lost Lead is undone, starts its days again from that day.
- **Presenting a proposal again never shortens its time.**
- **A day cannot be moved to before today.**
- **A new number of days in the settings** applies to proposals presented from
  then on. One that is already pending keeps its day.

## 138. Step 9a is merged; step 9b starts (2026-10-13)

**Decided by Jacob,** in reply to step 9a's handoff: "Merge and start. Let's
keep this train rolling."

1. **Step 9a, the clock on a pending proposal, is merged.** The readings in
   decision 137 stay as built; he did not change them.
2. **Step 9b, Rehash, starts.** It is built as the plan and decision 137 have
   it and stops for his review.

## 139. Step 9b is merged; step 9c starts (2026-10-13)

**Decided by Jacob,** in reply to step 9b's handoff: "Merge and start."

1. **Step 9b, Rehash, is merged.** The readings listed in its handoff and in
   the plan under "Step 9b as built" stay as built; he did not change them.
   Among them: a Lead is never released sooner than seven days after it last
   became Appointment run; with nothing presented the days count from when
   the appointment was marked run; the call center and a manager, not the
   salesperson it was released from, mark a Lead lost from Rehash; and only
   the timer sends a Lead to Rehash.
2. **Step 9c, the Pending figures, starts.** It is built as the plan's table
   and decision 137 (answer 8) have it and stops for his review.

## 140. The repository is public, for now (2026-10-13)

**Decided and done by Jacob.** On 2026-10-13 GitHub stopped running the two
checks: the month's free allowance of minutes for a private repository was
used up, by about 143 runs of roughly 14 billed minutes each. He was told what
a public repository exposes, that it cannot be fully undone, and that the
implementation agent recommended paying a small amount instead. His words, in
order: "we'll move ot public"; "thats fine - no one will ever know its there";
"can i move it back to private after we're done"; and, having changed it
himself, "okaY ITS PUBLIC - CONTINUE".

1. **The repository is public.** GitHub runs the checks on a public repository
   without the monthly limit, so the rule in
   [collaboration.md](collaboration.md) stands as written: both jobs, SQLite
   and MySQL 8.4, are confirmed on every pushed step.
2. **It can go back to private,** and he asked about doing so "after we're
   done". When is not decided. The natural time is before production holds
   real customer data. Whatever was copied while it was public stays copied.
3. **What is public as a result,** recorded so that it is treated as known to
   outsiders: all the code and its history; the plan and every decision,
   including pay, commission and margin rules and his words quoted in them;
   [security-notes.md](security-notes.md) with its open items; the address of
   the staging site; and the email address on the commits.
4. **Checked before it was changed:** all 187 commits on every branch held no
   password, key or token, no customer information, no database dump and no
   `.env` file. The accounts with a known password are made only on a
   development machine.
5. **While it is public, every commit is published the moment it is pushed.**
   The standing rules do not change, and there is now no margin for a
   mistake: no credential, token, key or customer information in any tracked
   file, and `docs/reference/` is never committed.

## 141. Step 9c is merged; slice 10, pre-production, is approved (2026-10-13)

**Decided by Jacob,** in reply to step 9c's handoff and the slice 10 plan
([slice-10-pre-production-plan.md](slice-10-pre-production-plan.md)): "Merge
and approve. For the welcome call, yes, it's mainly just a checklist item.
We'll have a separate SOP to do that, so that's just a checklist item for
those things. Yes, a workplace should flag a job that no one has called. After
2 business days, it gets flagged for falling behind."

1. **Step 9c, the Pending figures, is merged.** Slice 9 is complete. The
   readings in its handoff stay as built; he did not change them.
2. **Slice 10 is approved in three steps,** 10a onboarding and the rough
   timeframe, 10b the Color Specification, 10c readiness, each stopping for
   his review.
3. **The welcome call is a checklist item** (question 1). How the call is
   made is a procedure the business keeps outside Backoffice. Backoffice
   records that it was done.
4. **A job nobody has called is flagged as falling behind after two business
   days** in pre-production (question 3), in Production > Workspace.
5. **The other seven questions are as recommended:** a rough timeframe is an
   earliest and a latest start; the Production Manager or a manager writes the
   colors and records the customer's approval; changed colors are a new
   version needing approval again; sheen from a short list and the rest typed
   in; the salesperson reads colors and does not write them; colors are not
   required for "ready to schedule"; and no message goes to the customer.

Read by the implementation agent, for Jacob to change if wrong:

- **"A checklist item" is one tick, "Welcome call made",** with the day it was
  made and an optional note. Who they spoke to is not asked for. It can be
  unticked by the same people if it was ticked by mistake.
- **Business days are Monday to Friday,** in the Project's Location's time,
  counted from when the Production Manager took the job or was assigned it.
  Public holidays are not known to Backoffice and count as working days.
- **"Falling behind" is shown, not sent:** a mark on the job in the Workspace
  for its Production Manager and for a manager. No bell notice and no email,
  as the plan's question 3 proposed.

## 142. Step 10a is merged; step 10b starts (2026-10-13)

**Decided by Jacob,** in reply to step 10a's handoff: "Merge and start."

1. **Step 10a, the welcome call and the rough timeframe, is merged.** The
   readings in decision 141 and in the plan under "Step 10a as built" stay as
   built; he did not change them. Among them: the tick asks for the day and an
   optional note; business days are Monday to Friday and a public holiday
   counts as one; the two days run from when the present Production Manager
   got the job; and "falling behind" is a mark, not a message.
2. **Step 10b, the Color Specification, starts.** It is built as the plan and
   decision 141 have it and stops for his review.

## 143. Step 10b is merged; step 10c starts (2026-10-13)

**Decided by Jacob,** in reply to step 10b's handoff: "merge and start".

1. **Step 10b, the Color Specification, is merged.** The readings in its
   handoff and in the plan under "Step 10b as built" stay as built; he did
   not change them. Among them: manufacturer and sheen are needed on every
   line; any change to approved colors needs approval again; an approval
   recorded by mistake is put right by a new version; and "with the customer
   for approval" is optional.
2. **Step 10c, readiness, starts.** It is built as the plan and decision 141
   (question 8) have it and stops for his review. It is the last step of
   slice 10.

## 144. Step 10c is merged and slice 10 is complete; slice 11 is approved (2026-10-14)

**Decided by Jacob,** in reply to step 10c's handoff: "merge and continue".
Asked what "continue" meant, he chose "Yes, all recommendations": start
slice 11 as proposed, with the recommendation on all ten questions.

1. **Step 10c, readiness, is merged, and slice 10 is complete.** The readings
   in its handoff and in the plan under "Step 10c as built" stay as built; he
   did not change them: the checklist shows from the sale on; time in
   pre-production is shown to everyone who sees the list; nobody is notified
   when a job becomes ready.
2. **Slice 11, Work Packages and the exact schedule, is approved** in three
   steps ([slice-11-schedule-plan.md](slice-11-schedule-plan.md)), each
   stopping for his review. The ten answers are the recommendations:
   1. Dates belong to a Work Package. Every job starts with one package for
      the whole job; a large job can be split.
   2. A Work Package holds a name, the scope in words, expected working days
      and the smallest crew. Payout waits for subcontractors.
   3. A window is a first and a last working day, both chosen.
   4. Windows are proposed only on a job that is ready to schedule.
   5. Colors need not be approved before dates are confirmed. They will be
      required before a work order goes to a crew.
   6. Nothing about crews in this slice. The "crew-selected window" state
      waits for subcontractors; the Production Manager confirms with the
      customer directly.
   7. Rescheduling: the Production Manager or a manager, with a reason every
      time; the old dates kept; the salesperson told in Backoffice.
   8. A ready job with no confirmed dates is flagged "needs dates soon" when
      its rough timeframe starts in less than three weeks.
   9. The Schedule page is a plain list by week.
   10. No message to the customer.
3. **Step 11a, Work Packages and proposed windows, starts.** It stops for his
   review.

## 145. Step 11a is merged; step 11b starts (2026-10-14)

**Decided by Jacob,** in reply to step 11a's handoff: "go".

1. **Step 11a, Work Packages and the dates offered to the customer, is
   merged.** The readings in its handoff and in the plan under "Step 11a as
   built" stay as built; he did not change them: withdrawn dates are in the
   customer's Activity as well as offered ones; a window shorter than the
   work's days is allowed with a warning; nothing is written on a job that is
   not ready, packages included.
2. **Step 11b, the confirmed schedule, starts:** the customer's confirmation
   of one window, the job shown as Scheduled, and rescheduling with a reason
   and its history. It is built as the plan and decision 144 (questions 5 to
   7) have it and stops for his review.

## 146. Step 11b is merged; step 11c starts (2026-10-14)

**Decided by Jacob,** in reply to step 11b's handoff: "Merge and continue."

1. **Step 11b, the confirmed schedule, is merged.** The readings in its
   handoff and in the plan under "Step 11b as built" stay as built; he did
   not change them: a reschedule records who agreed to the new dates; the
   salesperson is told only when the whole job is scheduled; confirmed dates
   cannot be taken away without new ones.
2. **Step 11c, Production > Schedule and "needs dates soon", starts.** It is
   built as the plan and decision 144 (questions 8 and 9) have it and stops
   for his review. It is the last step of slice 11.

## 147. Step 11c is merged and slice 11 is complete; a plan for subcontractors comes next (2026-10-14)

**Decided by Jacob,** in reply to step 11c's handoff, which recommended
subcontractors as next: "merge and continue".

1. **Step 11c, Production > Schedule and "needs dates soon", is merged, and
   slice 11 is complete.** The readings in its handoff and in the plan under
   "Step 11c as built" stay as built; he did not change them: confirmed parts
   of a split job show on the Schedule before the whole job is Scheduled;
   "needs dates soon" is a mark, and nobody is notified.
2. **Next is a proposed plan for subcontractors** (product-architecture.md,
   section 7), starting with the roster and onboarding, as the handoff said.
   It is written for his approval before anything is built.

## 148. Slice 12, subcontractors, is approved; step 12a starts (2026-10-14)

**Decided by Jacob,** after reading the ten questions and their
recommendations: "I like them all. Let's run."

1. **The plan is approved** in two steps
   ([slice-12-subcontractors-plan.md](slice-12-subcontractors-plan.md)), each
   stopping for his review. The ten answers are the recommendations:
   1. A subcontractor works at one or more Locations, chosen when they are
      added.
   2. One "where they stand" list: Prospect, Screened, Interview scheduled,
      Interviewed, Approved, Onboarding sent, Active, Benched, Offboarded, and
      Not hired. VIP is a separate mark. "Pending compliance" is worked out
      from the checklist, never set.
   3. Business name and contact person are required, with a phone or an
      email; crew size and services can come later; home base is a town and
      ZIP code, not a full address.
   4. The Production Manager or a manager benches, unbenches and offboards,
      with a reason every time, as for VIP.
   5. Someone not yet compliant can be Active, shown as "Pending compliance",
      until work can be given out.
   6. No document files in this slice: the days only.
   7. The New Mexico waiver certificate is an extra requirement on the
      Locations in New Mexico.
   8. "Expiring soon" is marked 30 days before; no email.
   9. Only Production and managers see subcontractors, through two new
      permissions.
   10. No message to subcontractors.
2. **Step 12a, the roster and recruiting, starts.** It stops for his review.

## 149. Step 12a is merged; step 12b starts (2026-10-14)

**Decided by Jacob,** in reply to step 12a's handoff, which asked whether to
merge it and start step 12b: "continue".

1. **Step 12a, the roster and recruiting, is merged,** with the approved
   slice 12 plan. The readings in its handoff and in the plan under "Step
   12a as built" stay as built; he did not change them: any standing can
   follow any other; the ordinary recruiting steps take an optional note; a
   service taken off Sales' list stays on a subcontractor who has it.
2. **Step 12b, compliance, starts.** It is built as the plan and decision
   148 (questions 5 to 8) have it and stops for his review. It is the last
   step of slice 12.

## 150. Step 12b is merged and slice 12 is complete; a plan for giving work comes next (2026-10-14)

**Decided by Jacob,** in reply to step 12b's handoff, which recommended a
plan for giving work to subcontractors and said that "continue" would mean
merging and writing it: "continue".

1. **Step 12b, compliance, is merged, and slice 12 is complete.** The
   readings in its handoff and in the plan under "Step 12b as built" stay as
   built; he did not change them: no Location has the New Mexico waiver
   until someone adds it; "Pending compliance" shows only for subcontractors
   on the roster; a correction is a new record.
2. **Next is a proposed plan for giving work to subcontractors**
   (product-architecture.md, 6.6 and 7.4 to 7.8), inside Backoffice and
   without a portal, written for his approval before anything is built.

## 151. Slice 13, giving work to a subcontractor, is approved; step 13a starts (2026-10-14)

**Decided by Jacob,** in reply to the proposed plan and its ten questions
with recommendations: "approved - continue".

1. **The plan is approved** in three steps
   ([slice-13-giving-work-plan.md](slice-13-giving-work-plan.md)), each
   stopping for his review, with the recommendation on all ten questions:
   1. A new permission, held by Production Managers, Location Managers,
      Leadership, the Owner and Finance, shows payouts. Sales and the
      Contact Center never see them.
   2. A payout changes freely until the work is awarded; after that, with a
      reason, and the subcontractor's acceptance is recorded again. Every
      amount is kept.
   3. Work is given only to Active, compliant subcontractors at the job's
      Location; a crew smaller than needed is a warning.
   4. The subcontractor picks one of the windows offered to the customer;
      the Production Manager then confirms with the customer; if the dates
      are already confirmed, the award is for them.
   5. Acceptance is recorded with who, how and the day.
   6. Taking back an award needs a reason and whose doing it was; only "the
      subcontractor withdrew" counts against them later.
   7. One company insurance-deduction rate, 10% to start, changed in
      Settings with the day it takes effect; Location rates when needed.
   8. The work order is a page that prints; "sent" is recorded; approved
      colors are needed first.
   9. The job's address is on the work order once awarded, never before.
   10. No message to subcontractors or customers.
2. **Step 13a, payout, starts.** It stops for his review.

## 152. Step 13a is merged; step 13b starts (2026-10-14)

**Decided by Jacob,** in reply to step 13a's handoff, which asked whether to
merge it and start step 13b: "continue".

1. **Step 13a, payout, is merged,** with the approved slice 13 plan. The
   readings in its handoff and in the plan under "Step 13a as built" stay as
   built; he did not change them: a reason is optional until work can be
   awarded; another Production Manager at the Location sees a payout but
   cannot change it.
2. **Step 13b, the award, starts.** It is built as the plan and decision 151
   (questions 2 to 6) have it and stops for his review.

## 153. Step 13b is merged; step 13c starts (2026-10-14)

**Decided by Jacob,** in reply to step 13b's handoff, which asked whether to
merge it and start step 13c: "continue".

1. **Step 13b, the award, is merged.** The readings in its handoff and in
   the plan under "Step 13b as built" stay as built; he did not change them:
   rescheduling keeps the award with the same subcontractor; a payout change
   keeps the work with them, marked until they accept again.
2. **Step 13c, the work order, starts.** It is built as the plan and
   decision 151 (questions 7 to 9) have it and stops for his review. It is
   the last step of slice 13.

## 154. Step 13c is merged and slice 13 is complete; a plan for the work and closeout comes next (2026-10-15)

**Decided by Jacob,** in reply to step 13c's handoff, which recommended a
plan for the work itself and closeout and said that "continue" would mean
merging and writing it: "continue".

1. **Step 13c, the work order, is merged, and slice 13 is complete.** The
   readings in its handoff and in the plan under "Step 13c as built" stay as
   built; he did not change them: one deduction per work order; the rate in
   force on the work's first day; a new rate cannot start in the past.
2. **Next is a proposed plan for the work itself and closeout**
   (product-architecture.md, 6.8, 7.9 and 7.10), written for his approval
   before anything is built.

## 155. Continue through the remaining slices overnight, without stopping to merge (2026-10-15)

**Decided by Jacob,** while the slice 14 plan was being written: "continue
all the way through the remaining slices - im going to sleep i expect this
to be ready to merge when im up. do not stop to merge betweeen each slice,
just continue through. we will fix issues in the morning."

How the implementation agent reads it, for Jacob to correct in the morning:

1. **Slice 14 is built as proposed,** with the recommendation on each of its
   ten questions taken as the answer
   ([slice-14-work-and-closeout-plan.md](slice-14-work-and-closeout-plan.md)).
   Any later slice is planned from the product architecture and built the
   same way, its recommendations taken as the answers and listed for his
   review.
2. **Nothing is merged into `main`, and nothing changes on staging,** until
   he says so. The work stays on one branch, each step its own commit,
   tested and pushed, with both GitHub jobs confirmed.
3. **Left alone overnight:** anything that needs his accounts or a paid
   service (document storage, QuickBooks), sends anything to a customer or
   subcontractor, or lets anyone outside Banana sign in.

## 156. File storage is Wasabi, not a DigitalOcean Space (2026-10-15)

**Decided by Jacob,** who already has a Wasabi subscription: "I have a
Wasabi subscription. Would that work instead of the Digital Ocean service?"
and, after hearing what it means, "let's get the bucket set up". He chose
two buckets over one shared bucket: "I'll make two buckets."

1. **Decision 33 stands, with Wasabi in place of a DigitalOcean Space.**
   Wasabi is S3-compatible, so Laravel's standard S3 driver applies.
2. **Jacob set it up himself,** adding only new things to his Wasabi
   account and changing nothing that was there:
   - two private buckets in `us-east-1`: `backoffice-staging` for staging
     and `banana-backoffice` for production;
   - versioning on; Object Lock, replication and logging off;
   - two account-level policies, `backoffice-staging-files` and
     `backoffice-production-files`, each allowing list, read, write and
     delete on its own bucket only;
   - two users with API access only, `backoffice-staging` and
     `backoffice-production`, each with its own policy and nothing else.
3. **The keys are in Jacob's password manager.** They are never pasted into
   a chat, a document or the repository. The staging keys go into Forge's
   environment by Jacob once the code that uses them is merged; the
   production keys wait for production.
4. **Nothing uses the storage yet.** The plan for using it is
   [slice-16-document-storage-plan.md](slice-16-document-storage-plan.md),
   proposed and not approved.

## 157. Slices 14 and 15 are merged; slice 16 is approved; step 16a starts (2026-10-15)

**Decided by Jacob,** in reply to the status of the overnight work and the
slice 16 plan: "looks good". Asked what it covered, he chose both "Merge
slices 14 and 15" and "Approve storage plan".

1. **Slices 14 and 15 are merged.** The decisions taken overnight under
   decision 155, and the readings in each plan's "as built" sections, stay
   as built; he did not change them.
2. **Slice 16, document storage, is approved** in three steps
   ([slice-16-document-storage-plan.md](slice-16-document-storage-plan.md)),
   each stopping for his review, with the recommendation on all ten
   questions. Among them: the package `league/flysystem-aws-s3-v3` may be
   added; the W-9 file is not stored; PDF, JPEG, PNG and HEIC up to 20 MB;
   no virus scanning for now; links last five minutes; removed files are
   purged after 30 days and their earlier versions after a further 30.
3. **Step 16a, the storage itself, starts.** It stops for his review.

## 158. Step 16a is merged; the bucket deletes earlier versions (2026-10-15)

**Decided by Jacob,** in reply to the step 16a report, which recommended
both: "merge and then tell me what to do".

1. **Step 16a is merged** as built
   ([slice-16-document-storage-plan.md](slice-16-document-storage-plan.md),
   "Step 16a as built").
2. **Earlier versions are deleted by the bucket, not the application.**
   This changes decision 157's answer to question 10 in how, not in what:
   a removed file is purged by the application after 30 days, and its
   earlier version by a lifecycle rule Jacob sets on each Backoffice bucket
   in Wasabi ("permanently delete noncurrent versions", 30 days). The
   application's key never gets permission over earlier versions, so it
   cannot destroy the backup.
3. **Next, by Jacob:** the staging key and the `FILES_*` settings in Forge,
   and the lifecycle rule on `backoffice-staging`. Then the restore drill is
   run on staging, with his approval, before step 16b stores a real file.
   Step 16b waits for his word.

## 159. Staging storage is ready; step 16b starts (2026-10-16)

**Decided by Jacob,** after the restore drill passed on staging: "Yes, I
did add the lifecycle rule to Wasabi. I think we're all good to go. Let me
know if we're not. Otherwise, continue."

1. **Staging's storage is set up:** the key and `FILES_*` settings are in
   Forge, the lifecycle rule (noncurrent versions deleted after 30 days) is
   on `backoffice-staging`, and the restore drill passed on 2026-10-15.
2. **Step 16b, compliance documents, starts**
   ([slice-16-document-storage-plan.md](slice-16-document-storage-plan.md)),
   with decision 157's answers: the W-9 file is not stored. It stops for
   his review.

## 160. Step 16b is merged; step 16c starts (2026-10-20)

**Decided by Jacob,** in reply to the step 16b report and the steps for
raising staging's upload limit: "continue", which in this project has meant
merge the step and go on (decisions 149, 150, 152 to 154).

1. **Step 16b, compliance documents, is merged** as built
   ([slice-16-document-storage-plan.md](slice-16-document-storage-plan.md),
   "Step 16b as built").
2. **Step 16c, photos of the work, starts.** It stops for his review.
3. **Staging's upload limit is still 2 MB** (read on 2026-10-20). Raising it
   to 20 MB is Jacob's, in Forge (the server's Runtime tab, PHP, max upload
   size): the agent's Forge key may only read, and was refused when Jacob
   asked it to make the change on 2026-10-16.

## 161. Step 16c is merged; slice 16 is complete (2026-10-21)

**Decided by Jacob,** after the step 16c report and the staging upload
limit: "continue", which in this project has meant merge the step and go on.

1. **Step 16c, photos of the work, is merged** as built
   ([slice-16-document-storage-plan.md](slice-16-document-storage-plan.md),
   "Step 16c as built"). Slice 16, document storage, is complete.
2. **Staging's upload limit is 20 MB,** raised by Jacob in Forge on
   2026-10-21 (decision 160, item 3). Raising it to 100 MB, so several
   large photos can be added at once, is recommended and his to do.
3. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 162. Slice 17, customer payments, is approved; step 17a starts (2026-10-22)

**Decided by Jacob,** in reply to the plan check after slice 16 and the
slice 17 plan: "Looks good, continue."

1. **Slice 17 is approved** in three steps
   ([slice-17-customer-payments-plan.md](slice-17-customer-payments-plan.md)),
   each stopping for his review, with the recommendation on all eight
   questions:
   - 17a, 17b, 17c, in that order;
   - invoice totals are recorded as QuickBooks shows them, with the invoice
     number; Backoffice suggests the deposit from the sold price before tax;
   - the Production Manager, a manager or Finance records invoices and
     payments; cash and check only with a receipt photo, and they count
     once Finance approves them (decision 133);
   - a deposit percent per Location, 50% by default; less, or none, only by
     a manager with a reason;
   - at day 7 the balance is marked late and "late fee applies"; the fee is
     added in QuickBooks, as part of the final invoice total;
   - Collections is Finance for now;
   - card and financing surcharges are part of the invoice total;
   - the deposit-paid call is a tick on the Project, due within 24 hours,
     with the Production Manager told by the bell.
2. **Step 17a, invoices and payments, starts.** It stops for his review.

## 163. Step 17a is merged; Finance approves cash and check from the bank (2026-10-22)

**Decided by Jacob,** in reply to the step 17a report, which asked whether
the person who recorded a cash or check payment should be barred from
approving it: "Correct. It gets approved by finance, the person who actually
has access to the bank account and can see that it actually came into the
bank account. Otherwise, go ahead and merge."

1. **Step 17a is merged** as built
   ([slice-17-customer-payments-plan.md](slice-17-customer-payments-plan.md),
   "Step 17a as built").
2. **What approving means:** Finance approves a cash or check payment once
   they see it arrived in the bank account, not only from the receipt. The
   approval asks exactly that.
3. **Someone other than who recorded it approves it,** as recommended; the
   Owner is the exception, as recommended and not changed by Jacob. Built in
   step 17b, which waits for his word.

## 164. Step 17b is merged; step 17c starts (2026-10-24)

**Decided by Jacob,** in reply to the step 17b report, which asked to merge
and whether the 24-hour call is the welcome call: "awesome - continue".

1. **Step 17b, the deposit, is merged** as built
   ([slice-17-customer-payments-plan.md](slice-17-customer-payments-plan.md),
   "Step 17b as built").
2. **The call within 24 hours of the deposit is the welcome call:** one
   call, one tick. Once the deposit is paid, the welcome call is due 24
   hours after it (or after the Production Manager got the job, if later).
   Jacob did not change the agent's reading, which stands.
3. **Step 17c, the final balance and who chases it, starts.** It stops for
   his review.

## 165. Step 17c is merged; slice 17 is complete (2026-10-24)

**Decided by Jacob,** in reply to the step 17c report: "continue", which in
this project has meant merge the step and go on.

1. **Step 17c is merged** as built
   ([slice-17-customer-payments-plan.md](slice-17-customer-payments-plan.md),
   "Step 17c as built"), with its two departures from the plan's wording:
   Receivables grouped by the collection timeline (days 7, 14, 30), and
   Receivables as Finance's page, managers seeing balances on each job and
   the Workspace. **Slice 17, customer payments, is complete.**
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 166. Slice 18, change orders, is approved; step 18a starts (2026-10-24)

**Decided by Jacob,** in reply to the plan check after slice 17 and the
slice 18 plan: "Follow your recommendations. Continue."

1. **Slice 18 is approved** in three steps
   ([slice-18-change-orders-plan.md](slice-18-change-orders-plan.md)), each
   stopping for his review, with the recommendation on all eight questions:
   - 18a, 18b, 18c, in that order;
   - a change order counts once the signed copy is attached, with who
     signed, how and the day;
   - only a manager lowers the price, with a reason;
   - the final invoice is suggested from the price sold plus every signed
     change order, less the deposit invoice, before tax;
   - change orders do not change the sale's Sales figures; they count
     toward the customer's lifetime value when that is built;
   - a change order records the working days it adds; dates move only
     through the existing reschedule or "More days";
   - every change of scope or payout once work is given out is a revision
     the subcontractor accepts, with the work order sent again;
   - the figure in 18c is "After subcontractor payouts", never "gross
     margin" until every cost is counted.
2. **Step 18a, customer change orders, starts.** It stops for his review.

## 167. Step 18a is merged; step 18b starts (2026-10-24)

**Decided by Jacob,** in reply to the step 18a report: "continue", which in
this project has meant merge the step and go on.

1. **Step 18a, customer change orders, is merged** as built
   ([slice-18-change-orders-plan.md](slice-18-change-orders-plan.md),
   "Step 18a as built").
2. **Step 18b, subcontractor work order revisions, starts.** It stops for
   his review.

## 168. Step 18b is merged; step 18c starts (2026-10-24)

**Decided by Jacob,** in reply to the step 18b report: "continue", which in
this project has meant merge the step and go on.

1. **Step 18b is merged** as built
   ([slice-18-change-orders-plan.md](slice-18-change-orders-plan.md),
   "Step 18b as built"), with its deviation: one acceptance by the
   subcontractor covers every revision made before it.
2. **Step 18c, what the job brings in after subcontractor payouts,
   starts.** It stops for his review.

## 169. Step 18c is merged; slice 18 is complete (2026-10-24)

**Decided by Jacob,** in reply to the step 18c report: "continue", which in
this project has meant merge the step and go on.

1. **Step 18c is merged** as built
   ([slice-18-change-orders-plan.md](slice-18-change-orders-plan.md),
   "Step 18c as built"). **Slice 18, change orders, is complete.**
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 170. Slice 19, job costing and the financial close, is approved and built straight through (2026-10-24)

**Decided by Jacob,** in reply to the plan check after slice 18 and the
slice 19 plan: "approved continue. go all the way through 19 without
stopping".

1. **Slice 19 is approved** in three steps
   ([slice-19-job-costing-plan.md](slice-19-job-costing-plan.md)), with the
   recommendation on all eight questions taken as the answer, as under
   decision 155:
   - 19a, 19b, 19c, in that order;
   - the kinds of cost: materials, equipment rental, permit, dump or
     disposal, W-2 labour, warranty or callback, other; subcontractor
     payouts from the Work Packages;
   - a cost can be recorded without a receipt, and is listed as missing one
     until it is attached;
   - the target gross margin is 55%, set per Location;
   - "Projected", "Provisional" and "Final" gross margin, each before
     allocations;
   - job costing is submitted once the customer has signed off;
   - accuracy strikes are counted automatically; the third in a rolling 90
     days tells the Location's managers and Leadership; nothing else
     happens automatically;
   - Finance closes a job once it is signed off, paid in full and its job
     costing approved, and can reopen it with a reason; Brand Ambassador
     obligations do not hold it.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 171. Slice 19 is merged; only materials and rentals need a purchase order (2026-10-24)

**Decided by Jacob,** in reply to the slice 19 report, which asked to merge
and to confirm one reading of decision 133: "sounds good. continue".

1. **Slice 19, job costing and the financial close, is merged** as built
   ([slice-19-job-costing-plan.md](slice-19-job-costing-plan.md), "Step 19a
   as built" to "Step 19c as built").
2. **Only materials and equipment rentals take a purchase order,** so only
   they are accuracy strikes for lacking one (decision 133, as read in
   "Step 19b as built").
3. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 172. Slice 20, variable compensation, is approved and built straight through (2026-10-24)

**Decided by Jacob,** in reply to the plan check after slice 19 and the
slice 20 plan: "approved continue. go all the way through 20 without
stopping".

1. **Slice 20 is approved** in three steps
   ([slice-20-compensation-plan.md](slice-20-compensation-plan.md)), with
   the recommendation on all eight questions taken as the answer, as under
   decisions 155 and 170:
   - 20a, 20b, 20c, in that order;
   - pay is on the final gross margin Finance approves, before allocations,
     until allocations are built;
   - the pay period is the calendar month;
   - each person sees their own pay; Finance and the Owner see everyone's,
     through a new permission, `compensation.manage`;
   - a cancelled job claws back an advance, in the next period, linked to
     the job;
   - the 2% and 6% plans earn the final commission only, with no advance;
   - the commission is for the salesperson recorded on the sale; a split is
     a manual adjustment;
   - the Production Bonus month is the month a job is financially closed,
     in the Location's time, for the job's Production Manager then.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 173. Slice 20's three open questions are answered; payroll IDs are added (2026-10-25)

**Decided by Jacob,** in reply to the slice 20 report.

1. **A month with no job closed does not hold the Production Bonus:** "if
   they produce no jobs that's their problem". The month before it is not
   paid, as built.
2. **An advance stands if a deposit payment is reversed afterwards:** the
   final commission or a clawback settles it, as built.
3. **Payroll IDs, now:** Finance or the Owner keeps each person's payroll
   ID on their pay page, one person to each, audited. Finance > Payroll
   shows it beside each person, or that it is missing, and the download
   gives it first on every line.

## 174. Slice 20 is merged (2026-10-25)

**Decided by Jacob,** in reply to the payroll ID report: "merge it and
continue".

1. **Slice 20, variable compensation, is merged** as built
   ([slice-20-compensation-plan.md](slice-20-compensation-plan.md), "Step 20a
   as built" to "Step 20c as built", with decision 173).
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 175. Slice 21, Brand Ambassador, is approved and built straight through (2026-10-25)

**Decided by Jacob,** in reply to the slice 21 plan: "q6: correct - anything
above floor is pure profit. 5: follow recommended. do the entire slice in
one go".

1. **Slice 21 is approved** in three steps
   ([slice-21-brand-ambassador-plan.md](slice-21-brand-ambassador-plan.md)),
   with the recommendation on all eight questions taken as the answer:
   - 21a, 21b, 21c, in that order;
   - the package is recorded with the sale; the salesperson corrects it
     until the handoff is complete, a manager afterwards, with why;
   - Custom is items from the list and a discount of up to 25%, with why,
     and the Location's managers are told;
   - the job's Production Manager or a manager there handles the
     obligations, and can say who physically did each;
   - the financial close waits until every obligation is handled
     (completed, partial, deferred or waived); a deferred one may stay open
     after the close;
   - **the budget comes from the floor price** for every job: (retail
     price + signed change orders) × 75% × (100% − the target margin).
     "Anything above floor is pure profit";
   - the largest discount, 25%, is kept on each job as sold;
   - the yard sign is one item: the day it went up, and a reminder when it
     is due to come down 30 days later.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 176. Slice 21 is merged (2026-10-25)

**Decided by Jacob,** in reply to the slice 21 report: "continue".

1. **Slice 21, Brand Ambassador, is merged** as built
   ([slice-21-brand-ambassador-plan.md](slice-21-brand-ambassador-plan.md),
   "Step 21a as built" to "Step 21c as built"), with the sale dialog
   starting on "No package".
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 177. Slice 22, Warranty, is approved and built straight through (2026-10-25)

**Decided by Jacob,** in reply to the slice 22 plan: "approved, follow
recommendations, do the entire slice in one go".

1. **Slice 22 is approved** in three steps
   ([slice-22-warranty-plan.md](slice-22-warranty-plan.md)), with the
   recommendation on all eight questions taken as the answer:
   - 22a, 22b, 22c, in that order;
   - anyone who can see a job opens a warranty case on it;
   - it belongs to the job's Production Manager while they are an active
     Production Manager at the Location; otherwise the Location's managers
     are told, and one of them gives it to a Production Manager or keeps it;
   - a manager approves the warranty budget, with why, before the visit is
     scheduled; $0 is allowed, and costs above it are shown in red;
   - warranty cost counts in the original job's lifetime gross margin, on
     the job and on Finance > Job Costing, and against that job's Production
     Manager's Production Bonus in the month it is recorded; sales
     commission does not change;
   - the visit shows on Production > Schedule on its day;
   - the case records which subcontractors did the original work, for
     their warranty-call rate later;
   - nothing is sent to the customer.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 178. Slice 22 is merged (2026-10-25)

**Decided by Jacob,** in reply to the slice 22 report: "continue".

1. **Slice 22, Warranty, is merged** as built
   ([slice-22-warranty-plan.md](slice-22-warranty-plan.md), "Step 22a as
   built" to "Step 22c as built").
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 179. Slice 23, Performance Boards, is approved and built straight through (2026-10-25)

**Decided by Jacob,** in reply to the slice 23 plan: "approved, follow
recommendations, do the entire slice in one go".

1. **Slice 23 is approved** in three steps
   ([slice-23-performance-boards-plan.md](slice-23-performance-boards-plan.md)),
   with the recommendation on all eight questions taken as the answer:
   - 23a, 23b, 23c, in that order;
   - "completed" means financially closed in the month, in the Location's
     time, at the final gross margin, as the Production Bonus counts;
   - a job is at risk when its work runs past its last day, it is over its
     budget, its final balance is past due, or it has an open warranty case;
   - Production and the Location's managers see each Production Manager's
     gross-margin dollars, margin and status (on track, at risk, below the
     floor), never the bonus amount;
   - job-costing timeliness is the days from the customer's signoff to
     submission, with signed-off jobs not submitted after 7 days listed;
   - cash collected is the payments that count, by the day received;
   - the leaderboard runs by calendar month, quarter and year in the
     company's time; a champion has the most sold revenue in a finished
     period, a tie shares it, and corrections count as on Sales >
     Performance;
   - everyone with Sales access sees the leaderboard, company-wide.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 180. Slice 23 is merged (2026-10-25)

**Decided by Jacob,** in reply to the slice 23 report: "continue".

1. **Slice 23, Performance Boards, is merged** as built
   ([slice-23-performance-boards-plan.md](slice-23-performance-boards-plan.md),
   "Step 23a as built" to "Step 23c as built"), with the leaderboard on its
   own page in the Sales menu.
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 181. Slice 24, Refunds, Credits and Write-offs, is approved and built straight through (2026-10-25)

**Decided by Jacob,** in reply to the slice 24 plan: "approved, follow
recommendations, do the entire slice in one go".

1. **Slice 24 is approved** in three steps
   ([slice-24-refunds-credits-plan.md](slice-24-refunds-credits-plan.md)),
   with the recommendation on all eight questions taken as the answer:
   - 24a, 24b, 24c, in that order;
   - Finance at the Location or a manager there approves credits and
     refunds, never whoever requested them;
   - a credit lowers what the customer owes and the job's revenue, so its
     budget, gross margin, commission and Production Bonus follow; it goes
     on the final invoice only;
   - a refund is never more than what was collected, is approved like a
     credit and recorded paid by Finance; it does not change revenue by
     itself;
   - a write-off is proposed by Finance once the balance is with
     Collections and approved by a Location manager, two different people;
   - bad debt stays below gross margin: the gross margin and the Production
     Bonus do not change; the job shows a lifetime figure after bad debt,
     and Finance > Performance shows the month's write-offs;
   - a write-off takes back the salesperson's plan rate on the written-off
     amount, in the open pay period, linked to the job;
   - requests wait on the job and on Finance > Receivables.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 182. Slice 24 is merged (2026-10-26)

**Decided by Jacob,** in reply to the slice 24 report: "continue".

1. **Slice 24, Refunds, Credits and Write-offs, is merged** as built
   ([slice-24-refunds-credits-plan.md](slice-24-refunds-credits-plan.md),
   "Steps 24a and 24b as built" and "Step 24c as built").
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 183. Slice 25 is approved and built straight through; ad-platform connections come last (2026-10-26)

**Decided by Jacob,** in reply to the slice 25 plan: "approved, follow
recommendations, do the entire slice in one go. also for the ad spend - lets
build the direct connections in last".

1. **Slice 25 is approved** in three steps
   ([slice-25-marketing-and-executive-boards-plan.md](slice-25-marketing-and-executive-boards-plan.md)),
   with the recommendation on all eight questions taken as the answer:
   - 25a, 25b, 25c, in that order;
   - spend is recorded against the Lead Source, by month and Location;
   - a new permission, `marketing.spend.manage`, for Owner, Leadership,
     Location Manager and Finance, records it; not the Contact Center;
   - leads count in the month received, appointments in the month issued,
     and sales and sold revenue in the month accepted, by the Lead's source,
     as Sales > Performance counts them;
   - return on spend is sold revenue ÷ spend, "$4.20 per $1";
   - each Location has a monthly sold-revenue target, effective from a
     month, set in Settings > Locations; the company's is their sum;
   - each Executive Board KPI has its own status: sold revenue on pace or
     behind its target so far, gross margin against the target margin, and
     late balances and jobs at risk as counts; no single health score;
   - Company Overview access sees the Executive Board.
2. **Direct connections to ad platforms** (Google, Meta and the like), which
   would bring spend in by themselves, **are built last,** after everything
   else in the plan. Until then spend is typed in.
3. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 184. Slice 25 is merged (2026-10-26)

**Decided by Jacob,** in reply to the slice 25 report: "continue".

1. **Slice 25, Marketing Spend, Marketing Performance and the Executive
   Board, is merged** as built
   ([slice-25-marketing-and-executive-boards-plan.md](slice-25-marketing-and-executive-boards-plan.md),
   "Step 25a as built" to "Step 25c as built"). Every Center now opens on a
   working board.
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 185. Slice 26, the Exception Engine and Needs Attention, is approved and built straight through (2026-10-26)

**Decided by Jacob,** in reply to the slice 26 plan: "approved, follow
recommendations, do the entire slice in one go".

1. **Slice 26 is approved** in three steps
   ([slice-26-needs-attention-plan.md](slice-26-needs-attention-plan.md)),
   with the recommendation on all eight questions taken as the answer:
   - 26a, 26b, 26c, in that order;
   - the twelve first rules, their owners and severities, as the plan's
     table has them;
   - exceptions are kept, swept every 15 minutes by the scheduler, and close
     by themselves when fixed;
   - each person sees their own, a manager every one at their Locations, the
     Owner and Leadership all of them, never one about a record the person
     may not open;
   - feedback is useful, not useful or dismiss, with an optional reason; a
     dismissed item leaves that person's list until it clears and comes
     back;
   - an Urgent item rings its owner's bell once; nothing is emailed or
     texted;
   - the morning briefing is on Home; no email;
   - no AI in this slice.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 186. Slice 26 is merged (2026-10-26)

**Decided by Jacob,** in reply to the slice 26 report: "continue".

1. **Slice 26, the Exception Engine and Needs Attention, is merged** as
   built ([slice-26-needs-attention-plan.md](slice-26-needs-attention-plan.md),
   "Step 26a as built" to "Step 26c as built"), including counting every
   standing appointment from today to four days out as one to confirm,
   until a confirmation call is recorded.
2. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 187. Slice 27 is approved and built straight through; QuickBooks will be connected (2026-10-26)

**Decided by Jacob,** in reply to the slice 27 plan: "We are going to
connect QuickBooks. In fact, we already have QuickBooks set up for the
location ... Yes, one owner per location, because that's going to be the
general manager. Other than that, approved and good to go. Go ahead and
complete the entire slice in one go."

1. **Slice 27 is approved** in three steps
   ([slice-27-leadership-scorecards-plan.md](slice-27-leadership-scorecards-plan.md)),
   with the recommendation on every question taken as the answer, and:
   - **question 3:** one owner per Location's scorecard, its general
     manager, chosen in Settings > Locations;
   - **question 2:** Net Profit % joins the scorecard once QuickBooks is
     connected. QuickBooks is already set up for the Location, and Jacob
     will connect it. The connection is its own slice, proposed after this
     one, because it needs Jacob to authorize Backoffice in QuickBooks
     himself; this slice builds the scorecard so a KPI can be added.
2. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 188. Slice 27 is merged (2026-10-26)

**Decided by Jacob,** in reply to the slice 27 report: "continue".

1. **Slice 27, Leadership Scorecards and TV Mode, is merged** as built
   ([slice-27-leadership-scorecards-plan.md](slice-27-leadership-scorecards-plan.md),
   "Step 27a as built" to "Step 27c as built"), including a scorecard's
   numbers being the month so far when it is submitted.
2. **Next:** connecting QuickBooks, proposed as its own slice (decision
   187), for his approval.

## 189. Slice 28 is approved; each Location has its own QuickBooks company (2026-10-26)

**Decided by Jacob,** in reply to the slice 28 plan: "banana's QB is 1
account for each location. each has a seperate login and payment setup and
everything. so 3 total accounts. and each new location will have an
account. continue".

1. **Slice 28 is approved** in three steps
   ([slice-28-quickbooks-plan.md](slice-28-quickbooks-plan.md)), with the
   recommendation taken as the answer on every question but the second.
2. **Question 2: one QuickBooks Online company per Location,** each with
   its own sign-in and payment setup; three today, and each new Location
   gets its own. Each Backoffice Location therefore connects to its own
   company, signed in with that company's login; one QuickBooks company
   cannot be connected to two Locations. The company's net profit adds up
   the Locations'.
3. **Question 4 follows the recommendation:** staging connects to Intuit
   sandbox companies with made-up numbers; Banana's real companies are
   connected only on production, once production is approved.
4. **Built without stopping between the steps,** on one branch, each step
   committed and checked on both GitHub jobs. Nothing is merged until
   Jacob reviews the slice.

## 190. Slice 28 is merged (2026-10-26)

**Decided by Jacob,** in reply to the slice 28 report, after creating the
Intuit app and putting its development keys into staging's settings
himself: "continue".

1. **Slice 28, the QuickBooks Connection and Net Profit, is merged** as
   built ([slice-28-quickbooks-plan.md](slice-28-quickbooks-plan.md),
   "Step 28a as built" to "Step 28c as built").
2. **One Intuit app serves every Location.** Each Location's company
   connects to it on its own, signed in with that company's login; a new
   Location needs no new app.
3. **Next:** the plan check after a slice, and a proposed next slice, for
   his approval.

## 191. Production readiness waits; slice 29 is Settings > Audit Log (2026-10-26)

**Decided by Jacob,** in reply to the proposed production-readiness slice:
"can we come back to this and start the next slice or whatever i wont be by
the computer".

1. **Production readiness waits until Jacob is back,** unchanged; it needs
   him for the server, the domain, payment and keys. Its plan becomes slice
   30 ([slice-30-production-readiness-plan.md](slice-30-production-readiness-plan.md)),
   still proposed and waiting for his answers.
2. **Slice 29 is Settings > Audit Log,** the alternative the plan check
   named, chosen by the implementation agent because it needs nothing from
   Jacob while he is away. It is built straight through with the
   recommendation on every question
   ([slice-29-audit-log-plan.md](slice-29-audit-log-plan.md)), committed
   and checked on both GitHub jobs step by step, and not merged until Jacob
   reviews it.

## Future decisions already constrained by the brief

- Estimates hold any number of named, ordered options. No fixed tiers.
- Accepted proposals are stored as versioned snapshots.
- Projects carry several narrow states (sales, production, payment), not one
  combined status.
- Customer-visible information is opted in field by field, never by default.
- Customer uploads use private storage.
