# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-slice-1-five-center-navigation`
**State:** slice 1 implementation and the independent-review correction are built.
Final exact-SHA CI and local browser review remain before merge review. It is not
merged. B8 and slices 2–5 remain unauthorized.

## Objective

Review the bounded navigation-only implementation approved by Jacob: exactly five
Centers, Settings under Company, and Color Studio as a separately grouped external
destination, while retaining all working URLs, stable IDs, gates, location scope
and active-context behavior.

## What changed

- The Center registry now exposes Marketing, Sales, Production, Finance and
  Company only. Color Studio remains available through the full destination
  collection and appears in a visually separate External group.
- Company contains the approved target navigation, including direct Locations and
  nested Settings. `/settings` uses a small permission-aware landing action and
  retains the existing Locations, Users and Access, then Lead & Sales Lists order.
- Marketing, Sales, Production and Finance use the approved labels, hierarchy and
  muted unbuilt target items. Shared Leads, Customers and Users & Access keep their
  working routes and context-sensitive active navigation.
- Finance → People & Access uses the existing `settings.users.manage` gate. The
  Finance role receives no new permission and cannot see or open it by default.
- A legacy `backoffice.center=settings` session value maps to Company. Existing
  five-Center and Color Studio context values remain valid.
- Location switching now selects from every authorized destination that owns the
  previous route. It preserves the remembered shared Leads, Customers or Users &
  Access context only when that destination is still authorized in the new
  location, otherwise it uses the first authorized context or Home.

No database, permission enum, role grant, policy, domain action, dependency or
working URL changed.

## Tests and checks

- Final full `composer test`: passed Pint, PHPStan and **1,499 tests / 6,486
  assertions**.
- Focused post-review regression run: **85 tests / 324 assertions**, including
  shared Leads, Customers, Users & Access, legacy Settings context, and a
  remembered destination that becomes unauthorized in the new location.
- `npm run build`: passed. The optional Fontaine optimization warning is unchanged
  and non-failing.
- `git diff --check`: passed after the implementation and handoff updates.
- Both GitHub jobs passed on reviewed implementation SHA
  `45d67d880a77519eb7e92a53822def892f395761` before the location-switch fix. Both
  jobs must pass again on the final SHA.
- Local Chrome review is blocked because the browser-control transport is closed;
  the existing Chrome session exposes no DevTools endpoint. No HTTP-only check is
  being substituted for this required visual review.

## Review boundary

- Confirm the shell presents five Centers and a separate External group without
  widening authorization or location scope.
- Confirm `/settings`, shared Leads/Customers and Users & Access preserve their
  route and active-context behavior.
- Confirm unbuilt target items remain non-interactive and no feature behavior was
  added.
- In Chrome, review Home grouping; Company and nested Settings; Finance People &
  Access; Marketing/Sales Leads; each Center's Customers context; role and location
  scoping; legacy and current location-switch context; and muted unbuilt items.
- Do not merge until the final local gate, both GitHub jobs and browser review are
  recorded here. Do not begin B8 or slices 2–5.

## Known limitations and decisions still needed

This slice intentionally builds no Contact Center, Inbox, Workspace, calendar,
warranty, scorecard or report behavior. No further product or architecture
decision is needed for this implementation. The only current blocker is access to
the requested local Chrome control for the required browser review. The next slice
requires a separate owner-approved plan after this slice is reviewed.
