# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-07 · **Branch:** `docs/architecture-roadmap-reconciliation`
**State:** slice 1 compatibility plan awaiting implementation approval. The
five-slice order and slice 1 planning are approved. Jacob has authorized merging
this documentation branch and its automatic staging deployment under decision 61.
Navigation implementation and B8 remain unauthorized.

## Objective

Approve or revise the source-grounded compatibility plan for the first reconciled
slice: five-Center shell alignment. The full ID, route, access and regression map
is in [navigation-map.md](../navigation-map.md), "Proposed slice 1 implementation
map."

## Recommendation

Approve slice 1 as a navigation-only implementation:

- expose exactly Marketing, Sales, Production, Finance and Company as Centers;
- keep Color Studio reachable as a separately grouped external destination with
  its current routes, gate and location scope;
- nest Settings under Company while preserving `settings`, every `settings.*` ID,
  `/settings` landing behavior and every working `/settings/*` URL;
- preserve all existing root URLs, shared Leads/Customers routes, active-context
  behavior and server-side permissions;
- present Finance → People & Access only to someone who already has
  `settings.users.manage`; do not grant it to the Finance role;
- keep every unbuilt target as muted navigation with no new feature behavior.

Implementation should touch only the navigation registry/shell, one small nested
landing action, related shell views/routes, and focused regression tests. It must
not touch permission grants, policies, domain actions, the database or dependencies.

## Decisions needed now

1. Approve, revise or reject the slice 1 compatibility plan.
2. If approved, explicitly authorize **slice 1 implementation only**. Completed,
   verified approved work may then merge and deploy to staging under the standing
   authorization; code checks and owner browser review still apply.

## Constraints carried forward

- Shared Customers and related records remain permission-filtered canonical
  records. Related-record access never exposes unrelated work or sensitive GP,
  commission, payout or job-cost fields.
- Responsibility and assignment never grant access; separate grants do not union
  into authority neither grant provides alone.
- Old B8–B12 ordering, Project-from-Qualified and B9-only Open/Won/Lost assumptions
  remain historical pending revalidation. No exact Project or Pending rule was
  invented.
- The approved five-slice order does not authorize planning or implementation of
  slices 2–5.
- No production action or feature implementation is part of this review. Only
  the approved documentation may merge and deploy to staging now.

## Documentation release verification

- Scope check: the branch changes documentation only; no application, schema,
  configuration or navigation implementation changes.
- Full local `composer test`: passed, including Pint, PHPStan and **1,470 tests /
  6,404 assertions**. `git diff --check` passed.
- Both GitHub jobs (SQLite and MySQL 8.4) must pass before merge. Remote commit
  and active staging release verification are reported in the final release
  handoff; merging this plan does not authorize its implementation.
