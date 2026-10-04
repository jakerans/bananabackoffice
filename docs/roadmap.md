# Roadmap

## Where we are

**Current checkpoint: A — Secure branded foundation.**
**State: built on the `checkpoint-a` branch (2026-10-04), awaiting owner review.**
Checkpoint B has not been started and needs the decisions listed at the end of
this document first.

| Checkpoint | Scope | State |
| --- | --- | --- |
| A | Secure branded foundation | Built, awaiting owner review |
| B | Core CRM records (customers, properties, projects, photos, notes, timeline) | Not started |
| C | First Color Studio workflow | Not started |
| Later | Estimating and proposals, homeowner portal, production, finance, reporting | Not started |

## Starting point (verified 2026-10-04)

- Laravel 13.34, Livewire 4.4, Flux UI free 2.20, Fortify 1.40, Tailwind 4.3,
  Pest 5.3, PHP 8.4, SQLite.
- Sign-in, password reset, email verification, two-factor and passkeys are installed.
- Public registration is already off: there is no `/register` route.
- The starter kit's 32 tests pass.
- The database has the starter tables only and no users.

## Checkpoint A plan

Status: **Approved** (2026-10-04) and **built**. Each step is one commit on the
`checkpoint-a` branch, named A0 to A7, with its own tests.

### What changed from the plan while building

- **Small screens.** The center menu scrolls sideways instead of collapsing into a
  slide-out menu. It is simpler and works without extra code.
- **Coming Soon in the center menu.** Unbuilt sections are grouped behind a single
  "Coming soon" label instead of each carrying its own, which kept the menu readable.
  Each one is labelled individually on the center landing page.
- **No third menu row yet.** No built section has sub-sections, so the sub-navigation
  row is not drawn. The registry already holds the sub-sections.
- **Settings has no single permission.** It opens for anyone who may manage
  locations or manage users, so no ninth permission was needed.
- **Invitation links have their own table** (`staff_invitation_tokens`) so they can
  never be used on the password reset form. See decisions.md, decision 11.
- **Company Overview and Settings do not depend on the selected location.** They
  are company-level and behave the same whichever location is selected.

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
- A landing page for each center and for Settings.
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
5. A landing page for each center the person is authorized for.
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

## Known limits of Checkpoint A

- The interface has been checked by automated tests, not yet by eye in a browser.
- Location names are compared exactly, so "Northside" and "northside" count as different.
- Changes to access are stamped with who granted them, but there is no audit log
  screen and removed grants leave no history.
- Invitation emails are sent immediately rather than queued, so a slow mail server
  slows that one click.
- The mail service for the live system is not chosen. Invitations need one before launch.
- `APP_URL` in the local `.env` still points at `http://localhost:8000`. Passkeys
  and emailed links use it, so it must match the address the app is opened at.

## Decisions to make before Checkpoint B

- Who can see a centrally received lead before a location owns it.
- Whether a person's access to a customer follows the customer's projects, the
  customer's properties, or both.
- Audit log scope.
- Whether sensitive roles (Owner, Finance) must use two-factor sign-in.
