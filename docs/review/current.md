# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `main`

**State:** slice 29, Settings > Audit Log, is merged (decision 192). Slice
30, Production Readiness, is proposed and waits for Jacob's answers
(decision 191). Nothing is being built.

## What to review

Plan: [../slice-30-production-readiness-plan.md](../slice-30-production-readiness-plan.md).

## Decisions

Seven questions, each with a recommendation in the plan:

1. Is production next? (Recommended: yes; the alternative is Reports.)
2. The production address, a part of Banana's own domain.
3. Who must use two-factor sign-in. (Recommended: Owner, Leadership and
   Finance.)
4. Owner impersonation on production. (Recommended: keep it as decision 46.)
5. The server. (Recommended: a second Forge server, the size of staging,
   created and paid for by Jacob.)
6. Backups. (Recommended: the database hourly and files nightly, each kept
   30 days.)
7. QuickBooks live keys, through Intuit's questionnaire.

## Trying QuickBooks on staging (Jacob)

Staging has the Intuit app's keys but no Locations yet: add one in
Settings > Locations, then press Connect on Settings > Integrations and
choose Intuit's sandbox company.

## Noted separately

On a phone, the header's Location and account menus are wider than the
screen on every page, so the page can scroll sideways. Offered as its own
small fix.
