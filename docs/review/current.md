# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `checkpoint-slice-1-five-center-navigation`
**State:** slice 1 implementation and both review corrections are built, independently
reviewed and browser-verified. Exact-SHA CI passed, and the completed approved work
is cleared for integration under the standing staging authorization. B8 and slices
2–5 remain unauthorized.

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
- Focused final regression run: **56 tests / 192 assertions**, including shared
  Leads, Customers, Users & Access, location-to-All and All-to-location switches,
  legacy Settings context, route parameters/query, and a remembered destination
  that becomes unauthorized in the new location.
- `npm run build`: passed. The optional Fontaine optimization warning is unchanged
  and non-failing.
- `git diff --check`: passed after the implementation and handoff updates.
- Both GitHub jobs passed on final implementation SHA
  `b1e26412988f562385e4eadd5cab8a910184f38f`: Checks and tests (SQLite), and
  Migrations and tests (MySQL 8.4), in run `37696146601`.
- Fresh local Chrome review passed: five Centers and separate External Color Studio;
  nested Settings and permission-gated Finance People & Access; muted unbuilt
  navigation; Owner, Sales Consultant, role and location scope; dropdown Escape;
  and the approved shared-context routes.
- Targeted browser re-review confirmed Marketing `/leads` and `/customers`, Company
  `/settings/users`, and nearby Sales Workspace `/leads` preserve both URL and active
  Center from Four Corners to All Locations and back where applicable.

## Integration boundary

- Confirm the shell presents five Centers and a separate External group without
  widening authorization or location scope.
- Confirm `/settings`, shared Leads/Customers and Users & Access preserve their
  route and active-context behavior.
- Confirm unbuilt target items remain non-interactive and no feature behavior was
  added.
- Merge only this verified branch into `main` and allow its normal automatic staging
  deployment. Do not begin B8 or slices 2–5, deploy to production, or widen scope.

## Known limitations and decisions still needed

This slice intentionally builds no Contact Center, Inbox, Workspace, calendar,
warranty, scorecard or report behavior. No further product or architecture
decision is needed for this implementation. The next slice requires a separate
owner-approved plan. The unauthorized remembered-context fallback is covered by
automated tests only: the available Sales Consultant fixture has one location and
therefore no rendered location selector, and there is no dedicated Finance browser
fixture. No merge-blocking issue remains for slice 1.
