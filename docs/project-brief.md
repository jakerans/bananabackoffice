# Project Brief

Status: **Approved** (taken from the owner's brief, 2026-10-04).

## What Backoffice is

The internal operating system for Banana Painting: one company, several operating
locations, used by staff. It is not software sold to other companies.

## Stack

Laravel 13, Livewire 4 (single-file components), Blade, Tailwind 4, Flux UI (free
edition), Laravel Fortify authentication, Pest, Laravel Boost, Herd for local
development. SQLite locally; MySQL 8.4 on staging and production.

Not allowed without owner approval: React, Inertia, Supabase, microservices, a
separate frontend, an admin-panel framework, paid UI components or services, new
packages, major dependency upgrades.

## Business rules that never bend

### One company, many locations

- Banana Painting is one company. Locations are operating workspaces inside it, not
  separate copies of the CRM.
- Staff may belong to one location or several.
- Leadership, call center and finance operate centrally. They are functions, not
  locations, and are never modelled as fake locations.

### What a person can do is separate from where they can do it

- "What" is a permission (for example: edit leads, view job costs).
- "Where" is one location, several locations, or explicitly granted company-wide access.
- Working across locations never implies finance, payroll or admin permissions.
- The location selector lists only locations the person is authorized for.
- "All Locations" requires explicit authorization.
- No URL, record ID, Livewire request, form value, session value or selector change
  may bypass location authorization. It is enforced on the server.
- A missing location never silently means "all locations".

### Staff and homeowners are separate

Staff sign-in and access are kept completely separate from the future homeowner
portal.

### Shared records, not shared visibility

- The long-term core is Customer, Property, Project. A customer may have several
  properties and projects. A project belongs to a location once assigned.
- Customers and Inbox are shared concepts. There is one Customer record, never a
  "Sales customer" and a "Finance customer".
- Shared does not mean everyone can see it.
- A project may start from ordinary lead intake or from Color Studio. A color
  consultation is never mandatory.
- There is no single giant `project_status` field covering sales, color, production,
  payment and scheduling.
- Internal notes, margins, commissions and staff-only information never become
  customer-visible automatically.

### Estimating (future — do not build yet)

- There is no Good / Better / Best model. No `basic_price`, `better_price`,
  `best_price`. No fixed count of three. No maximum of twelve.
- An estimate holds any number of named, ordered options, each with its own scope
  and pricing.
- The interface must cope with many options without laying out twelve price cards
  side by side.
- Whether options are alternatives, add-ons, or both is decided before estimating
  is built.
- An accepted proposal is preserved as a versioned snapshot.

### Coming-soon features

- The shell shows where the system is going without building it early.
- No database tables, fake workflows, fake buttons or invented analytics for
  features that are only in the navigation.
- "Not built yet" and "not authorized" are different states and are shown differently.

## How we work

- Small, reviewable changes. Stop for owner approval at each step.
- Explain important decisions in plain English.
- Fictitious data only in development and tests.
- No secrets in the repository.
- Customer uploads will use private storage.
- No paid services, no external AI APIs, no deployment to Forge until approved.
- No destructive database commands and no overwriting existing work without approval.
- Never claim a test, build or check passed unless it was actually run.
- Preserve Laravel Boost-generated guidance.
