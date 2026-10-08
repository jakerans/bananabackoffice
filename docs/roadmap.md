# Roadmap

## Where we are

**Checkpoint A — Secure branded foundation — is approved and merged into `main`
(2026-10-04).**
**Current: slice 3 is built and awaiting owner review.** The B1–B7 record and
older unbuilt plan are in [checkpoint-b.md](checkpoint-b.md). Jacob approved the
five-slice order; slices 1 and 2 are merged, and the bounded contact-attempt
evidence foundation in slice 3 has been implemented for review. Each later
implementation slice still stops for owner approval and review.

**Steps B1 to B7 are approved and merged into `main` (2026-10-07).** B1 to B6
were deployed to staging on 2026-10-06; the automatic staging deployment caused
by the B7 merge was explicitly allowed and is verified separately. See
"Integration checkpoint" below. **B8 to B12 are not built.**

Slices 1 and 2 are completed and merged after B7. Slice 3 is built on its review
branch but is not merged. B8, Projects, production readiness, slices 4–5 and
other downstream implementation remain unauthorized. The older future steps
below remain historical planning context.

The former launch target of B1 to B9 and production-readiness timing around B7 or
B8 are historical planning assumptions. They remain useful context but are not
the current sequence; the reconciled roadmap must replace them before further
implementation or production preparation is authorized.

How each step is planned, reviewed and approved is in
[collaboration.md](collaboration.md). Whatever is waiting on the owner right now is
in [review/current.md](review/current.md).

| Checkpoint | Scope | State |
| --- | --- | --- |
| A | Secure branded foundation | Approved and merged (2026-10-04) |
| B | Leads, customers and projects: lead capture and attribution, routing, accounts, contacts, properties, qualification, projects, sales outcome, notes, private attachments, timeline | B1 to B7 approved and merged into `main` (2026-10-07). B8 to B12 not built. |
| C | Historical Color Studio checkpoint placeholder | Not started; target Color Studio is an external surface and sequencing is pending reconciliation |
| Later | Historical future-work placeholders | Pending replacement by the canonical architecture roadmap |

## Reconciled target and approved planning sequence

[product-architecture.md](product-architecture.md) is the authority for product
direction. Jacob approved this planning order on 2026-10-07. Each row requires its
own reviewed plan and explicit implementation approval; slices 1 and 2 have now
received that approval.

| Order | Proposed slice | Product status | Implementation status and boundary |
| --- | --- | --- | --- |
| 1 | Five-Center shell alignment | **LOCKED:** Marketing, Sales, Production, Finance and Company; Settings under Company; Color Studio external. | **Approved, built, verified and merged into `main` (2026-10-07).** The current-ID/current-route/access compatibility map is in [navigation-map.md](navigation-map.md). Shell/navigation only; no new workspace behavior or permission expansion. |
| 2 | Shared Customer Activity and unified Notes | **LOCKED:** shared records, context/provenance/history-preserving Notes, and Activity made from major business events rather than every audit event. | **Approved, built, verified and merged into `main` (2026-10-07).** Reuses existing customer, Lead note and curated audit evidence; every related Lead, note and event location remains permission-scoped, and no raw audit payload or sensitive finance field is rendered. |
| 3 | Contact-attempt evidence foundation | **LOCKED:** each attempt is durable evidence with actor, channel, time and outcome; suspicious patterns go to manager review, with no automated punishment. **PENDING:** provider choice. | **Approved, built and verified; integration into `main` authorized (2026-10-08).** Manual records are explicitly not provider-verified, preserve current actor/location/contact/lifecycle evidence, and cannot change lifecycle state. No provider selection, cadence enforcement or personnel policy is introduced. |
| 4 | Scheduled work and atomic due queue | **LOCKED:** due work is atomically claimed and manual Deferred Follow-up remains a distinct existing callback workflow. **CONFIGURABLE:** cadence. **PENDING:** scheduler, timezone, retry and failure contracts. | **Order approved; not planned or authorized.** Specify those pending contracts before implementation; do not reinterpret B7 follow-ups as automated nurture. |
| 5 | Appointment issuance and ownership handoff contract | **LOCKED:** appointment booking hands operational responsibility from Contact Center to Sales. **PENDING:** exact Project creation transition and Pending ownership/validity policy. | **Order approved; not planned or authorized.** Contract/design slice only; do not implement Projects or invent the pending rules. |

