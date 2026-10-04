# Navigation Map

Status: **Proposed** (structure from the owner's brief; IDs, states and permissions
proposed 2026-10-04).

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
| Route | Present only when the item is `live`. |

### The three states a person can see

| Situation | What the person sees | What the server does |
| --- | --- | --- |
| Authorized and built | A normal link | Serves the page |
| Authorized and not built | Muted item labelled "Coming soon", not clickable | No route exists |
| Not authorized | Nothing | Refuses the request ("forbidden") |

"Not built yet" and "not authorized" are never shown the same way. Hiding an item
is a courtesy; the server-side check is what protects the page.

Until a feature is built it borrows its center's access permission. When it is
built it receives its own specific permission, recorded in
[permission-model.md](permission-model.md).

## Shell layout

```
+----------------------------------------------------------------------------+
| BANANA | Sales Center v                   Location v      (AB) Name v     |  Ink header
+----------------------------------------------------------------------------+
| Performance   Appointments   ESTIMATES   Proposals   Customers   Inbox     |  Center navigation
|                              =========                                     |  Yellow marker
+----------------------------------------------------------------------------+
| Sub-navigation, only where an item has children                            |
+----------------------------------------------------------------------------+
| Cream workspace with Card surfaces                                         |
+----------------------------------------------------------------------------+
```

- Header: brand at left, then the center switcher, then on the right the location
  switcher and the user menu.
- Center navigation: horizontal. The current item is heavier with a small Yellow marker.
- Sub-navigation: a third row, shown only for items with children.
- On small screens the center navigation collapses into a slide-out menu.

## Centers

State at the end of Checkpoint A. `CS` = Coming soon.

### Company Overview — `company` — scope: company

| ID | Label | State |
| --- | --- | --- |
| `company.home` | Company Overview (landing) | Live |
| `company.performance` | Company Performance | CS |
| `company.location_comparison` | Location Comparison | CS |
| `company.reports` | Company Reports | CS |

### Marketing Center — `marketing` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `marketing.home` | Marketing Center (landing) | Live |
| `marketing.performance` | Performance Board | CS |
| `marketing.call_center` | Call Center | CS |
| `marketing.call_center.inbox` | Central Inbox | CS |
| `marketing.call_center.leads` | Leads | CS |
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
| `sales.home` | Sales Center (landing) | Live |
| `sales.performance` | Performance Board | CS |
| `sales.appointments` | Appointments | CS |
| `sales.estimates` | Estimates | CS |
| `sales.proposals` | Proposals | CS |
| `sales.relationships` | Relationships | CS |
| `sales.commissions` | Commissions | CS |
| `sales.reports` | Reports | CS |

### Production Center — `production` — scope: location

| ID | Label | State |
| --- | --- | --- |
| `production.home` | Production Center (landing) | Live |
| `production.performance` | Performance Board | CS |
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
| `finance.home` | Finance Center (landing) | Live |
| `finance.performance` | Performance Board | CS |
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
| `color_studio.home` | Color Studio (landing) | Live |
| `color_studio.projects` | Color Projects | CS |
| `color_studio.sample_requests` | Sample Requests | CS |
| `color_studio.estimate_requests` | Estimate Requests | CS |
| `color_studio.catalog` | Color Catalog | CS |

### Settings — `settings` — scope: company

| ID | Label | State |
| --- | --- | --- |
| `settings.home` | Settings (landing) | Live |
| `settings.company` | Company | CS |
| `settings.locations` | Locations | Live |
| `settings.users` | Users and Access | Live |
| `settings.service_areas` | Service Areas | CS |
| `settings.services` | Services and Products | CS |
| `settings.pricing` | Pricing Settings | CS |
| `settings.templates` | Templates | CS |
| `settings.integrations` | Integrations | CS |
| `settings.audit_log` | Audit Log | CS |

### Shared

| ID | Label | State |
| --- | --- | --- |
| `shared.customers` | Customers | CS |
| `shared.inbox` | Inbox | CS |

Customers and Inbox each exist once, with one route. They appear inside the
navigation of the centers that use them, pointing at the same shared page.

## Landing pages

Each center has a landing page that is real and authorized. It shows the center
name, what is available now, and what is coming. It shows no metrics, charts or
counts, because there is no real data to show yet. A center's Performance Board is
a separate, later feature.

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
