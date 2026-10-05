# Navigation Map

Status: **Approved** (structure from the owner's brief; IDs, states and permissions
approved 2026-10-04).

## How navigation works

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

There are exactly three levels, and no level in between:

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

## Centers

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
| `settings.locations` | Locations | Live, primary |
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
| `shared.customers` | Customers | CS |
| `shared.inbox` | Inbox | CS |

Customers and Inbox each exist once, with one route. They appear inside the
navigation of the centers that use them, pointing at the same shared page.

## Planned for Checkpoint B (not built yet)

Nothing below is live. Each change is made in the step that builds the page. See
[checkpoint-b.md](checkpoint-b.md).

All approved by the owner on 2026-10-05.

| Item | Change | Step |
| --- | --- | --- |
| `settings.lists` — Lead & Sales Lists | A new Settings page holding the five managed lists. Opened by its own permission, held by Owner and Leadership. Leadership sees Settings with this page only. | B2 — **built** |
| `shared.leads` — Leads | One shared Leads page with one address, shown in **both** the Marketing Center and the Sales Center to anyone holding the lead permission. There is no separate Marketing Leads and Sales Leads. Marketing > Call Center > Leads points at it. | B3 â **built** |
| `shared.customers` — Customers | Becomes live. Lists Accounts. | B6 |
| `shared.projects` — Projects | One shared Projects page with one address, shown in the Sales Center and the Production Center as appropriate. There are no separate Sales Projects and Production Projects. | B8 |

Shared pages are opened by their own permission, not by access to the center whose
menu happens to show them.

A shared page is drawn inside the menu of a center the person may open. When they
may open more than one, it is the center they were last in. That choice only
decides which menu surrounds the page; it never grants access to a center.

Until Central Inbox is built, Call Center opens on Leads.

- The first live section that has sub-sections brings the third row (sub-sections)
  into the shell, as already described under "Shell layout".
- Every other item on this page keeps its current state through Checkpoint B.
  Estimates, Proposals, Relationships, the Production project stages, the Finance
  sections and the Call Center tools all stay Coming Soon. They will be different
  views of the same Project record, not separate records.

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
