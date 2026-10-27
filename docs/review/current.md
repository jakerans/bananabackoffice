# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `main`

**State:** slice 28, the QuickBooks Connection and Net Profit, is merged
(decision 190) and on staging. Slice 29, Production Readiness, is
**proposed and waits for Jacob's approval.**

## What to review

The plan: [../slice-29-production-readiness-plan.md](../slice-29-production-readiness-plan.md).

## Decisions needed

From the plan, each with a recommendation:

1. Is production next, before Settings > Audit Log or Reports?
2. The production address.
3. Which roles must use two-factor sign-in.
4. Owner impersonation on production.
5. The production server.
6. How often backups run and how long they are kept.
7. QuickBooks' live keys.

## Trying QuickBooks on staging (Jacob)

The Intuit app "Banana Backoffice" is created with staging's Redirect URI,
and its development keys are in staging's settings: Settings >
Integrations says it reads Intuit's test companies. Staging has no
Locations yet, so there is nothing to connect: add one in Settings >
Locations (a made-up or real Location name; never customer details), then
press Connect beside it on Settings > Integrations and choose Intuit's
sandbox company.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
