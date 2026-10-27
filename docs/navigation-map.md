# Navigation Map

Status: **As-built navigation through B7 is approved and live.** The five-slice
roadmap order and implementation of its first slice are owner-approved. The target
five-Center structure below is **LOCKED product direction**; the compatibility
implementation passed local checks, independent review, browser review, SQLite CI
and MySQL 8.4 CI, and was merged into `main` on 2026-10-07.

## Canonical target and compatibility migration

The target parent Centers are **Marketing, Sales, Production, Finance and
Company**. Customers and other canonical records remain shared pages presented
inside the Centers that use them. Color Studio is an external/customer surface,
not a parent Center. Settings belongs under Company. This describes the target
implemented by slice 1; it does not claim that muted future features are built.

### Approved slice 1 implementation map

Slice 1 changes the registry and shell presentation only. It does not build a
Contact Center, Inbox, Sales or Production Workspace, calendar, warranty,
scorecard or report. A target item is muted `coming_soon` unless the table below
explicitly maps it to an existing working route.

| Target destination | Stable ID and route compatibility | State and access |
| --- | --- | --- |
| Company | Keep root ID `company`, route name `company.home` and `/company`. Relabel Company Overview to Company. | Its entry permission becomes the union of the existing `company.overview.access`, `settings.locations.manage`, `settings.users.manage` and `settings.lead_sales_lists.manage` gates so each existing Settings-only user can still enter the containing Center. This is composition of current grants, not a new grant. |
| Company → Executive Board | Keep `company.performance` and `/company/performance`; change only the label. | Existing `company.overview.access`; current primary coming-soon page. |
| Company → Leadership Scorecards | Add `company.leadership_scorecards`; `/company/leadership-scorecards`, route name `company.leadership_scorecards` (slice 27, step 27b). | Live; `company.overview.access`. |
| Company → Needs Attention | Add `company.needs_attention`; `/company/needs-attention`, route name `company.needs_attention` (slice 26, step 26b). | Live; `company.overview.access`. |
| Company → Locations | Reparent `settings.locations` as a direct Company child; keep `/settings/locations`. | Live; existing `settings.locations.manage`. |
| Company → Reports | Keep `company.reports`; no route. | Coming soon; `company.overview.access`. |
| Company → Settings | Reparent the existing `settings` node under Company. Keep `settings.users`, `settings.lists` and the unbuilt Settings children beneath it; `settings.locations` moves to the direct Locations item above. Preserve `settings`, every `settings.*` ID, `settings.home`, `/settings` and every live `/settings/*` URL. | The nested entry keeps the current union of Settings permissions. `/settings` still lands on the first working Settings destination the user may open, including the sibling Locations item. |
| Marketing → Performance | Keep `marketing.performance` and `/marketing/performance-board`. | Existing state and `marketing.access`. |
| Marketing → Contact Center | Keep `marketing.call_center`, relabel it, and keep `shared.leads` as its live interim landing destination at `/leads`. | Existing Marketing and Lead permissions; no calling behavior is added. |
| Marketing → Inbox | Reparent `shared.inbox` as a top-level Marketing item; no route. Reserve `marketing.call_center.inbox` as a legacy ID rather than reusing it. | Coming soon; `marketing.access`. |
| Marketing → Campaigns | Keep `marketing.campaigns`; no route. | Coming soon; `marketing.access`. |
| Marketing → Customers | Keep `shared.customers` and `/customers`. | Live; existing `accounts.view`. |
| Marketing → Reports | Keep `marketing.reporting`, relabel it Reports; no route. | Coming soon; `marketing.access`. |
| Sales → Performance | Keep `sales.performance` and `/sales/performance-board`. | **Built (slice 7 step 7d, 2026-10-13).** Live; `sales.access`. The first Sales figures for a chosen month at one Location: a person's own, or for a manager everyone's or any one salesperson's at their Locations. |
| *(no menu item)* A Project | `/projects/{project}`, route name `projects.show`. | **Built (slice 8 step 8a, 2026-10-13).** `projects.view` at the Project's own Location. Reached from the Lead's Proposals card, the customer page and the customer's Activity. The plan gives Projects no page in any menu (6.2); step 8b lists them in Production → Workspace. |
| Sales → Workspace | Add `sales.workspace`, with `shared.leads` at `/leads` as its live interim child/landing destination. | Existing Sales and Lead permissions; no selling workflow is added. |
| Sales → Calendar | `sales.calendar`; route name `sales.calendar`; `/sales/calendar`. | **Built (slice 6, steps 6a and 6b, 2026-10-10).** Live; `sales.access`. A plain list of upcoming appointments: a person's own, or every one at the Locations where they hold `appointments.manage`. Under it, "To rebook": Leads whose appointment was cancelled and that still wait for a new one, with the days left, under the same rule. Since step 6c a third list: Leads released to the Contact Center that can still be taken back. Since slice 7 step 7a, at the top: "To close out", appointments whose time has come and that nobody has marked run or no-show. Since decision 126 a salesperson with one that is over and unanswered also sees a bar under the header on every page, linking to the oldest. Since step 7c that link, and one on each "To close out" row, open the after-action report: `/leads/{lead}/appointments/{appointment}/report`, route name `leads.report`, a page of the Lead and not a menu item. |
| Sales → Commissions | Keep `sales.commissions`; no route. | Coming soon; `sales.access`. |
| Sales → Leaderboard | `sales.leaderboard`; route name `sales.leaderboard`; `/sales/leaderboard`. | **Built (slice 23, step 23c).** Live; `sales.access`. Company-wide sold revenue by salesperson, month, quarter and year to date, with champions and winners. |
| Sales → Customers | Keep `shared.customers` and `/customers`. | Live; existing `accounts.view`. |
| Sales → Reports | Keep `sales.reports`; no route. | Coming soon; `sales.access`. |
| Production → Performance | Keep `production.performance` and `/production/performance-board`. | Existing state and `production.access`. |
| Production → Workspace | `production.workspace`; route name `production.workspace`; `/production/workspace`. | **Built (slice 8 step 8b, 2026-10-13).** Live; `production.access`. Sold jobs at one Location in three lists: waiting for the salesperson's handoff, new from Sales, and pre-production. A Production Manager takes a job; a manager assigns one. No stage page beyond pre-production is built. |
| *(no menu item)* A Project's handoff | `/projects/{project}/handoff`, route name `projects.handoff`. | **Built (slice 8 step 8b, 2026-10-13).** The salesperson who sold it, or `projects.manage`. Reached from the prompt under the header, the Lead's Proposals card and the Project page. |
| Production → Schedule | Add `production.schedule`; no route. | Coming soon; `production.access`. |
| Production → Subcontractors | Keep `production.crews` and change only its label. | Coming soon; `production.access`. |
| Production → Warranty | `production.warranty`; route name `production.warranty`; `/production/warranty`. | **Built (slice 22, step 22a).** Live; `production.access`. The warranty cases at one Location: open and scheduled, then those finished in the last 30 days. Each case has its own page on its job, `/projects/{project}/warranty/{case}`. |
| Production → Customers | Keep `shared.customers` and `/customers`. | Live; existing `accounts.view`. |
| Production → Reports | Keep `production.reports`; no route. | Coming soon; `production.access`. |
| Finance → Performance, Cash Management, Job Costing, Payroll | Keep the current IDs, hierarchy and working `/finance/performance-board` route. | Existing states and `finance.access`. |
| Finance → People & Access | Add `finance.people_access` pointing to the existing `settings.users` route and `/settings/users`. | Live only for `settings.users.manage`; it remains hidden from the Finance role unless that separate permission is already held. |
| Finance → Customers | Keep `shared.customers` and `/customers`. | Live; existing `accounts.view`. |
| Finance → Reports | Keep `finance.reports`; no route. | Coming soon; `finance.access`. |
| Color Studio external destination | Keep `color_studio`, `color_studio.*`, `/color-studio` and `/color-studio/color-projects` unchanged. Remove it only from the Center collection, not from authorized destinations. | Existing `color_studio.access`; still location-scoped and discoverable from Home and the header in a visually separate External group. |