Canonical navigation also keeps Marketing Contact Center separate from Inbox,
uses one Sales Workspace instead of separate Estimate/Proposal modules, and one
Production Workspace instead of separate stage/project modules. Customers and
their related records remain shared across Centers. These locked directions are
not claims that the target UI exists.

### Completed slice 1 boundary

The completed implementation is recorded in
[navigation-map.md](navigation-map.md). It covers only navigation placement,
labels, retained route behavior and tests; it does not authorize a permission
change, workspace feature, B8 or a later slice. Slice 2 was separately reviewed
and approved before the work recorded below.

### Completed slice 2 boundary

The Customer Activity and Notes workspace unifies existing Lead-owned Notes and a
curated set of B1–B7 customer/Lead business events. It preserves note provenance
and removal history, reuses existing note actions and policies, and applies current
customer, related-Lead and event-location access. It adds no project, conversation,
proposal, payment, production or B8+ behavior.

### Slice 3 review boundary

The contact-attempt foundation stores append-only manual evidence on a Lead with
the responsible rep, impersonation provenance, channel, direction, actual event
time, factual answered/connection disposition, number, duration, current linked
customer/contact, recorded location and lifecycle snapshot. Manual rows can never
claim provider recordings or transcripts. Every write rechecks current Lead and
location authorization; history is filtered by the attempt's recorded location
and does not enter shared Customer Activity. Recording an attempt has no lifecycle,
assignment or follow-up side effect. Provider integrations, outbound communication,
dialers, queues, AI, cadence/nurture rules, management penalties and slice 4 are
outside this implementation.

## Starting point (verified 2026-10-04)

- Laravel 13.34, Livewire 4.4, Flux UI free 2.20, Fortify 1.40, Tailwind 4.3,
  Pest 5.3, PHP 8.4, SQLite locally. Staging and production use MySQL 8.4.
- Sign-in, password reset, email verification, two-factor and passkeys are installed.
- Public registration is already off: there is no `/register` route.
- The starter kit's 32 tests pass.
- The database has the starter tables only and no users.

## Checkpoint A plan

Status: **Approved, built and merged** (2026-10-04). Each step is one commit, named
A0 to A7, with its own tests. They were made on the `checkpoint-a` branch and are
preserved individually in `main`.

### What changed from the plan while building

- **Small screens.** The center menu scrolls sideways instead of collapsing into a
  slide-out menu. It is simpler and works without extra code.
- **Coming Soon in the center menu.** Corrected after the owner's review; see
  "Changes after the owner's visual review" below.
- **No third menu row yet.** No built section has sub-sections, so the sub-navigation
  row is not drawn. The registry already holds the sub-sections.
- **Settings has no single permission.** It opens for anyone who may manage
  locations or manage users, so no ninth permission was needed.
- **Invitation links have their own table** (`staff_invitation_tokens`) so they can
  never be used on the password reset form. See decisions.md, decision 11.
- **Company Overview and Settings do not depend on the selected location.** They
  are company-level and behave the same whichever location is selected.

### Changes after the owner's visual review (2026-10-04)

The owner reviewed the shell in a browser and asked for the navigation hierarchy to
be corrected. Authorization, locations, roles and route protection were not changed.

- **Center "Overview" pages removed.** A center has no page of its own. Choosing a
  center opens its primary section directly; see navigation-map.md.
- **Second row simplified.** It lists only the sections of the selected center. The
  "Overview" item and the standalone "Coming soon" item are gone.
- **"Coming soon" is a state of each section.** An unbuilt section is muted and not
  clickable; "Coming soon" shows on hover and is read out by screen readers.
- **Primary sections have a page even before they are built**, so that a center
  has somewhere to open. That page states it is not built and shows nothing else.
- **The header logo.** The owner asked for the real Banana Backoffice horizontal
  logo in place of the text wordmark. The files were supplied on 2026-10-04 and
  are now used, unaltered, for the header, the sign-in pages and the browser icons
  (see design-system.md, decisions 12 and 13).

### A0 — Housekeeping

- Start version control with `docs/reference` excluded.
- Name the app "Banana Backoffice".
- Remove starter-kit leftovers: the Laravel welcome page, the "Repository" and
  "Documentation" links, the placeholder dashboard.
- Remove the "delete my account" feature. Staff accounts are deactivated by an
  administrator, never self-deleted.
- Light theme only (see design-system.md).

### A1 — Brand and sign-in pages

