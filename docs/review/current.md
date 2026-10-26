# Current Review

How this file is used: [../collaboration.md](../collaboration.md), "Review handoff".
No secrets or customer information belong here.

**Updated:** 2026-10-26 · **Branch:** `main`

**State:** slice 27 is complete and merged (decision 188); Leadership
Scorecards and TV Mode are live. **Slice 28, the QuickBooks Connection and
Net Profit, is proposed and waits for Jacob's approval.** Nothing of it is
built.

## The proposal

Plan: [../slice-28-quickbooks-plan.md](../slice-28-quickbooks-plan.md).

- **28a:** the Owner connects Backoffice to QuickBooks Online, approving it
  on QuickBooks' own page, and matches each Location.
- **28b:** profit and loss read from QuickBooks each night for this month
  and last, shown on Finance > Performance.
- **28c:** Net Profit % on the Leadership Scorecard, the Executive Board
  and its TV view.

## Decisions needed

The plan's eight questions, each with a recommendation. "Follow your
recommendations" is a complete answer. Two need Jacob's own knowledge:

- **Question 2:** is Banana's QuickBooks one company file with QuickBooks
  Locations turned on, or one company file per Location?
- **Question 4:** staging on a sandbox company with made-up numbers until
  production (recommended), or Banana's real books on staging now.

Connecting also needs Jacob to register a free app with Intuit and put its
two keys into Forge himself; the plan lists the steps. Keys never go in a
chat.

## Optional, for Jacob

Staging's upload limit is 20 MB per upload. Raising it to 100 in Forge
(BackOffice server, Runtime, max upload size) lets several large photos be
added at once.