The old unbuilt IDs `marketing.call_center.inbox`, `marketing.social`,
`marketing.reviews`, `sales.appointments`, `sales.estimates`, `sales.proposals`,
`sales.relationships`, `production.projects*`, `finance.commission_approvals` and
the Center-level `shared.inbox` placements are reserved as legacy IDs and are not
reassigned. They have no current URLs, so redirects would manufacture compatibility
that does not exist. All existing routed names and URLs above remain valid; no
working URL is removed.

### Shell and landing compatibility

- `centers()` returns exactly the five parent Centers. A separate authorized
  destination/context collection contains those Centers plus Color Studio. Home
  renders the two groups, and the header switcher visually separates Color Studio
  as External while keeping it reachable.
- Route ownership and active navigation use the full context collection, not only
  Centers. Existing shared `/leads`, `/customers` and `/settings/users` routes keep
  the last authorized context in `backoffice.center`; a remembered unauthorized
  context is never trusted.
- A legacy session value of `settings` maps to `company`. `color_studio` remains a
  valid external context. The five retained Center IDs require no session migration.
- `/settings` needs a nested-navigation landing action instead of the root-only
  Center controller. It resolves and authorizes the compatibility entry, then
  checks the landing order—Locations, Users and Access, Lead & Sales
  Lists, and (since step 4a) Contact Center—and redirects to the first destination the user already has permission
  to open. Direct Settings URLs keep their current controllers and server-side
  gates.