- Add the brand colors and fonts as design tokens.
- Restyle sign-in, password reset, email verification and two-factor pages.
- Add a test proving registration stays unavailable.

### A2 — Locations, roles and access grants (data)

- New tables: `locations`, `access_grants`. New column: `users.is_active`.
- Roles and permissions defined in code.
- Factories and a local-only seeder with fictitious data.
- `php artisan backoffice:create-owner` to create the first owner safely.

### A3 — Authorization core

- The rules that answer "can this person do this, here?".
- The current-location resolver, which re-checks authorization on every request.
- Deactivated staff are blocked from signing in and from continuing a session.

### A4 — Application shell

- The central navigation definition.
- Header with brand, center switcher, authorized location switcher, user menu.
- Horizontal center navigation with Coming Soon items.
- Each center opens directly on its primary section (changed after review; originally a landing page per center).
- Friendly "no access assigned yet" page.

### A5 — Settings: Locations

- List, add, rename, deactivate locations.

### A6 — Settings: Users and Access

- List staff, invite a staff member by email, grant and revoke access, deactivate
  and reactivate.

### A7 — Wrap-up

- Update these documents to match what was built.
- Run the full test suite, static analysis and code style checks.

## What works at the end of Checkpoint A

1. Branded sign-in, sign-out, password reset, email verification, two-factor, passkeys.
2. Personal profile and security settings.
3. First-owner creation from the command line.
4. The application shell: header, center switcher, location switcher, navigation.
5. Every authorized center opens directly on its primary section, with that center's sections in the second row.
6. Settings: Locations.
7. Settings: Users and Access.
8. "No access assigned yet" and "not authorized" pages.

## What stays Coming Soon after Checkpoint A

Everything else in [navigation-map.md](navigation-map.md): every Performance Board,
Call Center, Campaigns, Social, Reviews, all Reporting, Appointments, Estimates,
Proposals, Relationships, Commissions, Projects, Crews / Subcontractors, Cash
Management, Job Costing, Payroll, Commission Approvals, all of Color Studio,
Customers, Inbox, and the remaining Settings pages.

## Tests in Checkpoint A

All of the following are covered by automated tests in `tests/`. See
[permission-model.md](permission-model.md) for the rules being tested.

**Authentication**
- The existing sign-in, password reset, verification, two-factor tests keep passing.
- `/register` does not exist, for both viewing and submitting.
- A deactivated staff member cannot sign in.
- A staff member deactivated while signed in is signed out on their next request.

**First owner**
- The command creates a verified owner with company-wide access.
- The command refuses to create a second owner unless explicitly told to.
- The command rejects weak passwords and duplicate emails.

**What a person can do, and where**
- A permission held at one location does not apply at another.
- A company-wide grant applies at every location.
- Two grants do not combine into something neither grants: sales at Location A plus
  call center company-wide does not produce sales at Location B.
- A call-center grant across all locations gives no finance or settings access.
- A person with no grants can do nothing and sees "no access assigned yet".
- Company-level permissions only count when the grant is company-wide.

**Location switcher**
- It lists only the person's authorized locations.
- "All Locations" appears only for people holding a company-wide grant.
- Posting another location's ID is refused and does not change the current location.
- Posting "all" without a company-wide grant is refused.
- With no stored location, the person lands on an authorized location, never "all".
- A stored location that is later revoked or deactivated is dropped on the next request.

**Shell and navigation**
- Guests are redirected to sign-in from every center page.
- Each center page returns "forbidden" to a person without that center's permission,
  even when they type the URL.
- Navigation shows built items as links, unbuilt items as muted "Coming soon", and
  hides unauthorized items.
- Coming Soon items are not links and have no route.
- Every navigation ID is unique, and every built item points at a real route.

**Settings**
- Only people with the settings permissions can open or use Locations and Users
  and Access, including through direct Livewire calls.
- Location names are validated and must be unique.
- A grant cannot be created for a location that does not exist or is inactive.
- A location-scoped grant requires a location; a company-wide grant must not have one.
- The last active owner cannot be deactivated or have their owner grant removed.
- An invitation creates the account without a usable password and sends a
  set-password email.

## Security review before merge (2026-10-04)

The branch was reviewed in full before merging. Eight findings were fixed on the
branch and the rest are recorded, with what each needs, in
[security-notes.md](security-notes.md). Two of the open ones had to be closed before
real staff use the system on a server: "forgot password" revealed which addresses
have accounts, and a deactivated person could complete a passkey or two-factor
sign-in before being signed out again. Both were fixed on 2026-10-04, before
Checkpoint B.

