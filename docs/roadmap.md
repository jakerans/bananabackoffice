# Roadmap

## Where we are

**Checkpoint A — Secure branded foundation — is approved and merged into `main`
(2026-10-04).**
**Current: slices 1 to 4 are built and merged into `main`, and slice 5's rulebook
is written and merged (2026-10-09). Slice 4 was built in three steps: 4a (location
timezones and the Contact Center settings), 4b (the scheduling engine and the
atomic due queue) and 4c (the Call Queue screen and manager tools). Both GitHub
jobs passed on the `main` commit that holds all of it, and staging deployed that
commit. The staging scheduler is on. The browser review of 4c was done on
2026-10-09 (see "Slice 4 boundary" below).** Its plan is
[slice-4-plan.md](slice-4-plan.md). The B1–B7 record and older unbuilt plan are in
[checkpoint-b.md](checkpoint-b.md). Jacob approved the five-slice order. **The
MySQL deadlock fix (decision 87), the fixes for three display faults found in
the 4c browser review, and the switch, one for each Call Center Agent, that
lets them see the Call Queue's manager part (decision 85) are built and merged
into `main` (2026-10-10).** **Slice 6, appointment booking, was then built in
three steps and merged into `main` (2026-10-10, decisions 90 to 120); see
below.** Nothing after slice 6 is planned or authorized, and every later slice
still stops for owner review. Since decision 122 each slice is checked against
[product-architecture.md](product-architecture.md) once it is merged.

**Steps B1 to B7 are approved and merged into `main` (2026-10-07).** B1 to B6
were deployed to staging on 2026-10-06; the automatic staging deployment caused
by the B7 merge was explicitly allowed and is verified separately. See
"Integration checkpoint" below. **B8 to B12 are not built.**