- `/company`, `/marketing`, `/sales`, `/production`, `/finance` and `/color-studio`
  keep their current route names and landing behavior. New coming-soon entries do
  not receive placeholder routes unless they are required as an approved primary
  landing; none is required by this plan.

### Permission and regression matrix

No enum, role grant, policy, gate or location-scope rule changes in slice 1.

| Existing role/grant context | Destinations after alignment |
| --- | --- |
| Owner | All five Centers, Settings pages, Finance People & Access and external Color Studio. |
| Leadership, all locations | Company, Marketing, Sales and Production; external Color Studio; existing Settings list access only unless another current Settings permission is held. No Finance. |
| Leadership, one location | Marketing, Sales and Production plus external Color Studio for that location. Company-wide pages remain unavailable. |
| Finance | Finance and its shared Customers page. People & Access stays hidden and forbidden without `settings.users.manage`. |
| Location Manager | Marketing, Sales and Production plus external Color Studio for authorized locations. No Company, Finance or Settings access. |
| Call Center Agent | Marketing for authorized scope. No other Center or external Color Studio. |
| Sales Consultant | Sales plus external Color Studio for authorized locations. |
| Production Manager | Production for authorized locations. |
| No grants | No destination; the existing no-access state remains. |

Required regression coverage:

1. Registry tests assert exactly five Center IDs, separate external Color Studio,
   unique non-legacy IDs, valid routed items, target order/labels and muted unbuilt
   items.
2. Dashboard, switcher and controller tests cover every role row above, location
   versus all-locations scope, direct forbidden requests, Settings-only entry into
   Company, and Color Studio discoverability outside the Center group.
3. Landing tests cover every retained root URL, `/settings` for each of its three
   existing permission paths, and denial with no matching Settings permission.
4. Shared-context tests cover Marketing/Sales `/leads`, all four `/customers`
   contexts, Company/Finance `/settings/users`, active navigation, authorized
   session preference, the `settings` session alias and rejection of a remembered
   unauthorized context.
5. Existing Settings, Leads, Customers, impersonation and location-switching
   feature suites remain green. No database migration or feature data is needed.

Likely implementation files are `app/Support/Navigation/Navigation.php`,
`app/Support/Navigation/Shell.php`, `routes/web.php`, a small nested-navigation
landing controller, the dashboard and shell switcher/layout views, and their
existing navigation/controller/dashboard/shared-record tests. Creation of the
controller must use the repository's Artisan convention. No dependency,
database, permission enum, role, policy or domain-action file belongs in this
slice.

## Slice 4 navigation changes (4a and 4c merged; 4b adds none)