## Staging (deployed and operational, 2026-10-04)

Staging is live on Laravel Forge and deploys automatically whenever `main` is
pushed to GitHub.

| | |
| --- | --- |
| Address | `https://bananabackoffice-xg8eqczb.on-forge.com` |
| Forge | server "BackOffice", one site |
| Runs as | production mode, debug off |
| Database | MySQL 8.4 |
| Email | Resend, working (see below) |

Verified by the owner on staging:

- The Owner account was created with the application's own create-owner logic and
  a random password nobody saw.
- "Forgot password" delivered a real email through Resend.
- The owner set their own password from that email and signed in.

Email on staging:

- Sent through Resend from `backoffice@mail.bananapainting.com`, shown as
  "Banana Backoffice (Staging)" so staging email can be told apart from production.
- Reply-To is `admin@bananapainting.com`. This is an interim address the owner
  intends to change.
- The Resend API key was entered by the owner directly in Forge and exists only
  there.

History: the first deployment failed while applying a database change on MySQL
8.4. The cause and fix are in decisions.md, 21. The staging database was new and
empty, so no data was involved.

## Backups

### Database backups

State: **automated staging backups are configured and a successful backup has been
verified** (2026-10-05 UTC). A restore has deliberately not been run yet; see
"Restoring" below. Production is not set up.

Staging configuration:

| | |
| --- | --- |
| Method | Forge's built-in database backups |
| Forge | server "BackOffice", backup configuration "Banana Backoffice Staging - daily" |
| What | The `banana_backoffice` MySQL database only |
| Destination | DigitalOcean Spaces, in a private bucket used only for this: `banana-backoffice-staging`, through the Forge storage provider "Banana Backoffice Staging" |
| Frequency | Daily at 08:00 UTC |
| Retention | The 7 most recent backups |
| Failure notifications | Emailed to the owner |

The bucket and its access key were created by the owner in DigitalOcean and entered
directly in Forge. The key exists only there. See decisions.md, 23.

What was verified on the first backup:

- Forge reported it complete and not partial, at about 4 KB.
- A full dump of all 13 tables, measured on the server at the same time, compressed
  to almost exactly that size, so the backup is the whole database and not an
  empty file.
- The bucket refuses requests made without credentials.
- Not verified directly: the file sitting in the bucket. That needs the storage
  credentials, which were deliberately not used. Forge's own report is the evidence.

Production will get its own, stricter configuration when it is set up: more
frequent backups, longer retention, its own bucket and its own access key.

### Restoring

**Forge restores a backup directly over the database it was taken from.** It does
not let you choose a separate, isolated database to restore into. Pressing Restore
in Forge replaces the live database's contents with the backup's.

How this was established (2026-10-05): Forge's documentation says backups "will be
restored to the database they were created from"; and Forge's backup script writes
the database's own name into the backup file, so the file loads into that database
wherever it is run. Forge's stated route for restoring anywhere else is to download
the file from the storage bucket and load it by hand.

**For that reason Forge Restore was deliberately not run against the active staging
database at this stage.** No restore of any kind has been performed. The backups
are verified as taken, not as restorable.

**Required before production holds meaningful real data: a full Forge restore
drill.** It should be done deliberately, not in passing:

- at a time when staging can safely be overwritten, and rebuilt if the restore
  goes wrong;
- with a fresh backup taken immediately beforehand, so nothing is lost;
- following a written recovery procedure, which the drill should produce or
  correct: who does what, in what order, how to confirm the result, and what to do
  if the restore fails partway.

Known gaps to close for production:

- A restore goes back to the last backup and no further, because MySQL's change
  log is switched off. Point-in-time recovery would need it switched on.
- No written recovery procedure exists yet.

### Uploaded files are NOT covered

**A database backup does not include uploaded files.** It contains the database
only. Customer and project files (photos, documents, anything uploaded) live on the
server's disk or in file storage, and no database backup of any frequency will
bring them back.

Backoffice stores no uploaded files today. **Before it begins storing meaningful
uploaded files, it needs its own durable storage and backup strategy for them:**

- files kept in durable object storage, not only on the application server's disk;
- private by default, since they will include customers' homes and details;
- protected against accidental deletion and overwriting, for example with versioning;
- a defined way to restore them, tested, and kept in step with the database so a
  restored record still finds its files.

