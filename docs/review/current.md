# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `checkpoint-slice-29-audit-log`

**State:** while Jacob was away, production readiness waits for him (now
slice 30), and slice 29, Settings > Audit Log, is built straight through
(decision 191). **It waits for Jacob's review. Not merged.**

## What to review

Plan and "as built": [../slice-29-audit-log-plan.md](../slice-29-audit-log-plan.md).

- **29a:** Settings > Audit Log, for the Owner only: every recorded change,
  newest first, 50 to a page, with who, when, the record (linked), the
  Location, why, and "What changed".
- **29b:** filters by area, Location, person and days; one record's
  history; links from a staff member's page and from each Location; and
  Settings > Integrations saying to add a Location first when there is none.

## In the browser (Jacob)

1. Settings > Audit Log. Open "What changed" on a line.
2. Choose Area: Projects, then press "History" on a line.
3. Settings > Locations: "Changes" on a Location. Users and Access: open a
   person, then "Changes they made".

## Decisions

- **Slice 29:** none open; each question took the recommendation.
- **Slice 30, Production Readiness**
  ([../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md)):
  seven questions wait for Jacob, including the production address.

## Trying QuickBooks on staging (Jacob)

Staging has the Intuit app's keys but no Locations yet: add one in
Settings > Locations, then press Connect on Settings > Integrations and
choose Intuit's sandbox company.

## Noted separately

On a phone, the header's Location and account menus are wider than the
screen on every page, so the page can scroll sideways. Not part of slice
29; offered as its own small fix.