Slices 1 to 4 are completed and merged after B7. Slice 4 was authorized as
described in decisions 65 to 74 and nothing beyond it. **Slice 5's rulebook is
written** ([slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md),
decisions 75 to 84, job #13): it is design only and authorizes no build. B8,
Projects, production readiness, appointment implementation and other downstream
implementation remain unauthorized. The older future steps below remain
historical planning context; the rulebook replaces their Project-from-Qualified
assumption with "a Project is created when the sale is Won" (decision 82).

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
own reviewed plan and explicit implementation approval; slices 1 to 4 have now
received that approval.

| Order | Proposed slice | Product status | Implementation status and boundary |
| --- | --- | --- | --- |
| 1 | Five-Center shell alignment | **LOCKED:** Marketing, Sales, Production, Finance and Company; Settings under Company; Color Studio external. | **Approved, built, verified and merged into `main` (2026-10-07).** The current-ID/current-route/access compatibility map is in [navigation-map.md](navigation-map.md). Shell/navigation only; no new workspace behavior or permission expansion. |
| 2 | Shared Customer Activity and unified Notes | **LOCKED:** shared records, context/provenance/history-preserving Notes, and Activity made from major business events rather than every audit event. | **Approved, built, verified and merged into `main` (2026-10-07).** Reuses existing customer, Lead note and curated audit evidence; every related Lead, note and event location remains permission-scoped, and no raw audit payload or sensitive finance field is rendered. |
| 3 | Contact-attempt evidence foundation | **LOCKED:** each attempt is durable evidence with actor, channel, time and outcome; suspicious patterns go to manager review, with no automated punishment. **PENDING:** provider choice. | **Approved, built, verified and merged into `main` (2026-10-08).** Manual records are explicitly not provider-verified, preserve current actor/location/contact/lifecycle evidence, and cannot change lifecycle state. No provider selection, cadence enforcement or personnel policy is introduced. |
| 4 | Scheduled work and atomic due queue | **LOCKED:** due work is atomically claimed and manual Deferred Follow-up remains a distinct existing callback workflow; scope, exits, order, holds and failure rules (decisions 65, 67, 70, 71). **CONFIGURABLE:** cadence, calling hours and days, hold length (decisions 66, 69, 70). The scheduler, timezone, retry and failure contracts are **decided** (decisions 68, 71 and 73) and specified in [slice-4-plan.md](slice-4-plan.md). | **Approved for implementation (2026-10-08, decision 65), built and merged into `main` (2026-10-09): 4a, 4b and 4c. Jacob's browser review of 4c is still owed.** Three reviewed steps: 4a settings and timezones, 4b engine, 4c queue screen. No dialer, provider, outbound message, popup, snooze tray, Aged Leads, AI or penalties. B7 follow-ups are not reinterpreted as automated nurture. |
| 5 | Appointment issuance and ownership handoff contract | **LOCKED:** appointment booking hands operational responsibility from Contact Center to Sales, under the fourteen rules in [slice-5-appointment-rulebook.md](slice-5-appointment-rulebook.md) (decisions 75 to 83), including: a Project is created when the sale is Won (decision 82). **CONFIGURABLE:** appointment length (default 90 minutes), the 30-day release after a cancellation, the Pending ownership period. **Confirmed (2026-10-09):** what "a manager" means and that a Sales Consultant books only for themselves (decisions 88 and 89). **PENDING:** the Pending ownership period is provisional at 30 days until Sales Leadership confirms (decision 83); offer validity; the cancellation reasons; which salesperson an issued Lead counts for after a reassignment or re-issue; no-shows and appointment outcomes (decision 84). | **Rulebook written and merged into `main` (job #13, 2026-10-09), design only. Nothing is built and no implementation is proposed or approved.** No code, table, permission, screen or Project. Later appointment work must follow the rulebook and needs its own reviewed plan and Jacob's explicit approval. |

**Slice 6, appointment booking, is approved in three steps (2026-10-10,
decision 90),** built from the slice 5 rulebook. Its plan is
[slice-6-appointments-plan.md](slice-6-appointments-plan.md). **Step 6a is
built, reviewed and merged into `main` (2026-10-10):** booking an appointment issues the Lead to Sales; a
manager can mark it "booked in error"; the salesperson is told by a bell in
the header and by email; Sales > Calendar lists upcoming appointments. Steps 6b
and 6c are approved in order only: each is planned after the step before it is
reviewed and still needs decisions of its own. **Step 6b (reschedule, move,
cancel, book again, send back) is built and merged into `main` (2026-10-10)**
under decisions 97 to 114. **Step 6c (the release after 14 days, the Cancelled
Appointments queue with its own button, and a salesperson taking a released
Lead back) is built and merged into `main` (2026-10-10)** under decisions 115
to 120. **Slice 6 is complete as planned.**

**Slice 7, what happened at the appointment, proposals and their results, is
approved in four steps (2026-10-12, decision 125).** It is the after-action
report of the plan's section 5.6. Its plan, with the reasoning from the plan
for why it is next, is
[slice-7-appointment-results-plan.md](slice-7-appointment-results-plan.md).
**Step 7a (mark an appointment run or no-show) is built (2026-10-12)**,
with what decision 126 added to it: close out first, the prompt, the reminder
15 minutes after the appointment, and the salesperson told when a manager
touches their appointment. **It was merged on 2026-10-12** and is on staging.
**Step 7b (proposals) was built and merged on 2026-10-12.** **Step 7c
(results, correcting a proposal, a Lead that is Sold or Lost, and the
after-action report) was built and merged on 2026-10-13.** **Step 7d (the
first Sales figures, on Sales > Performance) was built and merged on
2026-10-13.** **Slice 7 is complete as planned.**

**Slice 8, a Project when a sale is Sold and the handoff to Production, is
approved in two steps (2026-10-13, decision 130).** Its plan, with the
reasoning from the plan for why it is next, is
[slice-8-projects-plan.md](slice-8-projects-plan.md). **Step 8a (the Project,
one for each sold proposal) and step 8b (the property of a proposal, the
handoff questions and Production > Workspace) were built and merged on
2026-10-13. Slice 8 is complete as planned.**

**The fixes after slice 8 (decision 134) were built and merged on
2026-10-13:** a Project named for what was sold, and Sales > Performance
brought in line with decisions 133 and 134 (Close Rate by lead ownership and
the month a proposal was accepted, corrections as adjustments, and a
no-demo's reason). **Decision 135 then made Close Rate demo to close:** the
wins are divided by the Leads demoed, not the Leads run. **Built and merged
on 2026-10-13 (decision 136).**

**Slice 9, Pending ownership and Rehash, is approved in three steps
(2026-10-13, decision 137).** Its plan, with the reasoning from the plan for
why it is next, is
[slice-9-pending-rehash-plan.md](slice-9-pending-rehash-plan.md). **Step 9a
(the clock on a pending proposal) was built and merged on 2026-10-13
(decision 138).** **Step 9b (Rehash: the release, the Rehash call queue,
marking lost from it and taking a Lead back) was built and merged on
2026-10-13 (decision 139).** **Step 9c (the Pending figures on Sales >
Performance) was built and merged on 2026-10-13 (decision 141).** **Slice 9
is complete as planned.**

**Slice 10, pre-production, is approved in three steps (2026-10-13, decision
141).** Its plan, with the reasoning from the plan for why it is next, is
[slice-10-pre-production-plan.md](slice-10-pre-production-plan.md). **Step 10a
(the welcome call and the rough timeframe) was built and merged on 2026-10-13
(decision 142).** **Step 10b (the Color Specification) was built and merged on
2026-10-13 (decision 143).** **Step 10c (readiness) was built and merged on
2026-10-14 (decision 144).** **Slice 10 is complete as planned.**

**Slice 11, Work Packages and the exact schedule, is approved in three steps
(2026-10-14, decision 144).** Its plan, with the reasoning from the plan for
why it is next, is [slice-11-schedule-plan.md](slice-11-schedule-plan.md).
**Step 11a (Work Packages and proposed windows) was built and merged on
2026-10-14 (decision 145).** **Step 11b (the confirmed schedule) was built and merged on 2026-10-14
(decision 146).** **Step 11c (Production > Schedule) was built and merged on 2026-10-14
(decision 147).** **Slice 11 is complete as planned.**

**Slice 12, subcontractors (the roster and compliance), is approved in two
steps (2026-10-14, decision 148).** Its plan is
[slice-12-subcontractors-plan.md](slice-12-subcontractors-plan.md). **Step 12a
(the roster and recruiting) was built and merged on 2026-10-14 (decision
149).** **Step 12b (compliance) was built and merged on 2026-10-14 (decision 150).**
**Slice 12 is complete as planned.**

**Slice 13, giving work to a subcontractor (payout, the award and the work
order), is approved in three steps (2026-10-14, decision 151).** Its plan is
[slice-13-giving-work-plan.md](slice-13-giving-work-plan.md). **Step 13a
(payout) was built and merged on 2026-10-14 (decision 152).** **Step 13b (the
award) was built and merged on 2026-10-14 (decision 153).** **Step 13c (the work
order) was built and merged on 2026-10-15 (decision 154).** **Slice 13 is
complete as planned.**

**Slice 14, the work and its completion, was built overnight under decision
155 and merged on 2026-10-15 (decision 157).** Its plan is
[slice-14-work-and-closeout-plan.md](slice-14-work-and-closeout-plan.md).

**Slice 15, Average Job Size and Close-on-the-Spot Rate, was built overnight
under decision 155 and merged on 2026-10-15 (decision 157):**
[slice-15-sales-figures-plan.md](slice-15-sales-figures-plan.md).

**Slice 16, document storage, is approved in three steps (2026-10-15,
decision 157).** Jacob set up the Wasabi buckets (decision 156); its plan is
[slice-16-document-storage-plan.md](slice-16-document-storage-plan.md). Step
16a (the storage itself) is merged (decision 158), and its restore drill
passed on staging on 2026-10-15. Step 16b (compliance documents) is merged
(decision 160); step 16c (photos of the work) is merged (decision 161).
Slice 16 is complete.

**Slice 17, customer payments, is approved in three steps (2026-10-22,
decision 162); step 17a (invoices and payments) is merged (decision 163);
step 17b (the deposit) is merged (decision 164); step 17c (the final balance
and who chases it) is merged (decision 165). Slice 17 is complete.**

**Slice 18, change orders, is approved in three steps (2026-10-24, decision
166); step 18a (customer change orders) is merged (decision 167); step 18b
(subcontractor work order revisions) is merged (decision 168); step 18c
(after subcontractor payouts) is merged (decision 169). Slice 18 is
complete.** The
plan check after slice 17 found that what the customer owes, and what
Banana owes a subcontractor, cannot yet change after the sale. Its plan is
[slice-18-change-orders-plan.md](slice-18-change-orders-plan.md).

**Slice 19, job costing and the financial close, is approved (2026-10-24,
decision 170), built straight through its three steps and merged (decision
171). Slice 19 is complete.** The plan check after slice 18 found the internal,
financial close of a job (6.8, 6.9) the last part of closeout still
unbuilt. Its plan is
[slice-19-job-costing-plan.md](slice-19-job-costing-plan.md).

**Slice 20, variable compensation, is proposed (2026-10-24) and not
approved.** The plan check after slice 19 found the sales commission and the
Production Bonus waiting on the final gross margin slice 19 produces. Its
plan is [slice-20-compensation-plan.md](slice-20-compensation-plan.md).
The plan check after slice 16 found the last customer-facing step of
closeout, "final balance collected", and everything about money from the
customer still unbuilt. Its plan is
[slice-17-customer-payments-plan.md](slice-17-customer-payments-plan.md).

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

### Completed slice 3 boundary

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

### Slice 4 boundary (4a, 4b and 4c merged; browser review of 4c owed)

Jacob's decisions are 65 to 72 and 74 in [decisions.md](decisions.md); the design is
decision 73 and [slice-4-plan.md](slice-4-plan.md). His approval of the planning
batch on 2026-10-08 is the implementation approval for exactly this and nothing
beyond it.

| Step | Delivers | State |
| --- | --- | --- |
| 4a | A timezone on each Location; the Contact Center settings page under Company > Settings (cadence, calling hours and days, hold length) with its permission and audit events | **Merged into `main`** (job #10): `locations.timezone`, `contact_center_settings` version 1, the two permissions (`leads.work_queue` added here by decision 74), the page at `/settings/contact-center`, the four audit events. |
| 4b | The engine: every new Lead enters the cadence; a logged attempt advances it; the atomic claim, holds, skips, Put back in queue; the every-minute background timer. No screen. | **Merged into `main`** (job #11): `lead_cadences`, `lead_queue_events`, `scheduler_heartbeats` and the two cadence columns on `contact_attempts`; the calculators and the one eligibility rule in `app/Support/Queue/`; the actions in `app/Actions/Queue/`; the hooks in capture, contact attempts, disqualify, Deferred Follow-up, routing, access and Location deactivation; `backoffice:queue-tick` scheduled every minute; `lead.returned_to_queue` and `LeadPolicy::returnToQueue`. From deployment, newly captured Leads enter the cadence and logged attempts move it. Nothing was visible to staff until 4c. |
| 4c | The Call Queue in Marketing > Contact Center: due-now count, Next lead, the card, the manager summary and the "Cadence finished, no contact" list | **Merged into `main`** (job #12, 2026-10-09; Jacob's browser review is still owed): the page at `/marketing/contact-center/queue` (`marketing.call_center.queue`, `leads.work_queue`), the setter's card with the shared attempt form, skip and disqualify, the 10-second poll, the manager summary with the timer warning, the "Cadence finished, no contact" list, and the cadence panel, queue history and Put back in queue on the Lead page. No new table, permission or audit event. |

One recorded change to slice 3: logging a contact attempt now advances the
cadence (decision 67). It still never changes lifecycle status or assignee.

**Staging needs the server's scheduler enabled in Forge** for the background
timer: one entry running `php artisan schedule:run` every minute for the staging
site. **It is on (2026-10-09).** Forge showed it off after 4c was merged; with
Jacob's approval the staging site's Laravel scheduler switch was turned on, and
the `queue_tick` heartbeat in the staging database confirmed it running a
minute later. The record is in "The server's scheduler on staging" in the plan.

Decided by Jacob on 2026-10-09, after the 4c handoff:

- **Call Center Agents are to see the Call Queue's manager part only when
  Leadership switches it on** (decision 85): one switch for each agent,
  starting off, changed by Owner and Leadership on the Contact Center settings
  page, covering the manager part of both the Call Queue page and the Lead
  page. **Built on 2026-10-09:** a new section on the Contact Center settings
  page, the column `users.queue_manager_view`, and the audit event
  `staff.queue_manager_view_changed`. No permission or role grant changed.
  Once deployed, every agent's switch is off until someone turns it on.
- **Skip after calling hours close stays as built** (decision 86).
- **The MySQL deadlock fix is built** (decision 87, 2026-10-09): the Lead row
  is locked before the cadence row wherever a hold is ended, and the actions
  that also end a hold or a cadence run again from the start when the database
  reports a deadlock.
- **Jacob's browser review of 4c was done on 2026-10-09** on the local site, by
  the implementation agent at his request. The queue works as designed; three
  display faults were found and fixed the same day (doubled error messages in
  the Skip and Disqualify dialogs, a Put back message the page's refresh wiped,
  and a stray separator in the Lead page's queue history).

Known follow-ups recorded with this slice, none of them authorized:

- Existing screens (Received time, follow-up dates) still use company time.
  Moving them to each Location's time is a separate later job (decision 68).
- A confirmation text to a Lead that arrives outside calling hours, once texting
  exists and a provider is approved (decision 72).

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
- Passkeys only work over a secure address. The local site was served over plain
  `http` until 2026-10-10, when Jacob turned on Herd's HTTPS option; it is now at
  `https://backoffice.test`. Passkeys have not been tried locally since.

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
| B8 | Projects; the shared Projects page. Two reviewed steps, B8a and B8b. | Historical approved-in-principle plan from decision 54; not current implementation authority and pending revalidation. Its Project-from-Qualified starting point is replaced as the target rule by decision 82: a Project is created when the sale is Won. |
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