Checkpoint B introduces private attachments in step B10. The storage itself is
decided (a private Space separate from backups; see checkpoint-b.md,
"Attachments"). How those files are backed up and restored must still be settled
in B10, before the first real file is stored.

## Automated checks on GitHub

Every push to `main`, every push to a `checkpoint-` branch, and every pull request
runs two jobs:

| Job | Database | What it runs |
| --- | --- | --- |
| Checks and tests (SQLite) | SQLite, as in local development | Code style, static analysis, every test |
| Migrations and tests (MySQL 8.4) | MySQL 8.4, as on staging and production | Every migration on an empty database, then every test |

The MySQL job exists because MySQL enforces rules SQLite does not, which is how
the first staging deployment failed. Its database is created for the job and
destroyed afterwards. Its credentials are throwaway values written in the
workflow file; no staging or production credential is used or needed.

Not yet covered: staging runs PHP 8.5, while both jobs and local development use
PHP 8.4.

## Known limits of Checkpoint A

- Location names are compared exactly, so "Northside" and "northside" count as different.
- Changes to staff, access and locations are recorded as audit events from step B1
  onwards. Changes made before B1 have no history, and there is no screen for
  reading the events yet (step B11).
- Invitation emails are sent immediately rather than queued, so a slow mail server
  slows that one click.
- Application email is sent through Resend from `mail.bananapainting.com`
  (decisions.md, 22). Replies on staging go to `admin@bananapainting.com`, an
  interim address. The permanent reply-to inbox, and production's, are still to
  be named.
- Passkeys only work over a secure address. The local site is served over plain
  `http`, so passkeys cannot be tried locally until Herd's HTTPS option is turned on.

## Checkpoint B

Status: **B1 to B7 built, approved and merged.** The original plan, tables and
historical launch assumptions are in
[checkpoint-b.md](checkpoint-b.md). The permissions are in
[permission-model.md](permission-model.md), and the reasoning is in decisions.md,
25 to 37.

Checkpoint B is larger than the brief's original outline ("customers, properties,
projects, photos, notes, timeline"). The owner added the Lead as a record of its
own, marketing attribution, qualification and disqualification, and the sales
outcome, because Backoffice is to be the system of record from launch.

| Step | Delivers | State |
| --- | --- | --- |
| B0 | The plan and updated documents | Complete |
| B1 | Audit event recording, including Checkpoint A's staff and access changes | Built and approved (2026-10-05) |
| B2 | Attribution and managed lists: the Lead & Sales Lists page, with all five lists | Built and approved (2026-10-05) |
| B3 | Lead capture with attribution; the shared Leads page; Services and Project Size; address lookup; service areas | Built and approved (2026-10-05), including the live Mapbox lookup, which the owner tested in a browser. Both GitHub jobs passed on its final commit. |
| B4 | Lead routing and assignment: route, move, return to central; one assignee with history; the four Leads views; correcting the Received time; refusing to deactivate a Location with active Leads | Built and approved (2026-10-05), with the Received Time chronology rule the approval required. |
| — | **Owner impersonation** (not a Checkpoint B step; added at the owner's request to review each role's experience) | Built and approved (2026-10-05). See decision 46. |
| B5 | Lead notes: add, read and remove from view on the Lead page | Built and approved (2026-10-05). |
| B6a | Customers: the Account, Contact and Property records; who may see a customer; the Customers list and page (read only) | Built and approved (2026-10-06). |
| B6b | Managing Contacts and Properties, with address lookup; creating a customer directly | Built and approved (2026-10-06). |
| B6c | Resolving a Lead into a customer; duplicate suggestions, including masked matches; shared Contacts; "New lead for this customer"; archive and restore; editing a customer | Built and approved (2026-10-06). |
| — | **Integration checkpoint:** B1 to B6 merged into `main` and verified on staging | Complete (2026-10-06). See below. |
| B7 | Qualification milestone, legitimate disqualification and Deferred Follow-up; lifecycle history and concurrency; Follow-ups view | Reconciled, owner-approved and merged into `main` (2026-10-07), including review corrections and accessible application confirmation dialogs. See decisions 55 to 58. |
| — | **Production-readiness checkpoint** (historical timing) | Not authorized. Its former placement before B8a is pending replacement by the reconciled roadmap. |
| B8 | Projects; the shared Projects page. Two reviewed steps, B8a and B8b. | Historical approved-in-principle plan from decision 54; not current implementation authority and pending revalidation. |
| B9 | Sales outcome: Won with Sold Amount, Lost | Historical sequence; not authorized and pending revalidation. |
| B10 | Attachments and project photos | Historical sequence; not authorized and pending revalidation. |
| B11 | Timeline and record history | Historical sequence; target Activity/Notes direction is reconciled above. Not authorized. |
| B12 | Wrap-up and review | Historical sequence; not authorized and pending replacement. |

