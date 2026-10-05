# Roadmap

## Where we are

**Checkpoint A — Secure branded foundation — is approved and merged into `main`
(2026-10-04).**
**Next: Checkpoint B.** It has not been started and needs the decisions listed at
the end of this document first.

| Checkpoint | Scope | State |
| --- | --- | --- |
| A | Secure branded foundation | Approved and merged (2026-10-04) |
| B | Core CRM records (customers, properties, projects, photos, notes, timeline) | Not started |
| C | First Color Studio workflow | Not started |
| Later | Estimating and proposals, homeowner portal, production, finance, reporting | Not started |

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
[security-notes.md](security-notes.md). Two of the open ones must be closed before
real staff use the system on a server: "forgot password" reveals which addresses
have accounts, and a deactivated person can complete a passkey sign-in before
being signed out again.

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

State: **agreed, not yet configured** (2026-10-04). Nothing is being backed up yet.

Agreed target for staging:

| | |
| --- | --- |
| Method | Forge's built-in database backups |
| What | The `banana_backoffice` MySQL database |
| Destination | DigitalOcean Spaces, in a bucket used only for this: `banana-backoffice-staging` |
| Frequency | Daily |
| Retention | The 7 most recent backups |
| Failure notifications | On |

Waiting on: the bucket and a storage destination being created in DigitalOcean and
Forge by the owner. See decisions.md, 23.

Production will get its own, stricter configuration when it is set up: more
frequent backups, longer retention, its own bucket and its own access key.

Known gaps to close for production:

- A restore goes back to the last backup and no further, because MySQL's change
  log is switched off. Point-in-time recovery would need it switched on.
- A restore has never been tested. One should be done on staging once backups run.

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

Checkpoint B introduces private photos, so this must be decided before Checkpoint B
stores its first real file. It is listed under "Decisions to make before
Checkpoint B" below.

## Automated checks on GitHub

Every push to `main` and every pull request runs two jobs:

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
- Changes to access are stamped with who granted them, but there is no audit log
  screen and removed grants leave no history.
- Invitation emails are sent immediately rather than queued, so a slow mail server
  slows that one click.
- Application email is sent through Resend from `mail.bananapainting.com`
  (decisions.md, 22). Replies on staging go to `admin@bananapainting.com`, an
  interim address. The permanent reply-to inbox, and production's, are still to
  be named.
- Passkeys only work over a secure address. The local site is served over plain
  `http`, so passkeys cannot be tried locally until Herd's HTTPS option is turned on.

## Decisions to make before Checkpoint B

- Who can see a centrally received lead before a location owns it.
- Whether a person's access to a customer follows the customer's projects, the
  customer's properties, or both.
- Audit log scope.
- Whether sensitive roles (Owner, Finance) must use two-factor sign-in.
- **Where uploaded files are stored and how they are backed up.** Database backups
  do not cover them. Checkpoint B's private photos must not start storing real
  files until this is settled. See "Backups" above.
