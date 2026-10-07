# Navigation Map

Status: **As-built navigation through B7 is approved and live.** The target
five-Center structure below is **LOCKED product direction**; its route, ID and
access migration is a **proposed implementation** awaiting owner approval.

## Canonical target and compatibility migration

The target parent Centers are **Marketing, Sales, Production, Finance and
Company**. Customers and other canonical records remain shared pages presented
inside the Centers that use them. Color Studio is an external/customer surface,
not a parent Center. Settings belongs under Company. This describes the target;
the current shell still uses the registry documented below.

| Current surface | Target direction | Compatibility rule before coding |
| --- | --- | --- |
| Company Overview and standalone Settings | Company Center, including Settings | Preserve working routes and permissions until a reviewed ID/route migration map says whether each is retained, aliased or redirected. |
| Marketing Call Center with Central Inbox beneath it | Contact Center calling workspace and a separate Inbox section | Do not collapse calling queues and omnichannel conversations into one module. |
| Sales Estimates and Proposals sections | One Sales Workspace | Estimate math, offer options, presentation and agreement remain distinct domain concepts inside the workspace. |
| Production Projects and separate stage pages | One Production Workspace | Stage filters become workspace views; do not create parallel record types or split ownership. |
| Color Studio Center | External Color Studio surface | Keep current route and `color_studio.access` behavior until its reviewed compatibility migration; target placement alone grants no access. |
| Settings Users and Access | Finance → People & Access in the target navigation | Placement does not give the Finance role staff-administration permission or widen `settings.users.manage`. |

The shared Customers page remains canonical across operational Centers. Marketing
Contact Center is distinct from Marketing Inbox. No target item in this section
is live merely because its product direction is locked.

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
- Settings opens for anyone who may manage locations, manage users, or manage the
  Lead & Sales Lists. Its unbuilt pages borrow the first two permissions only, so
  someone who holds just the lists permission sees that one page and no other
  Settings item, built or not.

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
| `company.performance` | Company Performance | CS, primary |
| `company.location_comparison` | Location Comparison | CS |
| `company.reports` | Company Reports | CS |

### Marketing Center — `marketing` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `marketing.home` | Shortcut: opens Performance Board | — |
| `marketing.performance` | Performance Board | CS, primary |
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
| `production.performance` | Performance Board | CS, primary |
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
| `finance.performance` | Performance Board | CS, primary |
| `finance.cash` | Cash Management | CS |
| `finance.cash.receivables` | Receivables | CS |
| `finance.cash.payments` | Payments | CS |
| `finance.cash.payouts` | Payouts | CS |
| `finance.job_costing` | Job Costing | CS |
| `finance.payroll` | Payroll | CS |
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
| `settings.pricing` | Pricing Settings | CS |
| `settings.templates` | Templates | CS |
| `settings.integrations` | Integrations | CS |
| `settings.audit_log` | Audit Log | CS |

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