Each approved slice stops for the owner's review before the next begins.
Authorization, data integrity, tests and auditability are not traded for a date.

### Integration checkpoint: B1 to B6 on staging (2026-10-06)

Steps B1 to B6, with Owner impersonation, were merged into `main` with an
ordinary merge that keeps each step's commits, and deployed to staging by Forge.
This is a point where everything approved so far is known to work together on the
real server. It is not the end of Checkpoint B.

What was checked:

- The complete local check and both GitHub jobs (SQLite and MySQL 8.4) passed on
  the merge.
- Forge deployed it automatically. All 36 database changes are applied on staging
  MySQL 8.4, 23 of them new in this deployment, with none pending. Every new table
  is present and the starting list values are in place.
- **Mapbox is configured on staging and works.** One real lookup from the staging
  server returned suggestions and a permanent structured address with a map
  position. The token is set, is not printed anywhere, does not appear in any
  page, and is not in the log.
- Signed in as the Owner (checked inside the application, without the Owner's
  password): the home page, Lead & Sales Lists, Locations with service areas,
  Users and Access, the Leads list with its views, New lead with address lookup,
  the Customers list and New customer all load.
- Someone with no access is refused every one of those pages, and someone signed
  out is sent to the sign-in page.
- Email is still set to Resend, sessions are still kept in the database, debug
  mode is off, and no errors were logged after the deployment.
- Nothing was added to staging by these checks: no location, lead, customer or
  staff member.

One thing to know: **staging has no locations yet.** Until one exists, Leads and
Customers are refused even to the Owner and are not shown in the menus, because
nobody has a location to work in. This is the Checkpoint A rule that a missing
location never means "all locations". Adding a location in Settings > Locations
makes both appear. The two lists were checked on staging with a location that
existed only inside a database transaction that was rolled back.

### Historical production-readiness plan

The former plan placed production readiness around B7 or B8. Its safeguards below
remain valid constraints, but its timing is pending the reconciled roadmap and no
production action is authorized by this historical section.

Production is prepared before the operational steps are finished, not after B12.
Production does not reuse staging, and **real customer data is never entered into
staging.** Before real customer data is entered into production:

- a dedicated production environment and server;
- dedicated database credentials with least privilege (staging uses the server's
  general database account; see security-notes.md);
- production backups;
- a deliberate restore drill (see "Restoring" above);
- production Resend configuration and credentials;
- the real production domain, with HTTPS;
- the required two-factor policy for sensitive roles, as decided;
- a production file-storage strategy, before uploaded files are enabled.

### Decisions settled for Checkpoint B (2026-10-05)

- **Who sees a centrally received lead before a location owns it:** only Owner,
  Leadership and Call Center, through a company-wide grant.
- **How access to a customer follows its records:** whoever can see one of its
  leads or projects can see its core details, and never another location's work.
- **Audit scope:** important changes only, listed in checkpoint-b.md, extended to
  Checkpoint A's staff and access changes.
- **Where uploaded files are stored:** a private DigitalOcean Space of their own,
  `banana-backoffice-staging-files` in NYC3 for staging, separate from the backup
  Space and with its own access key. Nothing is created until step B10.
- **Existing data:** there is none to import.
- **The eight questions raised by the plan** (where Leads and Projects live in the
  menu, the Lead & Sales Lists page, Nurture, what a duplicate suggestion may
  reveal, Sold Amount, disqualification categories, launch scope): all settled.
  See checkpoint-b.md, "Decisions settled at the final review".

### Still open

- Whether sensitive roles (Owner, Finance) must use two-factor sign-in. Needed by
  the production-readiness checkpoint.
- The production domain. Needed by the production-readiness checkpoint.
- Whether Owner impersonation should be restricted or announced in production.
  Needed by the production-readiness checkpoint.
- **How uploaded files are backed up.** Database backups do not cover them. This is
  settled as part of step B10, before the first real file is stored. See "Backups"
  above.