Approved for implementation on 2026-10-08 (decision 65). The rows marked 4a are
merged into `main` (job #10). Step 4b (the engine, job #11) added no navigation item, route or page. The 4c row is merged into `main` (job #12, 2026-10-09); the owner's browser review of it is still owed. The plan is [slice-4-plan.md](slice-4-plan.md); the
permissions are in [permission-model.md](permission-model.md), "Slice 4".

| Destination | Stable ID, route and URL | State and access | Step |
| --- | --- | --- | --- |
| Company → Settings → Contact Center | New ID `settings.contact_center`; route name `settings.contact_center`; `/settings/contact-center`. Listed after Lead & Sales Lists. | **Built.** Live; `settings.contact_center.manage` (Owner and Leadership, all-locations grant only). | 4a |
| Company and Company → Settings (entry) | No ID or URL change. `settings.contact_center.manage` joins the existing union of permissions that open the `company` Center and the `settings` node. `/settings` adds Contact Center as its fourth landing choice, after Locations, Users and Access, and Lead & Sales Lists. | **Built.** The permission is added to the `company` and `settings` entry unions only, not to the list the unbuilt Settings pages borrow. It opens no other Settings page. | 4a |
| Company → Locations | No new item. The existing `settings.locations` page gains a timezone field for each Location. | **Built.** Existing `settings.locations.manage`. | 4a |
| Marketing → Contact Center → Call Queue | New ID `marketing.call_center.queue`; route name `marketing.call_center.queue`; `/marketing/contact-center/queue`. A third-row item after Leads. | **Built.** Live; `leads.work_queue` (Owner, Leadership, Location Manager, Call Center Agent). Opened like Leads, in the person's current Location view. | 4c |

- **Contact Center still opens on Leads.** Its primary stays `shared.leads`, so
  no one's landing page changes.
- **The Call Queue is in Marketing only.** Sales gets no queue item, and a Sales
  Consultant has neither the item nor the route.
- **No existing ID, route name or URL changes,** and no reserved legacy ID is
  reused. `marketing.call_center.inbox` stays reserved.
- **The queue's views are not separate pages.** The setter's card, the manager
  summary and the "Cadence finished, no contact" list are parts of the one Call
  Queue page and add nothing else to the menus.
- **Still not built after slice 4:** Inbox, the new-lead popup, the snooze tray,
  Aged Leads and any dialer.

Regression coverage required with these changes: registry IDs stay unique and
every live item is routed; each role row in "Permission and regression matrix"
below keeps its current destinations, with Contact Center settings added for
Owner and all-locations Leadership only and Call Queue added for Owner,
Leadership, Location Manager and Call Center Agent only; `/settings` keeps its
existing landing order for the three existing permissions; direct requests
without the permission are refused.

## As-built navigation through B7

Navigation is defined once, in code, in a single registry. Every item has:

| Field | Meaning |
| --- | --- |
| ID | Stable identifier. Never changes, even if the label does. |
| Label | What staff see. |
| Parent | The center or item it sits under. |
| Permission | What a person needs to see and open it. |
| Scope | `location` (works inside one location or All Locations) or `company` (company-wide only). |
| State | `live` (built) or `coming_soon` (not built). |
| Route | Every built item has one. An unbuilt item has one only if its center opens on it. |
| Primary | The section a center opens on, or the sub-section a section opens on. |

### The hierarchy

The current shell has exactly three levels, and no level in between:

1. **Ink header** — brand, center selector, location selector, user.
2. **Second row** — the sections of the selected center, and nothing else.
3. **Page** — the content of the selected section.

A center has no page of its own. There is no "Overview" page and no "Overview"
item. Choosing a center goes straight to that center's primary section.

### The three states a person can see

| Situation | What the person sees | What the server does |
| --- | --- | --- |
| Authorized and built | A normal link | Serves the page |
| Authorized and not built | The section name, muted and not clickable. "Coming soon" appears on hover and is read out by screen readers. | No route exists |
| Not authorized | Nothing | Refuses the request ("forbidden") |

"Coming soon" is a state of an individual section. It is never a navigation item
of its own.

"Not built yet" and "not authorized" are never shown the same way. Hiding an item
is a courtesy; the server-side check is what protects the page.

### The one exception: a center's primary section

A center has to open on something. If its primary section is not built yet, that
one section still has a page: its title, a "Coming soon" badge and a plain
statement that it is not built. It shows no numbers, charts, tables or buttons.
Because it is the page you are on, it appears in the second row as the current
item. Every other unbuilt section stays muted and unclickable.

Until a feature is built it borrows its center's access permission. When it is
built it receives its own specific permission, recorded in
[permission-model.md](permission-model.md).

## Shell layout

```
+----------------------------------------------------------------------------+
| Brand | Sales Center v                    Location v      (AB) Name v     |  Ink header
+----------------------------------------------------------------------------+
| PERFORMANCE BOARD  Appointments  Estimates  Proposals  Relationships  ...  |  Sections of the center
| =================                                                          |  Yellow marker
+----------------------------------------------------------------------------+
| Cream workspace with Card surfaces: the selected section                   |
+----------------------------------------------------------------------------+
```

- Header: brand at left, then the center selector, then on the right the location
  selector and the user menu.
- Second row: the sections of the selected center, in the order listed below. The
  current section is heavier with a small Yellow marker.
- A section that has pages of its own (Call Center is the first) shows them in a
  third row while you are inside it. In the second row such a section is a link to
  the first of its pages the person may open. If none of its pages is built, it
  stays muted like any other unbuilt section.
- On small screens the center menu scrolls sideways.
- Company Overview and Settings are company-level: they do not change with the
  selected location. Every other center follows the selected location.
- Settings opens for anyone who may manage locations, manage users, manage the
  Lead & Sales Lists, or (since step 4a) manage the Contact Center settings. Its
  unbuilt pages borrow the first two permissions only, so someone who holds just
  the lists or Contact Center permission sees that one page and no other Settings
  item, built or not.

## Where each center opens

| Center | Opens on | Built? |
| --- | --- | --- |
| Company Overview | Company Performance | Not yet |
| Marketing Center | Performance Board | Not yet |
| Sales Center | Performance Board | Not yet |
| Production Center | Performance Board | Not yet |
| Finance Center | Performance Board | Not yet |
| Color Studio | Color Projects | Not yet |
| Settings | Locations | Yes |

Sections with sub-sections open on:

| Section | Opens on |
| --- | --- |
| Marketing → Call Center | Central Inbox |
| Marketing → Reporting | Lead Sources |
| Production → Projects | Ready to Schedule |
| Finance → Cash Management | Receivables |

How these were chosen:

- Marketing, Sales, Production and Finance open on Performance Board, as the owner
  specified.
- Color Studio, Company Overview and every section with sub-sections open on the
  first item listed for them in the owner's brief. Nothing was invented.
- Settings is the one center that does not open on its first listed item
  ("Company", which is not built). It opens on Locations, its first working page,
  so that Settings never lands on an empty page while working pages sit beside it.
  This can be changed in one line when Company is built.

If a person may not open a center's primary section but may open another section
in that center, the center opens on the first section they may open.

## As-built center registry through B7

State at the end of Checkpoint A. `CS` = Coming soon. Each center's address
(`/sales`, for example) is only a shortcut that opens its primary section.

### Company Overview — `company` — scope: company

| ID | Label | State |
| --- | --- | --- |
| `company.home` | Shortcut: opens Company Performance | — |
| `company.performance` | Company Performance | **Live (slice 25, step 25c), primary:** the Executive Board, `/company/performance`, route name `company.performance`, `company_overview.access` |
| `company.leadership_scorecards` | Leadership Scorecards | **Live (slice 27, step 27b):** every Location's weekly scorecard side by side, `/company/leadership-scorecards`, route name `company.leadership_scorecards`, `company_overview.access` |
| `company.needs_attention` | Needs Attention | **Live (slice 26, step 26b):** every open item of the exception engine, `/company/needs-attention`, route name `company.needs_attention`, `company_overview.access` |
| `company.location_comparison` | Location Comparison | CS |
| `company.reports` | Company Reports | CS |

### Marketing Center — `marketing` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `marketing.home` | Shortcut: opens Performance Board | — |
| `marketing.performance` | Performance Board | **Live (slice 25, step 25b), primary:** `/marketing/performance-board`, route name `marketing.performance`, `marketing.access` at the Locations shown; marketing spend at `/marketing/spend`, route name `marketing.performance.spend`, `marketing.spend.manage` |
| `marketing.call_center` | Call Center | CS |
| `marketing.call_center.inbox` | Central Inbox | CS |
| `shared.leads` | Leads | Live (step B3). The shared Leads page; see "Shared". It replaced the never-built `marketing.call_center.leads`. |
| `marketing.call_center.appointments` | Appointments | CS |
| `marketing.call_center.close_assist` | Close Assist | CS |
| `marketing.call_center.canceled` | Canceled Appointments | CS |
| `marketing.call_center.reports` | Call Center Reports | CS |
| `marketing.campaigns` | Campaigns | CS |
| `marketing.social` | Social | CS |
| `marketing.reviews` | Reviews | CS |
| `marketing.reporting` | Reporting | CS |
| `marketing.reporting.lead_sources` | Lead Sources | CS |
| `marketing.reporting.leads` | Leads | CS |
| `marketing.reporting.appointments` | Appointments | CS |
| `marketing.reporting.call_center` | Call Center | CS |
| `marketing.reporting.campaigns` | Campaigns | CS |
| `marketing.reporting.roi` | Marketing ROI | CS |

### Sales Center — `sales` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `sales.home` | Shortcut: opens Performance Board | — |
| `sales.performance` | Performance Board | CS, primary |
| `shared.leads` | Leads | Live (step B3). The shared Leads page; see "Shared". |
| `sales.appointments` | Appointments | CS |
| `sales.estimates` | Estimates | CS |
| `sales.proposals` | Proposals | CS |
| `sales.relationships` | Relationships | CS |
| `sales.commissions` | Commissions | CS |
| `sales.reports` | Reports | CS |

### Production Center — `production` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `production.home` | Shortcut: opens Performance Board | — |
| `production.performance` | Performance Board | **Live (slice 23, step 23a), primary:** `/production/performance-board`, route name `production.performance`, `production.access` at the Locations shown |
| `production.projects` | Projects | CS |
| `production.projects.ready` | Ready to Schedule | CS |
| `production.projects.pre_production` | Pre-Production | CS |
| `production.projects.in_production` | In Production | CS |
| `production.projects.completed` | Completed | CS |
| `production.crews` | Crews / Subcontractors | CS |
| `production.reports` | Reports | CS |

### Finance Center — `finance` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `finance.home` | Shortcut: opens Performance Board | — |
| `finance.performance` | Performance Board | **Live (slice 23, step 23b), primary:** `/finance/performance-board`, route name `finance.performance`, `finance.access` at the Locations shown; variable pay owed with `compensation.manage` |
| `finance.cash` | Cash Management | CS |
| `finance.cash.receivables` | Receivables | **Live (slice 17, step 17c):** `/finance/receivables`, route name `finance.receivables`, `finance.access` at the Locations shown |
| `finance.cash.payments` | Payments | CS |
| `finance.cash.payouts` | Payouts | CS |
| `finance.job_costing` | Job Costing | **Live (slice 19, step 19c):** `/finance/job-costing`, route name `finance.job-costing`, `finance.access` at the Locations shown |
| `finance.payroll` | Payroll | **Live (slice 20, step 20a):** `/finance/payroll`, route name `finance.payroll`, `compensation.manage`; each person's page at `/finance/payroll/people/{user}`. Everyone's own pay is "My pay" in the account menu, `/pay`, shown once they have a pay plan or a ledger entry |
| `finance.commission_approvals` | Commission Approvals | CS |
| `finance.reports` | Reports | CS |

### Color Studio — `color_studio` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `color_studio.home` | Shortcut: opens Color Projects | — |
| `color_studio.projects` | Color Projects | CS, primary |
| `color_studio.sample_requests` | Sample Requests | CS |
| `color_studio.estimate_requests` | Estimate Requests | CS |
| `color_studio.catalog` | Color Catalog | CS |

### Settings — `settings` — scope: company

| ID | Label | State |
| --- | --- | --- |
| `settings.home` | Shortcut: opens Locations | — |
| `settings.company` | Company | CS |
| `settings.locations` | Locations | Live, primary. Each location's service area (its ZIP codes) is managed here. |
| `settings.users` | Users and Access | Live |
| `settings.lists` | Lead & Sales Lists | Live (step B2). Opened by its own permission; see below. |
| `settings.service_areas` | Service Areas | CS |
| `settings.services` | Services and Products | CS |
| `settings.pricing` | Pricing Settings | **Live (slice 31, step 31a):** the default price book and each Location's, production rates, paints and hourly rates, `/settings/pricing`, route name `settings.pricing`; seen with `settings.pricing.view` or `settings.pricing.manage`, changed with `settings.pricing.manage` (Owner and Leadership) |
| `settings.templates` | Templates | CS |
| `settings.integrations` | Integrations | **Live (slice 28, step 28a):** each Location's QuickBooks company, `/settings/integrations`, route name `settings.integrations`, `settings.locations.manage` |
| `settings.audit_log` | Audit Log | **Live (slice 29, step 29a):** every recorded change, newest first, `/settings/audit-log`, route name `settings.audit_log`, `settings.audit_log.view` (Owner only) |

### Shared

| ID | Label | State |
| --- | --- | --- |
| `shared.leads` | Leads | Live (step B3). Shown in Marketing > Call Center and in Sales. |
| `shared.customers` | Customers | Live (step B6a). Shown in Marketing, Sales, Production and Finance. Opened by the customer permission. |
| `shared.inbox` | Inbox | CS |

Customers and Inbox each exist once, with one route. They appear inside the
navigation of the centers that use them, pointing at the same shared page.

## Historical unbuilt Checkpoint B navigation plan

Built rows below remain the as-built record. Unbuilt rows are historical planning
and are not authorized; the canonical target and a reviewed compatibility map
must replace them before implementation. See [checkpoint-b.md](checkpoint-b.md).

All approved by the owner on 2026-10-05.

| Item | Change | Step |
| --- | --- | --- |
| `settings.lists` — Lead & Sales Lists | A new Settings page holding the five managed lists. Opened by its own permission, held by Owner and Leadership. Leadership sees Settings with this page only. | B2 — **built** |
| `shared.leads` — Leads | One shared Leads page with one address, shown in **both** the Marketing Center and the Sales Center to anyone holding the lead permission. There is no separate Marketing Leads and Sales Leads. Marketing > Call Center > Leads points at it. | B3 — **built** |
| `shared.customers` — Customers | Becomes live. Lists Accounts. | B6a — **built** |
| `shared.projects` — Projects | One shared Projects page with one address, shown in the Sales Center and the Production Center as appropriate. There are no separate Sales Projects and Production Projects. | B8 |

Shared pages are opened by their own permission, not by access to the center whose
menu happens to show them.

A shared page is drawn inside the menu of a center the person may open. When they
may open more than one, it is the center they were last in. That choice only
decides which menu surrounds the page; it never grants access to a center.

Until Central Inbox is built, Call Center opens on Leads.

The Leads page has up to five views, chosen with buttons at the top (steps B4
and B7): Assigned to me, Follow-ups, Unassigned, All leads and Unrouted. A person is shown only the
views they may use, and the page opens on the one that suits their job. See
"Routing and assignment, as built in step B4" in
[checkpoint-b.md](checkpoint-b.md). The views are not separate pages and add
nothing to the menus. B7 presents the manual callback status as **Deferred
Follow-up** and the action as **Schedule follow-up** (decision 55). Follow-ups
is still the existing manual queue; no multichannel nurture or Contact Center
workflow is introduced.
- The first live section that has sub-sections brings the third row (sub-sections)
  into the shell, as already described under "Shell layout".
- Every other item on this page remains an as-built Coming Soon entry until an
  approved compatibility migration changes it. The canonical target consolidates
  Sales and Production work into their respective Workspaces as described above.

## Home

After signing in, a person lands on Home, which lists the centers they may open in
their current location, or says "No access assigned yet". Home is not part of any
center, so the second row is not shown there.

## What we took from the CRM reference screenshots

Layout and organization only:

- A dark top bar carrying brand, current center and user controls, with a
  horizontal center menu beneath it and a third row for sub-sections.
- Inbox and Customers repeated inside each center's menu, which confirms treating
  them as shared.
- Unbuilt items shown muted with a small "Coming Soon" label.
- Page rhythm: title and subtitle at left, period selectors or one main action at
  right, a row of summary cards, then a table or board.
- Count badges on sub-section tabs.

Not taken: competitor names and logos, colors, staff and customer names, any
figures, warranty and product claims, financing terms, lead categories, call
cadence rules, and the three-tier Good / Better / Best proposal layout.

The reference has no location selector, no company overview, no settings screens
and no Color Studio. Those are designed from the Backoffice design system.
