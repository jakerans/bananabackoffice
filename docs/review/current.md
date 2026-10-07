# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `slice-1-five-center-navigation`
**State:** slice 1 implementation is built and under verification. It is not
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

No database, permission enum, role grant, policy, domain action, dependency or
working URL changed.

## Tests and checks

- Focused navigation/controller/browser-response tests: **125 tests / 358
  assertions**, passed.
- Related Customers, Settings and location-switching tests: **235 tests / 942
  assertions**, passed.
- `vendor/bin/pint --dirty --format agent`: passed.
- `git diff --check`: passed.
- Full `composer test`, asset build, exact-SHA GitHub SQLite/MySQL checks and owner
  browser review are still pending and will be recorded before merge review.

## Review boundary

- Confirm the shell presents five Centers and a separate External group without
  widening authorization or location scope.
- Confirm `/settings`, shared Leads/Customers and Users & Access preserve their
  route and active-context behavior.
- Confirm unbuilt target items remain non-interactive and no feature behavior was
  added.
- Do not merge until the final local gate, both GitHub jobs and browser review are
  recorded here. Do not begin B8 or slices 2–5.

## Known limitations and decisions still needed

This slice intentionally builds no Contact Center, Inbox, Workspace, calendar,
warranty, scorecard or report behavior. No further product or architecture
decision is needed for this implementation. The next slice requires a separate
owner-approved plan after this slice is reviewed.
